<?php

namespace App\Http\Controllers;

use App\Models\AttendanceDevice;
use App\Models\AttendanceImport;
use App\Models\AttendancePunch;
use App\Models\Employee;
use App\Services\AttendanceClock;
use App\Services\AttendanceRules;
use App\Support\Csv;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Attendance > Punch log (HR and admin): every raw punch from every source as it was saved, newest first. The
 * machine (synced or uploaded), HR.my files and clock-ins on the app. Punches link to people the same way the
 * attendance board does: machine number, HR.my ID, or the employee id for app clock-ins (AttendanceBoard).
 */
class PunchLogController extends Controller
{
    public const SOURCES = ['machine' => 'Machine', 'hrmy' => 'HR.my', AttendanceClock::SOURCE => 'App'];

    private const PER_PAGE = 100;

    private const DEFAULT_DAYS = 7;

    /** @var array{machine: array<string, int>, hrmy: array<string, int>} device id => employee id */
    private array $links;

    /** @var array<int, string> attendance_imports.id => file name ("Machine sync" for a sync) */
    private array $imports;

    /** @var array<int, string> attendance_devices.id => name */
    private array $devices;

    public function index(Request $request): Response
    {
        $f = $this->filters($request);
        $page = $this->query($f)->paginate(self::PER_PAGE)->withQueryString();
        $people = $this->people($page->getCollection());

        return Inertia::render('attendance/punches', [
            'filters' => $f,
            'punches' => $page->through(fn (AttendancePunch $p) => $this->row($p, $people)),
            // Per source within the dates (other filters left out), for the source buttons.
            'counts' => AttendancePunch::query()->whereBetween('work_date', [$f['from'], $f['to']])
                ->selectRaw('source, count(*) as n')->groupBy('source')->pluck('n', 'source'),
            'sources' => self::SOURCES,
            'today' => CarbonImmutable::today()->toDateString(),
        ]);
    }

    public function csv(Request $request): StreamedResponse
    {
        $f = $this->filters($request);
        $query = $this->query($f);
        $rows = function () use ($query) {
            // cursor() keeps the newest-first order and streams, so a year of punches doesn't sit in memory.
            foreach ($query->cursor()->chunk(1000) as $chunk) {
                $people = $this->people($chunk);
                foreach ($chunk as $p) {
                    $r = $this->row($p, $people);
                    yield [$r['date'], $r['time'], $r['work_date'], $r['employee']['code'] ?? '', $r['employee']['name'] ?? '',
                        $r['employee'] ? 'Yes' : 'No', $r['source_label'], $r['device_user_id'], $r['device_name'], $r['method'],
                        $r['place'], $r['ip'], $r['location']['lat'] ?? '', $r['location']['lng'] ?? '', $r['location']['accuracy'] ?? '', $r['device'] ?? '', $r['via']];
                }
            }
        };

        return Csv::download("punch-log-{$f['from']}-to-{$f['to']}.csv",
            ['Date', 'Time', 'Counts on', 'Employee code', 'Employee', 'Linked', 'Source', 'Device ID', 'Name on device', 'Type',
                'Counted as', 'Address', 'Latitude', 'Longitude', 'Accuracy (m)', 'Device', 'Brought in by'],
            $rows());
    }

