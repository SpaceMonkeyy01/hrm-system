<?php

namespace App\Services;

use App\Models\AttendancePunch;
use App\Models\Employee;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Clock in / out on the My page. Each press is a punch with source "app", device_user_id = the employee's id
 * (so it needs no linking) and method In or Out, paired into sessions like HR.my punches (AttendanceRules).
 *
 *   in / out   the next press is Out when the person has a clock-in open, else In. The page says which button
 *              it showed; a press that no longer fits (pressed twice, two tabs) is refused, not flipped
 *   open       the latest app punch is an In from the last max_shift_hours. An older one stays open on its day
 *              (Missing punch, HR or an "ask HR" request fixes it) and the next press starts a new day
 *   work date  an In counts on today; an Out on its In's day, so a night shift ending at 06:00 stays one day
 *   place      office when the request came from config hrm.clock.office_ips, else remote
 *
 * Punches aren't edited or deleted from here: a wrong time is fixed the usual way, with an HR adjustment.
 */
class AttendanceClock
{
    public const SOURCE = 'app';

    public static function enabled(): bool
    {
        return (bool) config('hrm.clock.enabled');
    }

    /** "office" for an office IP, else "remote". */
    public static function placeFor(?string $ip): string
    {
        $office = config('hrm.clock.office_ips', []);

        return $ip && $office && IpUtils::checkIp($ip, $office) ? 'office' : 'remote';
    }

    /** The open clock-in, or null. */
    public static function open(Employee $employee, CarbonInterface $now): ?AttendancePunch
    {
        $last = AttendancePunch::query()->where('source', self::SOURCE)->where('device_user_id', (string) $employee->id)
            ->where('punched_at', '<=', $now)->latest('punched_at')->first();

        return $last && $last->method === 'In' && $last->punched_at->gt($now->copy()->subHours((int) config('hrm.clock.max_shift_hours')))
            ? $last : null;
    }

    /**
     * What the My page shows: whether a clock-in is open and the presses on the day it belongs to.
     *
     * @return array{enabled: bool, open: ?array{at: string, time: string, place: ?string}, date: string,
     *     presses: list<array{at: string, time: string, method: string, place: ?string}>, place: string}
     *     at = ISO 8601, time = "H:i" in the app's timezone
     */
    public static function state(Employee $employee, CarbonInterface $now, ?string $ip): array
    {
        $open = self::open($employee, $now);
        $date = $open?->work_date?->toDateString() ?? $now->toDateString();

        return [
            'enabled' => self::enabled(),
            'open' => $open ? ['at' => $open->punched_at->toIso8601String(), 'time' => $open->punched_at->format('H:i'), 'place' => $open->place] : null,
            'date' => $date,
            'presses' => AttendancePunch::query()->where('source', self::SOURCE)->where('device_user_id', (string) $employee->id)
                ->whereDate('work_date', $date)->orderBy('punched_at')->get(['punched_at', 'method', 'place'])
                ->map(fn (AttendancePunch $p) => ['at' => $p->punched_at->toIso8601String(), 'time' => $p->punched_at->format('H:i'),
                    'method' => $p->method, 'place' => $p->place])
                ->all(),
            // Where a press right now would count, so the page can say "You're clocking in from the office".
            'place' => self::placeFor($ip),
        ];
    }

    /**
     * Records a press. $action is the button the page showed: "in" or "out".
     *
     * @param  array{latitude?: ?float, longitude?: ?float, accuracy?: ?int}  $location
     */
    public static function press(Employee $employee, string $action, ?string $ip, array $location, CarbonInterface $now): AttendancePunch
    {
        if (! self::enabled()) {
            throw ValidationException::withMessages(['clock' => 'Clocking in on the app is switched off. Use the attendance machine or ask HR.']);
        }
        $today = $now->toDateString();
        if ($employee->exit_date && $employee->exit_date->toDateString() < $today) {
            throw ValidationException::withMessages(['clock' => 'Your record shows you have left, so you can\'t clock in. Talk to HR if that\'s wrong.']);
        }
        $last = AttendancePunch::query()->where('source', self::SOURCE)->where('device_user_id', (string) $employee->id)
            ->latest('punched_at')->first();
        if ($last && abs($now->getTimestamp() - $last->punched_at->getTimestamp()) < (int) config('hrm.clock.double_tap_seconds')) {
            throw ValidationException::withMessages(['clock' => 'Already saved a moment ago.']);
        }
        $open = self::open($employee, $now);
        if ($action === 'in' && $open) {
            throw ValidationException::withMessages(['clock' => 'You\'re already clocked in (since '.$open->punched_at->format('H:i').').']);
        }
        if ($action === 'out' && ! $open) {
            throw ValidationException::withMessages(['clock' => 'You\'re not clocked in, so there is nothing to clock out of.']);
        }

        return AttendancePunch::create([
            'source' => self::SOURCE,
            'device_user_id' => (string) $employee->id,
            'device_name' => $employee->full_name,
            'punched_at' => CarbonImmutable::instance($now)->startOfSecond(),
            'work_date' => $open ? $open->work_date->toDateString() : $today,
            'method' => $open ? 'Out' : 'In',
            'place' => self::placeFor($ip),
            'ip' => $ip,
            'latitude' => $location['latitude'] ?? null,
            'longitude' => $location['longitude'] ?? null,
            'accuracy' => isset($location['accuracy']) ? (int) round($location['accuracy']) : null,
        ]);
    }
}
