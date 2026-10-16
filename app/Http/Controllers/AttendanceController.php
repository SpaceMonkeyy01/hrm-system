<?php

namespace App\Http\Controllers;

use App\Models\AttendanceAdjustment;
use App\Models\AttendanceImport;
use App\Models\AttendanceRequest;
use App\Models\AttendancePunch;
use App\Models\Company;
use App\Models\Employee;
use App\Services\AttendanceBoard;
use App\Services\AttendanceLinks;
use App\Services\Import\AttendanceFileImporter;
use App\Services\Import\MachineSyncer;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Daily attendance, machine file uploads and HR's manual times. Linking numbers to people: AttendanceLinkController.
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
            'where' => ['nullable', 'in:office,remote,both'],
            'linked' => ['nullable', 'in:yes,no'],
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
                    'linked' => $board->isLinked($e)],
                'schedule' => $day['schedule_id'] ? $board->schedules->get($day['schedule_id'])?->name : null,
                'punchTimes' => array_map(fn ($t) => substr($t, 0, 5), $board->punchesOn($e->id, $date)),
                ...$day,
            ];
        }
        // Linked = the person's punches can reach the app (machine number, HR.my ID or the app's own clock-in).
        $linkedCount = count(array_filter($rows, fn ($r) => $r['employee']['linked']));
        $links = ['yes' => $linkedCount, 'no' => count($rows) - $linkedCount];
        if ($linked = $f['linked'] ?? null) {
            $rows = array_values(array_filter($rows, fn ($r) => $r['employee']['linked'] === ($linked === 'yes')));
        }
        $counts = array_count_values(array_column($rows, 'status'));
        $late = count(array_filter($rows, fn ($r) => $r['late'] > 0));
        $places = array_count_values(array_filter(array_column($rows, 'where')));
        if ($where = $f['where'] ?? null) {
            $rows = array_values(array_filter($rows, fn ($r) => $r['where'] === $where));
        }
        if ($status = $f['status'] ?? null) {
            $rows = array_values(array_filter($rows, fn ($r) => $status === 'Late' ? $r['late'] > 0 : $r['status'] === $status));
        }

        return Inertia::render('attendance/index', [
            'date' => $date,
            'today' => $now->toDateString(),
            'rows' => $rows,
            'counts' => $counts + ['Late' => $late],
            'links' => $links,
            'places' => ['office' => $places['office'] ?? 0, 'remote' => $places['remote'] ?? 0, 'both' => $places['both'] ?? 0],
            'filters' => $f,
            'companies' => Company::query()->orderBy('name')->with('departments:id,company_id,name')->get(['id', 'name'])
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'departments' => $c->departments->map->only('id', 'name')->all()]),
            'statuses' => config('hrm.attendance.statuses'),
            'hasSchedules' => $board->schedules->isNotEmpty(),
            'unlinked' => AttendanceLinks::unlinkedCount(),
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
        return Inertia::render('attendance/imports', [
            'imports' => AttendanceImport::query()->with('uploader:id,name')->latest('id')->limit(20)->get()->map(fn ($i) => [
                ...$i->only('id', 'source', 'original_name', 'punches_found', 'punches_added'),
                'date_from' => $i->date_from?->toDateString(), 'date_to' => $i->date_to?->toDateString(),
                'uploaded_by' => $i->uploader?->name, 'uploaded_at' => $i->created_at?->toIso8601String(),
            ]),
            // Linking is on Attendance > Links; this page says how many numbers are waiting.
            'unlinkedCount' => AttendanceLinks::unlinkedCount(),
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

    // ---------------------------------------------------------------- helpers


}
