<?php

namespace App\Http\Controllers;

use App\Models\AttendanceAdjustment;
use App\Models\AttendanceImport;
use App\Models\AttendanceRequest;
use App\Models\AttendancePunch;
use App\Models\Company;
use App\Models\Employee;
use App\Services\AttendanceBoard;
use App\Services\Import\AttendanceFileImporter;
use App\Services\Import\MachineSyncer;
use App\Services\Import\NameMatcher;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Daily attendance, machine file uploads, linking machine numbers to people, and HR's manual times.
 * The rules are in AttendanceRules; this only gathers and sends.
 */
class AttendanceController extends Controller
{
    private const EMPLOYEE_COLUMNS = ['id', 'employee_code', 'full_name', 'department_id', 'company_id', 'start_date', 'exit_date',
        'status', 'attendance_machine_id', 'hr_portal_id', 'photo_path'];

    public function index(Request $request): Response
    {
        $f = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'company' => ['nullable', 'integer'],
            'department' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'max:30'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $now = CarbonImmutable::now();
        $date = $f['date'] ?? $now->toDateString();

        // Everyone employed on that day: current staff, plus people who left after it.
        $employees = Employee::query()->with('department:id,name')->search($f['q'] ?? null)
            ->where(fn ($q) => $q->whereIn('status', ['active', 'offboarding'])->orWhere('exit_date', '>=', $date))
            ->when($f['company'] ?? null, fn ($q, $v) => $q->where('company_id', $v))
            ->when($f['department'] ?? null, fn ($q, $v) => $q->where('department_id', $v))
            ->orderBy('full_name')->get(self::EMPLOYEE_COLUMNS);
        $board = new AttendanceBoard($employees, $date, $date);

        $rows = [];
        foreach ($employees as $e) {
            $day = $board->day($e, $date, $now);
            if (! $day || $day['status'] === null) {
                continue;
            }
            $rows[] = [
                'employee' => ['id' => $e->id, 'code' => $e->employee_code, 'name' => $e->full_name, 'department' => $e->department?->name, 'photo' => $e->photoUrl(),
                    'linked' => (bool) ($e->attendance_machine_id || $e->hr_portal_id)],
                'schedule' => $day['schedule_id'] ? $board->schedules->get($day['schedule_id'])?->name : null,
                'punchTimes' => array_map(fn ($t) => substr($t, 0, 5), $board->punchesOn($e->id, $date)),
                ...$day,
            ];
        }
        $counts = array_count_values(array_column($rows, 'status'));
        $late = count(array_filter($rows, fn ($r) => $r['late'] > 0));
        if ($status = $f['status'] ?? null) {
            $rows = array_values(array_filter($rows, fn ($r) => $status === 'Late' ? $r['late'] > 0 : $r['status'] === $status));
        }

        return Inertia::render('attendance/index', [
            'date' => $date,
            'today' => $now->toDateString(),
            'rows' => $rows,
            'counts' => $counts + ['Late' => $late],
            'filters' => $f,
            'companies' => Company::query()->orderBy('name')->with('departments:id,company_id,name')->get(['id', 'name'])
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'departments' => $c->departments->map->only('id', 'name')->all()]),
            'statuses' => config('hrm.attendance.statuses'),
            'hasSchedules' => $board->schedules->isNotEmpty(),
            'unlinked' => $this->unlinkedCount(),
            'pendingRequests' => AttendanceRequest::where('status', 'pending')->whereHas('employee')->count(),
            'machine' => MachineSyncer::status(),
            'lastImport' => AttendanceImport::latest('id')->first(['date_to', 'created_at'])?->date_to?->toDateString(),
        ]);
    }

    /** HR sets the check-in and/or check-out for a day (forgotten punch, machine down). */
    public function adjust(Request $request, Employee $employee, string $date): RedirectResponse
    {
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && strtotime($date), 404);
        $time = ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'];
        $data = $request->validate([
            'check_in' => $time,
            'check_out' => [...$time],
            'note' => ['required', 'string', 'max:500'],
        ], ['check_in.regex' => 'Use a 24-hour time like 09:00.', 'check_out.regex' => 'Use a 24-hour time like 18:00.',
            'note.required' => 'Say why, e.g. "Forgot to punch out, confirmed by manager".']);
        if (empty($data['check_in']) && empty($data['check_out'])) {
            throw ValidationException::withMessages(['check_out' => 'Enter a check-in or a check-out time.']);
        }
        // A check-out before the check-in is the next morning (night shift); the same time can't be right.
        if (! empty($data['check_in']) && ! empty($data['check_out']) && $data['check_out'] === $data['check_in']) {
            throw ValidationException::withMessages(['check_out' => 'Check-out can\'t be the same time as check-in.']);
        }
        AttendanceAdjustment::updateOrCreate(
            ['employee_id' => $employee->id, 'date' => $date],
            ['check_in' => $data['check_in'] ?? null, 'check_out' => $data['check_out'] ?? null, 'note' => $data['note'], 'updated_by' => $request->user()->id],
        );

        return back()->with('success', "Saved {$employee->full_name}'s times for ".CarbonImmutable::parse($date)->format('j M').'.');
    }

    public function clearAdjustment(Employee $employee, string $date): RedirectResponse
    {
        AttendanceAdjustment::where('employee_id', $employee->id)->whereDate('date', $date)->first()?->delete();

        return back()->with('success', 'Removed the manual times. The punches count again.');
    }

    // ---------------------------------------------------------------- files and linking

    public function imports(Request $request): Response
    {
        $people = Employee::query()->whereIn('status', ['active', 'offboarding'])->orderBy('full_name')
            ->get(['id', 'employee_code', 'full_name', 'attendance_machine_id', 'hr_portal_id']);

        $unlinked = [];
        foreach (self::LINK_FIELDS as $source => $field) {
            $matcher = new NameMatcher;
            foreach ($people->whereNull($field) as $e) {
                $matcher->add($e->id, $e->full_name, $e->employee_code);
            }
            foreach ($this->unlinkedDevices($source) as $d) {
                $m = $d->device_name ? $matcher->match($d->device_name) : ['type' => 'none', 'ids' => []];
                $sure = in_array($m['type'], ['alias', 'exact', 'normalized', 'spelling'], true);
                $unlinked[] = [
                    'source' => $source, 'device_user_id' => $d->device_user_id, 'device_name' => $d->device_name, 'punches' => (int) $d->punches,
                    'last_seen' => substr($d->last_seen, 0, 10),
                    'suggestion' => count($m['ids']) === 1 ? $m['ids'][0] : null,
                    'confidence' => count($m['ids']) === 1 ? ($sure ? 'match' : 'check') : ($m['type'] === 'ambiguous' ? 'several' : 'none'),
                ];
            }
        }
        usort($unlinked, fn ($a, $b) => [$a['source'], $a['confidence'] !== 'match', $a['device_user_id']] <=> [$b['source'], $b['confidence'] !== 'match', $b['device_user_id']]);

        return Inertia::render('attendance/imports', [
            'imports' => AttendanceImport::query()->with('uploader:id,name')->latest('id')->limit(20)->get()->map(fn ($i) => [
                ...$i->only('id', 'source', 'original_name', 'punches_found', 'punches_added'),
                'date_from' => $i->date_from?->toDateString(), 'date_to' => $i->date_to?->toDateString(),
                'uploaded_by' => $i->uploader?->name, 'uploaded_at' => $i->created_at?->toIso8601String(),
            ]),
            'unlinked' => $unlinked,
            'people' => $people->map(fn ($e) => ['id' => $e->id, 'label' => "{$e->full_name} ({$e->employee_code})",
                'machine' => $e->attendance_machine_id, 'hrmy' => $e->hr_portal_id])->values(),
            'sources' => AttendanceFileImporter::SOURCES,
            'pending' => AttendanceUploadController::pending($request),
            'machine' => MachineSyncer::status(),
            'maxKb' => config('hrm.uploads.max_kb'),
        ]);
    }

    public function upload(Request $request, AttendanceFileImporter $importer): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'extensions:xls,xlsx', 'max:'.config('hrm.uploads.max_kb')],
        ], ['file.extensions' => 'Upload the .xls export as it comes from the machine or HR.my (or an .xlsx copy).']);
        $file = $request->file('file');
        $stored = $file->storeAs('attendance-imports', now()->format('Ymd-His').'-'.Str::random(8).'.'.strtolower($file->getClientOriginalExtension()), 'local');

        try {
            $result = $importer->import(Storage::disk('local')->path($stored), $file->getClientOriginalName(), $stored, $request->user()->id);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($stored);
            throw $e;
        }

        $msg = AttendanceFileImporter::SOURCES[$result['source']]." file: read {$result['found']} punches, {$result['added']} new";
        $msg .= $result['found'] > $result['added'] ? ' (the rest were already in).' : '.';
        if ($result['skipped']) {
            $msg .= ' '.count($result['skipped']).' rows skipped: '.implode('; ', array_slice($result['skipped'], 0, 3)).'.';
        }

        return back()->with('success', $msg);
    }

    /**
     * Link device ids to people: machine numbers go to employees.attendance_machine_id, HR.my ids to
     * employees.hr_portal_id (logged on the employee). HR.my ids look like employee codes but don't match ours,
     * so they are never linked by code.
     */
    public function link(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'source' => ['required', Rule::in(array_keys(self::LINK_FIELDS))],
            'links' => ['required', 'array', 'min:1', 'max:500'],
            'links.*.device_user_id' => ['required', 'string', 'max:40', 'distinct'],
            'links.*.employee_id' => ['required', 'integer', 'distinct', Rule::exists('employees', 'id')->withoutTrashed()],
        ], ['links.*.employee_id.distinct' => 'The same person is picked twice.']);
        $field = self::LINK_FIELDS[$data['source']];
        $what = $data['source'] === 'machine' ? 'machine number' : 'HR.my ID';

        $n = DB::transaction(function () use ($data, $field, $what) {
            $n = 0;
            foreach ($data['links'] as $i => $link) {
                $taken = Employee::where($field, $link['device_user_id'])->where('id', '!=', $link['employee_id'])->first();
                if ($taken) {
                    throw ValidationException::withMessages(["links.$i.employee_id" => ucfirst($what)." {$link['device_user_id']} already belongs to {$taken->full_name}."]);
                }
                $e = Employee::findOrFail($link['employee_id']);
                if ($e->{$field} && $e->{$field} !== $link['device_user_id']) {
                    throw ValidationException::withMessages(["links.$i.employee_id" => "{$e->full_name} already has $what {$e->{$field}}."]);
                }
                $e->update([$field => $link['device_user_id']]);
                $n++;
            }

            return $n;
        });

        return back()->with('success', $n === 1 ? "Linked 1 $what." : "Linked $n {$what}s.");
    }

    // ---------------------------------------------------------------- helpers

    /** Device source => the employee field that holds that device's id for the person. */
    private const LINK_FIELDS = ['machine' => 'attendance_machine_id', 'hrmy' => 'hr_portal_id'];

    private function unlinkedDevices(string $source)
    {
        $field = self::LINK_FIELDS[$source];
        $linked = Employee::query()->whereNotNull($field)->pluck($field)->map(fn ($v) => (string) $v)->all();

        return AttendancePunch::query()->where('source', $source)
            ->when($linked, fn ($q) => $q->whereNotIn('device_user_id', $linked))
            ->groupBy('device_user_id')
            ->selectRaw('device_user_id, max(device_name) as device_name, count(*) as punches, max(punched_at) as last_seen')
            ->toBase()->get();
    }

    private function unlinkedCount(): int
    {
        return $this->unlinkedDevices('machine')->count() + $this->unlinkedDevices('hrmy')->count();
    }
}
