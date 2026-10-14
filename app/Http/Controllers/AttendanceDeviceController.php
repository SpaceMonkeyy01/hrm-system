<?php

namespace App\Http\Controllers;

use App\Models\AttendanceDevice;
use App\Models\AttendancePunch;
use App\Services\Import\MachineSyncer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Attendance > Devices (HR and admin): the ZKTeco machines the app reads, each with its own connection and sync
 * settings, what the machine last said about itself, and its recent syncs. Test connection and Sync now only read
 * the machine (ZkClient is read only). The Comm Key never goes to the browser: a blank field keeps the saved one.
 */
class AttendanceDeviceController extends Controller
{
    public function index(): Response
    {
        $devices = AttendanceDevice::query()->orderByDesc('is_active')->orderBy('name')->get();
        $stats = AttendancePunch::query()->whereNotNull('attendance_device_id')
            ->selectRaw('attendance_device_id, count(*) as n, max(punched_at) as last')->groupBy('attendance_device_id')
            ->toBase()->get()->keyBy('attendance_device_id');

        return Inertia::render('attendance/devices', [
            'devices' => $devices->map(fn (AttendanceDevice $d) => [
                ...$d->only(['id', 'name', 'site', 'host', 'port', 'is_active', 'auto_sync', 'timeout_seconds', 'lookback_days', 'clock_warn_seconds',
                    'notes', 'model_name', 'serial', 'firmware', 'user_count', 'record_count']),
                'has_comm_key' => (string) $d->comm_key !== '',
                'info_read_at' => $d->info_read_at?->toIso8601String(),
                'punches' => (int) ($stats[$d->id]->n ?? 0),
                'last_punch_at' => isset($stats[$d->id]) ? substr((string) $stats[$d->id]->last, 0, 19) : null,
                'status' => MachineSyncer::deviceStatus($d, 10),
            ])->values(),
            'defaults' => (new AttendanceDevice)->only(['port', 'timeout_seconds', 'lookback_days', 'clock_warn_seconds']),
            // Automatic sync needs the cron link (CRON_TOKEN) opened by a cron service, or the scheduler.
            'cronReady' => (string) config('hrm.cron_token') !== '',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $device = AttendanceDevice::create($this->validated($request, null));

        return back()->with('success', "Added {$device->name}. Use Test connection to check the app can reach it.");
    }

    public function update(Request $request, AttendanceDevice $device): RedirectResponse
    {
        $data = $this->validated($request, $device);
        $device->update($data);

        return back()->with('success', "Saved {$device->name}.");
    }

    public function destroy(AttendanceDevice $device): RedirectResponse
    {
        $device->delete();

        return back()->with('success', "Removed {$device->name}. Its punches stay; they just no longer show which machine they came from.");
    }

    /** Reads what the machine says about itself and its clock. Nothing else is read or changed. */
    public function test(AttendanceDevice $device): RedirectResponse
    {
        set_time_limit(60);
        $r = MachineSyncer::test($device);
        if (! $r['ok']) {
            return back()->with('error', "Couldn't connect to {$device->name}: {$r['error']}");
        }
        $i = $r['info'];
        $offset = $r['clock_offset'];
        $clock = abs($offset) > $device->clock_warn_seconds ? ' Its clock is '.abs($offset).' seconds '.($offset > 0 ? 'fast' : 'slow').'.' : ' Its clock is right.';

        return back()->with('success', "Connected to {$device->name}".($i['name'] ? " ({$i['name']}" : ' (').($i['serial'] ? ", serial {$i['serial']}" : '')
            .'): '.number_format($i['users']).' users, '.number_format($i['records']).' punches stored.'.$clock);
    }

    public function sync(Request $request, AttendanceDevice $device, MachineSyncer $syncer): RedirectResponse
    {
        set_time_limit(180);
        $sync = $syncer->run($device, 'manual', $request->user()->id);

        return back()->with($sync->ok ? 'success' : 'error', "{$device->name}: ".MachineSyncer::summary($sync));
    }

    private function validated(Request $request, ?AttendanceDevice $device): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', 'unique:attendance_devices,name'.($device ? ','.$device->id : '')],
            'site' => ['nullable', 'string', 'max:120'],
            // An IP address or a host name; no scheme, path or port (the port has its own field).
            'host' => ['required', 'string', 'max:255', 'regex:/^(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)*[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$|^[0-9a-fA-F:]+$/'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'comm_key' => ['nullable', 'string', 'regex:/^\d{1,6}$/'],
            'clear_comm_key' => ['nullable', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'auto_sync' => ['required', 'boolean'],
            'timeout_seconds' => ['required', 'integer', 'between:5,120'],
            'lookback_days' => ['required', 'integer', 'between:1,60'],
            'clock_warn_seconds' => ['required', 'integer', 'between:10,3600'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [
            'name.unique' => 'Another device already has that name.',
            'host.regex' => 'Enter the IP address or host name only, like 203.0.113.10 (no http:// and no port).',
            'comm_key.regex' => 'The Comm Key is a number of up to 6 digits, as set on the machine (Comm. > Comm Key).',
        ]);
        // Blank keeps the saved key; "no key" has to be asked for.
        if ($request->boolean('clear_comm_key')) {
            $data['comm_key'] = null;
        } elseif (($data['comm_key'] ?? '') === '') {
            unset($data['comm_key']);
        }
        unset($data['clear_comm_key']);

        return $data;
    }
}
