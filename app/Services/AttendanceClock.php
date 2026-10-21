<?php

namespace App\Services;

use App\Models\AttendancePunch;
use App\Models\Employee;
use App\Models\OfficeNetwork;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
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
 *   work date  an In counts on today's work day (before 03:00 that is still yesterday, AttendanceRules::workDate);
 *              an Out on its In's day, so a night shift ending at 06:00 stays one day
 *   place      office when the request came from an office network (Attendance > App clock-ins, plus any in
 *              config hrm.clock.office_ips), else remote. Fixed when the punch is saved; adding a network later
 *              can re-mark earlier clock-ins from it (ClockInController)
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

    /**
     * Every office network: HR's (Attendance > App clock-ins) and any set on the server (HRM_OFFICE_IPS).
     *
     * @return list<array{id: ?int, name: string, address: string, server: bool}>
     */
    public static function officeNetworks(): array
    {
        $server = array_map(fn ($a) => ['id' => null, 'name' => 'Set on the server', 'address' => $a, 'server' => true],
            config('hrm.clock.office_ips', []));
        $hr = OfficeNetwork::query()->orderBy('name')->get(['id', 'name', 'address'])
            ->map(fn (OfficeNetwork $n) => ['id' => $n->id, 'name' => $n->name, 'address' => $n->address, 'server' => false])->all();

        return [...$hr, ...$server];
    }

    /**
     * The office network $ip belongs to, or null.
     *
     * @param  list<array{name: string, address: string}>|null  $networks  officeNetworks(), when the caller already has them
     */
    public static function networkFor(?string $ip, ?array $networks = null): ?array
    {
        if (! $ip) {
            return null;
        }
        foreach ($networks ?? self::officeNetworks() as $n) {
            if (IpUtils::checkIp($ip, $n['address'])) {
                return $n;
            }
        }

        return null;
    }

    /** "office" for an office network, else "remote". */
    public static function placeFor(?string $ip): string
    {
        return self::networkFor($ip) ? 'office' : 'remote';
    }

    /** "203.0.113.7" or "203.0.113.0/24" (IPv4 or IPv6); the error message otherwise. */
    public static function addressError(string $address): ?string
    {
        [$ip, $bits] = array_pad(explode('/', $address, 2), 2, null);
        $v4 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        $v6 = ! $v4 && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        if (! $v4 && ! $v6) {
            return 'Enter an internet address like 203.0.113.7, or a range like 203.0.113.0/24.';
        }
        if ($bits !== null && (! ctype_digit($bits) || (int) $bits > ($v4 ? 32 : 128) || (int) $bits < ($v4 ? 8 : 16))) {
            return 'The part after "/" must be a number from '.($v4 ? '8 to 32' : '16 to 128').'.';
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return 'That is an address inside the office (like 192.168.x.x). Use the public address the internet sees: open whatismyip.com on that network.';
        }

        return null;
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
     * "09:10" when today's machine check-in is still open (one tap, nothing after it closed it): the person forgot
     * to punch out when they left. Else null. Only today's machine session; HR's own version of the day wins.
     */
    public static function officeOpenSince(Employee $employee, CarbonInterface $now): ?string
    {
        if (! $employee->attendance_machine_id) {
            return null;
        }
        $today = AttendanceRules::workDate($now);
        $day = (new AttendanceBoard(collect([$employee]), $today, $today))->day($employee, $today, $now);
        foreach ($day['sessions'] ?? [] as $s) {
            if ($s['source'] === 'machine' && $s['in'] && ! $s['out'] && $s['in'] <= AttendanceRules::workClock($now)) {
                return $s['in'];
            }
        }

        return null;
    }

    /**
     * What the My page shows: whether a clock-in is open and the presses on the day it belongs to.
     *
     * @return array{enabled: bool, open: ?array{at: string, time: string, place: ?string}, date: string,
     *     presses: list<array{at: string, time: string, method: string, place: ?string, stated: bool}>, place: string,
     *     office_open: ?string}  at = ISO 8601, time = "H:i" in the app's timezone; office_open = officeOpenSince()
     *     when not clocked in on the app: clocking in away from the office first asks when they left
     */
    public static function state(Employee $employee, CarbonInterface $now, ?string $ip): array
    {
        $open = self::open($employee, $now);
        $date = $open?->work_date?->toDateString() ?? $now->toDateString();

        return [
            'enabled' => self::enabled(),
            'office_open' => $open ? null : self::officeOpenSince($employee, $now),
            'open' => $open ? ['at' => $open->punched_at->toIso8601String(), 'time' => $open->punched_at->format('H:i'), 'place' => $open->place] : null,
            'date' => $date,
            'presses' => AttendancePunch::query()->where('source', self::SOURCE)->where('device_user_id', (string) $employee->id)
                ->whereDate('work_date', $date)->orderBy('punched_at')->get(['punched_at', 'method', 'place', 'stated'])
                ->map(fn (AttendancePunch $p) => ['at' => $p->punched_at->toIso8601String(), 'time' => $p->punched_at->format('H:i'),
                    'method' => $p->method, 'place' => $p->place, 'stated' => $p->stated])
                ->all(),
            // Where a press right now would count, so the page can say "You're clocking in from the office".
            'place' => self::placeFor($ip),
        ];
    }

    /**
     * Records a press. $action is the button the page showed: "in" or "out".
     *
     * Clocking in away from the office while today's machine check-in is still open (officeOpenSince) needs
     * $leftOffice, the "H:i" they left: that is saved first as a stated Out at the office, closing the office session,
     * then the clock-in. $closedOffice is set to that time when it happened.
     *
     * @param  array{latitude?: ?float, longitude?: ?float, accuracy?: ?int}  $location
     */
    public static function press(Employee $employee, string $action, ?string $ip, array $location, CarbonInterface $now,
        ?string $leftOffice = null, ?string &$closedOffice = null): AttendancePunch
    {
        if (! self::enabled()) {
            throw ValidationException::withMessages(['clock' => 'Clocking in on the app is switched off. Use the attendance machine or ask HR.']);
        }
        $today = AttendanceRules::workDate($now);
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
        $place = self::placeFor($ip);
        $since = ! $open && $place === 'remote' ? self::officeOpenSince($employee, $now) : null;
        $leftAt = null;   // the time they left, on the work day's clock (01:30 after midnight = "25:30")
        if ($since) {
            if (! $leftOffice) {
                throw ValidationException::withMessages(['left_office' => "You checked in at the office at $since and didn't check out. Enter the time you left the office."]);
            }
            // After midnight the times run on past 24:00 (01:30 = "25:30"), so a late evening still compares.
            $leftAt = AttendanceRules::afterMidnight($leftOffice);
            if ($leftAt <= $since || $leftAt > AttendanceRules::workClock($now)) {
                throw ValidationException::withMessages(['left_office' => "Enter a time after $since and not later than now."]);
            }
        }

        return DB::transaction(function () use ($employee, $open, $place, $since, $leftOffice, $leftAt, $ip, $location, $now, $today, &$closedOffice) {
            if ($since) {
                AttendancePunch::create([
                    'source' => self::SOURCE, 'device_user_id' => (string) $employee->id, 'device_name' => $employee->full_name,
                    'punched_at' => CarbonImmutable::parse($today)->addMinutes(AttendanceRules::minutes($leftAt)), 'work_date' => $today,
                    'method' => 'Out', 'place' => 'office', 'stated' => true,
                ]);
                $closedOffice = $leftOffice;
            }

            return self::record($employee, $open, $place, $ip, $location, $now, $today);
        });
    }

    private static function record(Employee $employee, ?AttendancePunch $open, string $place, ?string $ip, array $location, CarbonInterface $now, string $today): AttendancePunch
    {
        return AttendancePunch::create([
            'source' => self::SOURCE,
            'device_user_id' => (string) $employee->id,
            'device_name' => $employee->full_name,
            'punched_at' => CarbonImmutable::instance($now)->startOfSecond(),
            'work_date' => $open ? $open->work_date->toDateString() : $today,
            'method' => $open ? 'Out' : 'In',
            'place' => $place,
            'ip' => $ip,
            'latitude' => $location['latitude'] ?? null,
            'longitude' => $location['longitude'] ?? null,
            'accuracy' => isset($location['accuracy']) ? (int) round($location['accuracy']) : null,
        ]);
    }
}