    /** @return array{from: string, to: string, source: ?string, q: ?string, place: ?string, linked: ?string} */
    private function filters(Request $request): array
    {
        $f = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'source' => ['nullable', 'in:'.implode(',', array_keys(self::SOURCES))],
            'q' => ['nullable', 'string', 'max:100'],
            'place' => ['nullable', 'in:office,remote'],
            'linked' => ['nullable', 'in:yes,no'],
        ]);
        $from = $f['from'] ?? CarbonImmutable::today()->subDays(self::DEFAULT_DAYS - 1)->toDateString();

        return ['from' => $from, 'to' => max($from, $f['to'] ?? CarbonImmutable::today()->toDateString()),
            'source' => $f['source'] ?? null, 'q' => $f['q'] ?? null, 'place' => $f['place'] ?? null, 'linked' => $f['linked'] ?? null];
    }

    private function query(array $f): Builder
    {
        $links = $this->links();
        $q = AttendancePunch::query()
            ->whereBetween('work_date', [$f['from'], $f['to']])
            ->when($f['source'], fn ($q, $s) => $q->where('source', $s))
            ->orderByDesc('punched_at')->orderByDesc('id');

        if ($f['q']) {
            // A person's punches through each of their links, plus the name as typed on the device (unlinked punches).
            $ids = Employee::withTrashed()->search($f['q'])->get(['id', 'attendance_machine_id', 'hr_portal_id']);
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $f['q']).'%';
            $q->where(fn ($w) => $w
                ->where(fn ($m) => $m->where('source', 'machine')->whereIn('device_user_id', $ids->pluck('attendance_machine_id')->filter()->map(fn ($v) => (string) $v)->all()))
                ->orWhere(fn ($h) => $h->where('source', 'hrmy')->whereIn('device_user_id', $ids->pluck('hr_portal_id')->filter()->map(fn ($v) => (string) $v)->all()))
                ->orWhere(fn ($a) => $a->where('source', AttendanceClock::SOURCE)->whereIn('device_user_id', $ids->pluck('id')->map(fn ($v) => (string) $v)->all()))
                ->orWhereRaw('lower(device_name) like ?', [mb_strtolower($like)])   // Postgres LIKE is case-sensitive
                ->orWhere('device_user_id', $f['q']));
        }
        if ($f['place'] === 'office') {
            $q->where(fn ($w) => $w->where('place', 'office')->orWhere(fn ($n) => $n->whereNull('place')->whereIn('source', $this->sourcesFor('office'))));
        } elseif ($f['place'] === 'remote') {
            $q->where(fn ($w) => $w->where('place', 'remote')->orWhere(fn ($n) => $n->whereNull('place')->whereIn('source', $this->sourcesFor('remote'))));
        }
        if ($f['linked']) {
            $linked = fn ($w) => $w
                ->where(fn ($m) => $m->where('source', 'machine')->whereIn('device_user_id', array_map('strval', array_keys($links['machine']))))
                ->orWhere(fn ($h) => $h->where('source', 'hrmy')->whereIn('device_user_id', array_map('strval', array_keys($links['hrmy']))))
                ->orWhere('source', AttendanceClock::SOURCE);
            $f['linked'] === 'yes' ? $q->where($linked) : $q->whereNot($linked);
        }

        return $q;
    }

    /** Sources whose punches have no place of their own and fall back to $place (AttendanceRules::PLACES). */
    private function sourcesFor(string $place): array
    {
        return array_keys(array_filter(AttendanceRules::PLACES, fn ($p) => $p === $place));
    }

    /** @return array{machine: array<string, int>, hrmy: array<string, int>} */
    private function links(): array
    {
        return $this->links ??= [
            'machine' => Employee::query()->whereNotNull('attendance_machine_id')->pluck('id', 'attendance_machine_id')->mapWithKeys(fn ($id, $k) => [(string) $k => $id])->all(),
            'hrmy' => Employee::query()->whereNotNull('hr_portal_id')->pluck('id', 'hr_portal_id')->mapWithKeys(fn ($id, $k) => [(string) $k => $id])->all(),
        ];
    }

    /** Employee for each punch in $punches, keyed by employee id. */
    private function people(iterable $punches): Collection
    {
        $ids = [];
        foreach ($punches as $p) {
            $id = $this->employeeId($p);
            $id && $ids[$id] = true;
        }

        return Employee::withTrashed()->with('department:id,name')->whereIn('id', array_keys($ids))
            ->get(['id', 'employee_code', 'full_name', 'department_id', 'deleted_at'])->keyBy('id');
    }

    private function employeeId(AttendancePunch $p): ?int
    {
        return $p->source === AttendanceClock::SOURCE ? (int) $p->device_user_id : ($this->links()[$p->source][$p->device_user_id] ?? null);
    }

    private function row(AttendancePunch $p, Collection $people): array
    {
        $e = ($id = $this->employeeId($p)) ? $people->get($id) : null;

        return [
            'id' => $p->id,
            'at' => $p->punched_at->toIso8601String(),
            'date' => $p->punched_at->toDateString(),
            'time' => $p->punched_at->format('H:i:s'),
            'work_date' => $p->work_date->toDateString(),
            'employee' => $e ? ['id' => $e->id, 'code' => $e->employee_code, 'name' => $e->full_name, 'department' => $e->department?->name, 'deleted' => $e->trashed()] : null,
            'source' => $p->source,
            'source_label' => self::SOURCES[$p->source] ?? $p->source,
            'device_user_id' => $p->device_user_id,
            'device_name' => $p->device_name,
            'method' => $p->method,
            'place' => $p->place ?? AttendanceRules::PLACES[$p->source] ?? null,
            'ip' => $p->ip,
            'location' => $p->latitude !== null ? ['lat' => $p->latitude, 'lng' => $p->longitude, 'accuracy' => $p->accuracy] : null,
            'device' => $p->attendance_device_id ? (($this->devices ??= AttendanceDevice::query()->pluck('name', 'id')->all())[$p->attendance_device_id] ?? null) : null,
            // Where the punch came into the app: a machine sync, an uploaded file, or a press on My page.
            'via' => $p->source === AttendanceClock::SOURCE ? 'Clock-in on My page'
                : (($this->imports ??= AttendanceImport::query()->pluck('original_name', 'id')->all())[$p->attendance_import_id] ?? 'Unknown'),
        ];
    }
}
