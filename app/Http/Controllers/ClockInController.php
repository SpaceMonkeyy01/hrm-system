<?php

namespace App\Http\Controllers;

use App\Models\AttendancePunch;
use App\Models\Employee;
use App\Models\OfficeNetwork;
use App\Services\AttendanceClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Attendance > App clock-ins (HR and admin): the office networks that make an app clock-in count as office time,
 * every clock-in with the address and location it came from, and the addresses not on the list yet, so HR can
 * collect the office's networks from where people actually clock in.
 */
class ClockInController extends Controller
{
    /** Days shown when no dates are picked. */
    private const DEFAULT_DAYS = 7;

    public function index(Request $request): Response
    {
        $today = CarbonImmutable::today();
        $f = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'unlisted' => ['nullable', 'boolean'],
        ]);
        $from = $f['from'] ?? $today->subDays(self::DEFAULT_DAYS - 1)->toDateString();
        $to = max($from, $f['to'] ?? $today->toDateString());
        $networks = AttendanceClock::officeNetworks();
        $ip = $request->ip();

        $punches = AttendancePunch::query()->where('source', AttendanceClock::SOURCE)
            ->whereBetween('work_date', [$from, $to])->latest('punched_at')->limit(1000)
            ->get(['id', 'device_user_id', 'punched_at', 'work_date', 'method', 'place', 'ip', 'latitude', 'longitude', 'accuracy']);
        $people = Employee::query()->with('department:id,name')->whereIn('id', $punches->pluck('device_user_id')->unique()->map(fn ($id) => (int) $id))
            ->get(['id', 'employee_code', 'full_name', 'department_id'])->keyBy('id');

        $rows = [];
        $unlisted = [];
        foreach ($punches as $p) {
            $network = AttendanceClock::networkFor($p->ip, $networks);
            $person = $people->get((int) $p->device_user_id);
            if (! $network && $p->ip) {
                $u = &$unlisted[$p->ip];
                $u ??= ['address' => $p->ip, 'clockIns' => 0, 'people' => [], 'last' => $p->punched_at->toIso8601String()];
                $u['clockIns']++;
                $u['people'][$p->device_user_id] = $person?->full_name ?? '?';
                unset($u);
            }
            if (! empty($f['unlisted']) && $network) {
                continue;
            }
            $rows[] = [
                'id' => $p->id,
                'employee' => $person ? ['id' => $person->id, 'name' => $person->full_name, 'code' => $person->employee_code, 'department' => $person->department?->name] : null,
                'date' => $p->work_date->toDateString(),
                'at' => $p->punched_at->toIso8601String(),
                'time' => $p->punched_at->format('H:i'),
                'method' => $p->method,
                'place' => $p->place,
                'ip' => $p->ip,
                'network' => $network['name'] ?? null,
                'location' => $p->latitude !== null ? ['lat' => $p->latitude, 'lng' => $p->longitude, 'accuracy' => $p->accuracy] : null,
            ];
        }

        return Inertia::render('attendance/clock-ins', [
            'filters' => ['from' => $from, 'to' => $to, 'unlisted' => ! empty($f['unlisted'])],
            'networks' => $networks,
            'rows' => $rows,
            'truncated' => $punches->count() >= 1000,
            // Most people first: an address many colleagues clock in from is likely an office line.
            'unlisted' => collect($unlisted)->map(fn ($u) => [...$u, 'people' => array_values($u['people'])])
                ->sortByDesc(fn ($u) => count($u['people']) * 10000 + $u['clockIns'])->values(),
            'you' => ['ip' => $ip, 'network' => AttendanceClock::networkFor($ip, $networks)['name'] ?? null,
                'error' => $ip ? AttendanceClock::addressError($ip) : null],
            'enabled' => AttendanceClock::enabled(),
            'today' => $today->toDateString(),
        ]);
    }

    public function storeNetwork(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'address' => ['required', 'string', 'max:50', 'unique:office_networks,address'],
            'recount' => ['nullable', 'boolean'],
        ], ['address.unique' => 'That address is already on the list.']);
        $data['address'] = trim($data['address']);
        if ($error = AttendanceClock::addressError($data['address'])) {
            throw ValidationException::withMessages(['address' => $error]);
        }
        $network = OfficeNetwork::create(['name' => $data['name'], 'address' => $data['address'], 'created_by' => $request->user()->id]);

        $moved = 0;
        if ($request->boolean('recount')) {
            // Earlier clock-ins from this address were saved as remote. Punches are raw device data (not on the
            // activity log); adding the network above is what's logged.
            $ids = AttendancePunch::query()->where('source', AttendanceClock::SOURCE)->where('place', '!=', 'office')
                ->whereNotNull('ip')->get(['id', 'ip'])
                ->filter(fn ($p) => IpUtils::checkIp($p->ip, $network->address))->pluck('id');
            foreach ($ids->chunk(500) as $chunk) {
                $moved += AttendancePunch::query()->whereIn('id', $chunk)->update(['place' => 'office']);
            }
        }

        return back()->with('success', "Added {$network->name}. Clock-ins from it now count as office time"
            .($moved ? ", including $moved earlier ".($moved === 1 ? 'one' : 'ones').'.' : '.'));
    }

    public function destroyNetwork(OfficeNetwork $network): RedirectResponse
    {
        $network->delete();

        return back()->with('success', "Removed {$network->name}. New clock-ins from it count as remote; earlier ones stay as they were.");
    }
}
