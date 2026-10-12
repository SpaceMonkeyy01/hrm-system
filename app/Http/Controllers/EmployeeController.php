<?php

namespace App\Http\Controllers;

use App\Models\AssetAssignment;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmploymentHistory;
use App\Models\Onboarding;
use App\Models\Team;
use App\Models\WorkSchedule;
use App\Services\EmployeeRecords;
use App\Services\HrRules;
use App\Services\ScheduleAssigner;
use App\Services\StatusBoard;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeController extends Controller
{
    public function index(Request $request): Response
    {
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'company' => ['nullable', 'integer'],
            'department' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['current', 'active', 'offboarding', 'exited', 'all'])],
            'review' => ['nullable', 'boolean'],
            'unassigned' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(['name', 'code', 'start_date', 'department'])],
        ]);
        $status = $f['status'] ?? 'current';

        $q = Employee::query()
            ->with(['company:id,name', 'department:id,name', 'team:id,name'])
            ->search($f['q'] ?? null)
            ->when($status === 'current', fn ($q) => $q->whereIn('status', ['active', 'offboarding']))
            ->when(in_array($status, ['active', 'offboarding', 'exited'], true), fn ($q) => $q->where('status', $status))
            ->when($f['company'] ?? null, fn ($q, $v) => $q->where('company_id', $v))
            ->when($f['department'] ?? null, fn ($q, $v) => $q->where('department_id', $v))
            ->when($f['review'] ?? false, fn ($q) => $q->where('needs_review', true))
            ->when($f['unassigned'] ?? false, fn ($q) => $q->whereNull('company_id'));

        match ($f['sort'] ?? 'name') {
            'code' => $q->orderBy('employee_code'),
            'start_date' => $q->orderByDesc('start_date'),
            'department' => $q->orderBy(Department::select('name')->whereColumn('departments.id', 'employees.department_id'))->orderBy('full_name'),
            default => $q->orderBy('full_name'),
        };

        $page = $q->paginate(50)->withQueryString()->through(fn (Employee $e) => [
            'id' => $e->id, 'code' => $e->employee_code, 'name' => $e->full_name, 'photo' => $e->photoUrl(),
            'company' => $e->company?->name, 'department' => $e->department?->name, 'team' => $e->team?->name,
            'role_title' => $e->role_title, 'employment_type' => $e->employment_type,
            'start_date' => $e->start_date?->toDateString(), 'status' => $e->status, 'needs_review' => $e->needs_review,
        ]);

        return Inertia::render('employees/index', [
            'employees' => $page,
            'filters' => ['status' => $status] + $f,
            'companies' => $this->companyOptions(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('employees/form', ['employee' => null] + $this->formOptions());
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, null);
        $company = Company::findOrFail($data['company_id']);
        $data['employee_code'] = ($data['employee_code'] ?? null) ?: $company->nextEmployeeCode();

        $schedule = $this->validatedSchedule($request);
        $employee = DB::transaction(function () use ($data, $schedule, $request) {
            $employee = Employee::create($data + ['status' => 'active']);
            if ($schedule) {
                ScheduleAssigner::assign([$employee], $schedule['schedule'], $schedule['starts_on'] ?? ($employee->start_date?->toDateString() ?? CarbonImmutable::today()->toDateString()), $request->user()->id);
            }
            // New hires start onboarding immediately, on probation until the 90-day evaluation.
            Onboarding::create([
                'employee_id' => $employee->id,
                'probation_end_date' => HrRules::probationEnd($employee->start_date),
                'probation_result' => 'Pending',
            ]);

            return $employee;
        });

        return redirect()->route('employees.show', $employee)->with('success', "Added {$employee->full_name} as {$employee->employee_code}.");
    }

    public function show(Employee $employee): Response
    {
        $employee->load(['company:id,name', 'department:id,name', 'team:id,name', 'manager:id,employee_code,full_name',
            'onboarding', 'probationPlan:id,employee_id,updated_at', 'offboardings', 'employmentHistory.author:id,name']);
        $private = request()->user()->can('edit-hr');
        $board = new StatusBoard([$employee->id]);
        $o = $employee->onboarding;

        $lists = [];
        foreach (['onboarding', 'document', 'offboarding'] as $list) {
            $lists[$list] = ['columns' => $board->columns($list), 'values' => $board->values($employee->id, $list)];
        }

        return Inertia::render('employees/show', [
            'employee' => [
                ...$employee->only(['id', 'system_id', 'employee_code', 'full_name', 'first_name', 'last_name', 'email', 'phone',
                    'gender', 'personal_email', 'city', 'work_mode', 'work_location',
                    'role_title', 'employment_type', 'status', 'hr_portal_id', 'attendance_machine', 'attendance_machine_id',
                    'drive_folder_url', 'notes', 'needs_review', 'review_note', 'source_ref']),
                // Viewer logins get null for private fields, not just a hidden section.
                ...collect(Employee::PRIVATE_FIELDS)->mapWithKeys(fn ($f) => [$f => $private ? $this->propValue($employee, $f) : null])->all(),
                'private_hidden' => ! $private,
                'photo' => $employee->photoUrl(),
                'start_date' => $employee->start_date?->toDateString(),
                'confirmation_date' => $employee->confirmation_date?->toDateString(),
                'exit_date' => $employee->exit_date?->toDateString(),
                'company' => $employee->company?->name,
                'department' => $employee->department?->name,
                'team' => $employee->team?->name,
                'manager' => $employee->manager ? ['id' => $employee->manager->id, 'name' => $employee->manager->full_name, 'code' => $employee->manager->employee_code] : null,
            ],
            'onboarding' => $o ? [
                'cohort' => $o->cohort,
                'probation_end_date' => $o->probation_end_date?->toDateString(),
                'probation_result' => $o->probation_result,
                'notes' => $o->notes,
                'plan_url' => $o->plan_url,
                'overdue' => HrRules::isProbationOverdue($o->probation_end_date?->toDateString(), $o->probation_result),
                'orientation' => $board->orientation($employee->id),
                'documents' => $board->documents($employee->id),
                'status' => $board->onboarding($employee->id, $o->probation_result),
            ] : null,
            'documentStatus' => $board->documents($employee->id),
            'completeness' => $private ? $employee->completeness() : null,
            // Files: HR and admin get the list; viewers only learn how many there are.
            'files' => $private ? $employee->files()->with('uploader:id,name')->get()->map(fn ($f) => [
                'id' => $f->id, 'checklist_item_id' => $f->checklist_item_id, 'label' => $f->label,
                'name' => $f->original_name, 'mime' => $f->mime, 'size' => $f->size,
                'uploaded_by' => $f->uploader?->name, 'uploaded_at' => $f->created_at?->toIso8601String(),
            ])->values() : null,
            'fileCount' => $employee->files()->count(),
            'uploads' => ['extensions' => config('hrm.uploads.extensions'), 'max_kb' => config('hrm.uploads.max_kb'), 'enabled' => (bool) config('hrm.uploads.enabled')],
            'attendance' => EmployeeRecords::attendanceMonth($employee, request()->input('month')),
            // A closure: skipped when only the month changes (partial reload of 'attendance').
            'attendanceYear' => fn () => EmployeeRecords::attendanceYear($employee),
            'leave' => EmployeeRecords::leaveYear($employee),
            'assets' => AssetAssignment::rowsFor($employee->id),
            // Letters carry CNIC numbers: HR and admin only.
            'letters' => $private ? LetterController::letterRows($employee->id) : null,
            'employmentHistory' => $employee->employmentHistory->map(fn (EmploymentHistory $h) => [
                'id' => $h->id, 'effective_date' => $h->effective_date->toDateString(), 'change_type' => $h->change_type,
                'changes' => $h->changes ?? [], 'note' => $h->note, 'by' => $h->author?->name,
            ])->values(),
            'lists' => $lists,
            'optionSets' => StatusBoard::optionSets(),
            'plan' => $employee->probationPlan ? ['updated_at' => $employee->probationPlan->updated_at?->toIso8601String()] : null,
            'offboardings' => $employee->offboardings->map(fn ($x) => [
                'id' => $x->id, 'type' => $x->type, 'status' => $x->status,
                'last_working_day' => $x->last_working_day?->toDateString(),
            ]),
            'activity' => $employee->activity()->with('user:id,name')->limit(60)->get()->map(fn ($a) => [
                'id' => $a->id, 'when' => $a->created_at?->toIso8601String(), 'user' => $a->user?->name ?? 'System',
                'event' => $a->event, 'description' => $a->description, 'changes' => $a->changes,
            ]),
            'options' => [
                'probationResults' => config('hrm.probation_results'),
                'cohorts' => config('hrm.cohorts'),
                'historyTypes' => config('hrm.history_types'),
            ],
        ]);
    }

    public function edit(Employee $employee): Response
    {
        return Inertia::render('employees/form', [
            'employee' => [
                ...$employee->only(['id', 'employee_code', 'full_name', 'first_name', 'last_name', 'cnic', 'email', 'phone',
                    'company_id', 'department_id', 'team_id', 'role_title', 'manager_id', 'employment_type',
                    'hr_portal_id', 'attendance_machine', 'attendance_machine_id', 'drive_folder_url', 'notes',
                    'needs_review', 'review_note', ...self::PROFILE_FIELDS]),
                ...collect(self::PROFILE_DATES)->mapWithKeys(fn ($f) => [$f => $employee->{$f}?->toDateString()])->all(),
                'start_date' => $employee->start_date?->toDateString(),
                'work_schedule_id' => ScheduleAssigner::current([$employee->id], CarbonImmutable::today()->toDateString())[$employee->id] ?? null,
            ],
        ] + $this->formOptions($employee->id));
    }

    public function update(Request $request, Employee $employee): RedirectResponse
    {
        $data = $this->validated($request, $employee);
        $change = $request->validate([
            'change_effective_date' => ['nullable', 'date'],
            'change_type' => ['nullable', Rule::in(config('hrm.history_types'))],
            'change_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $schedule = $this->validatedSchedule($request);

        DB::transaction(function () use ($employee, $data, $change, $request, $schedule) {
            $jobBefore = $this->jobLabels($employee);
            if ($schedule) {
                ScheduleAssigner::assign([$employee], $schedule['schedule'], $schedule['starts_on'] ?? CarbonImmutable::today()->toDateString(), $request->user()->id);
            }
            $oldStart = $employee->start_date?->toDateString();
            $oldEnd = $oldStart ? HrRules::probationEnd(CarbonImmutable::parse($oldStart)) : null;

            // Code: keep it unless HR typed a new one. A person imported without a company
            // (UNASSIGNED-xxx) gets the company's next code as soon as HR sets the company.
            $typed = $data['employee_code'] ?? null;
            if (! $typed || $typed === $employee->employee_code) {
                unset($data['employee_code']);
                if (! empty($data['company_id']) && str_starts_with($employee->employee_code, 'UNASSIGNED-')) {
                    $data['employee_code'] = Company::find($data['company_id'])->nextEmployeeCode();
                }
            }
            $employee->update($data);
            $this->recordJobChange($employee, $jobBefore, $change, $request->user()?->id);

            // Keep the probation end date in step with the start date, unless HR set it by hand.
            $o = $employee->onboarding()->first();
            if ($o && $employee->start_date?->toDateString() !== $oldStart
                && ($o->probation_end_date === null || $o->probation_end_date->toDateString() === $oldEnd)) {
                $o->update(['probation_end_date' => HrRules::probationEnd($employee->start_date)]);
            }
        });

        return redirect()->route('employees.show', $employee)->with('success', 'Saved.');
    }

    public function destroy(Employee $employee): RedirectResponse
    {
        $employee->delete();   // soft delete: the record stays in the database and the activity log

        return redirect()->route('employees.index')->with('success', "Deleted {$employee->full_name}. The record is kept in the archive.");
    }

    // ------------------------------------------------------------------

    private function validated(Request $request, ?Employee $employee): array
    {
        $rules = [
            'company_id' => [$employee ? 'nullable' : 'required', 'integer', 'exists:companies,id'],
            'employee_code' => ['nullable', 'string', 'max:30', Rule::unique('employees', 'employee_code')->ignore($employee?->id)],
            'full_name' => ['required', 'string', 'max:150'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'cnic' => ['nullable', 'string', 'regex:/^\d{5}-?\d{7}-?\d$/'],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:40'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'team_id' => ['nullable', 'integer', 'exists:teams,id'],
            'role_title' => ['nullable', 'string', 'max:150'],
            'manager_id' => ['nullable', 'integer', Rule::exists('employees', 'id')->withoutTrashed(), Rule::notIn([$employee?->id])],
            'employment_type' => ['nullable', Rule::in(config('hrm.employment_types'))],
            'start_date' => ['nullable', 'date'],
            'hr_portal_id' => ['nullable', 'string', 'max:60'],
            'attendance_machine' => ['nullable', 'string', 'max:60'],
            'attendance_machine_id' => ['nullable', 'string', 'max:60'],
            'drive_folder_url' => ['nullable', 'url', 'max:500'],
            'notes' => ['nullable', 'string', 'max:5000'],
            // Master profile
            'father_name' => ['nullable', 'string', 'max:150'],
            'date_of_birth' => ['nullable', 'date', 'before:today', 'after:1930-01-01'],
            'gender' => ['nullable', Rule::in(config('hrm.genders'))],
            'marital_status' => ['nullable', Rule::in(config('hrm.marital_statuses'))],
            'blood_group' => ['nullable', Rule::in(config('hrm.blood_groups'))],
            'cnic_expiry' => ['nullable', 'date'],
            'personal_email' => ['nullable', 'email', 'max:150'],
            'alternate_phone' => ['nullable', 'string', 'max:40'],
            'current_address' => ['nullable', 'string', 'max:500'],
            'permanent_address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:80'],
            'emergency_contact_name' => ['nullable', 'string', 'max:150'],
            'emergency_contact_relation' => ['nullable', 'string', 'max:40'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:40'],
            'bank_name' => ['nullable', 'string', 'max:100'],
            'bank_account_title' => ['nullable', 'string', 'max:150'],
            'bank_account_number' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9 -]+$/'],
            'work_mode' => ['nullable', Rule::in(config('hrm.work_modes'))],
            'work_location' => ['nullable', 'string', 'max:100'],
            'confirmation_date' => ['nullable', 'date'],
        ];
        if ($employee) {
            $rules['needs_review'] = ['boolean'];
            $rules['review_note'] = ['nullable', 'string', 'max:1000'];
        }

        $validator = validator($request->all(), $rules, [
            'cnic.regex' => 'CNIC must be 13 digits, like 35202-1234567-1.',
            'manager_id.not_in' => 'An employee cannot be their own manager.',
            'bank_account_number.regex' => 'Use letters, digits, spaces and dashes only (account number or IBAN).',
            'date_of_birth.before' => 'Date of birth must be in the past.',
        ]);
        $validator->after(function (Validator $v) use ($request, $employee) {
            // A field left out of an update keeps its stored value, so check against what the record will hold.
            $companyId = $request->exists('company_id') ? ($request->integer('company_id') ?: null) : $employee?->company_id;
            $deptId = $request->exists('department_id') ? ($request->integer('department_id') ?: null) : $employee?->department_id;
            $teamId = $request->exists('team_id') ? ($request->integer('team_id') ?: null) : $employee?->team_id;

            $dept = $deptId ? Department::find($deptId) : null;
            if ($dept && $dept->company_id !== $companyId) {
                $v->errors()->add('department_id', 'This department belongs to another company.');
            }
            $team = $teamId ? Team::find($teamId) : null;
            if ($team && $team->department_id !== $deptId) {
                $v->errors()->add('team_id', 'This team belongs to another department.');
            }
        });

        $data = $validator->validate();
        if (isset($data['cnic'])) {   // store as 35202-1234567-1
            $digits = preg_replace('/\D/', '', $data['cnic']);
            $data['cnic'] = substr($digits, 0, 5).'-'.substr($digits, 5, 7).'-'.substr($digits, 12, 1);
        }
        if (isset($data['bank_account_number'])) {   // IBANs are stored upper case without spaces
            $data['bank_account_number'] = strtoupper(preg_replace('/\s+/', '', $data['bank_account_number']));
        }

        return $data;
    }

    private function companyOptions(): array
    {
        return Company::query()->orderBy('name')->with('departments:id,company_id,name')->get(['id', 'name'])
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name,
                'departments' => $c->departments->map(fn ($d) => ['id' => $d->id, 'name' => $d->name])->all()])->all();
    }

    private function formOptions(?int $exceptId = null): array
    {
        return [
            'companies' => Company::query()->orderBy('name')->with('departments.teams')->get()->map(fn ($c) => [
                'id' => $c->id, 'name' => $c->name, 'nextCode' => $c->nextEmployeeCode(),
                'departments' => $c->departments->map(fn ($d) => [
                    'id' => $d->id, 'name' => $d->name,
                    'teams' => $d->teams->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])->all(),
                ])->all(),
            ])->all(),
            'managers' => Employee::query()->whereIn('status', ['active', 'offboarding'])
                ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
                ->orderBy('full_name')->get(['id', 'employee_code', 'full_name'])
                ->map(fn ($e) => ['id' => $e->id, 'label' => "{$e->full_name} ({$e->employee_code})"])->all(),
            'employmentTypes' => config('hrm.employment_types'),
            'schedules' => WorkSchedule::query()->orderBy('name')->get()->map(fn (WorkSchedule $s) => ['id' => $s->id, 'label' => "{$s->name} ({$s->summary()})", 'is_default' => $s->is_default])->all(),
            'options' => [
                'genders' => config('hrm.genders'),
                'maritalStatuses' => config('hrm.marital_statuses'),
                'bloodGroups' => config('hrm.blood_groups'),
                'workModes' => config('hrm.work_modes'),
                'historyTypes' => config('hrm.history_types'),
            ],
        ];
    }

    /** @return array{schedule: WorkSchedule, starts_on: ?string}|null */
    private function validatedSchedule(Request $request): ?array
    {
        $data = $request->validate([
            'work_schedule_id' => ['nullable', 'integer', 'exists:work_schedules,id'],
            'schedule_starts_on' => ['nullable', 'date'],
        ]);

        return empty($data['work_schedule_id']) ? null : [
            'schedule' => WorkSchedule::findOrFail($data['work_schedule_id']),
            'starts_on' => isset($data['schedule_starts_on']) ? CarbonImmutable::parse($data['schedule_starts_on'])->toDateString() : null,
        ];
    }

    /** Master profile fields sent to the form as they are stored. */
    private const PROFILE_FIELDS = ['father_name', 'gender', 'marital_status', 'blood_group', 'personal_email', 'alternate_phone',
        'current_address', 'permanent_address', 'city', 'emergency_contact_name', 'emergency_contact_relation',
        'emergency_contact_phone', 'bank_name', 'bank_account_title', 'bank_account_number', 'work_mode', 'work_location'];

    /** Master profile dates, sent as Y-m-d. */
    private const PROFILE_DATES = ['date_of_birth', 'cnic_expiry', 'confirmation_date'];

    private function propValue(Employee $employee, string $field): mixed
    {
        $v = $employee->{$field};

        return $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v;
    }

    /** Job fields as the names HR reads (company, department, team and manager names rather than ids). */
    private function jobLabels(Employee $e): array
    {
        $out = [];
        foreach (Employee::JOB_FIELDS as $field => $label) {
            $out[$label] = match ($field) {
                'company_id' => $e->company_id ? Company::find($e->company_id)?->name : null,
                'department_id' => $e->department_id ? Department::find($e->department_id)?->name : null,
                'team_id' => $e->team_id ? Team::find($e->team_id)?->name : null,
                'manager_id' => $e->manager_id ? Employee::withTrashed()->find($e->manager_id)?->full_name : null,
                default => $e->{$field},
            };
        }

        return $out;
    }

    /**
     * Write one employment_history row when a job field changed. The type is what HR picked, or a guess from what changed
     * (company/department/team = Transfer first). The effective date defaults to today.
     */
    private function recordJobChange(Employee $employee, array $before, array $change, ?int $userId): void
    {
        $after = $this->jobLabels($employee);
        $diff = [];
        foreach ($after as $label => $value) {
            if (($before[$label] ?? null) !== $value) {
                $diff[$label] = [$before[$label] ?? null, $value];
            }
        }
        if (! $diff) {
            return;
        }
        $guess = match (true) {
            (bool) array_intersect_key($diff, array_flip(['Company', 'Department', 'Team'])) => 'Transfer',
            isset($diff['Role']) => 'Role change',
            isset($diff['Employment type']) => 'Employment type change',
            isset($diff['Manager']) => 'Manager change',
            default => 'Work mode change',
        };
        EmploymentHistory::create([
            'employee_id' => $employee->id,
            'effective_date' => $change['change_effective_date'] ?? CarbonImmutable::today()->toDateString(),
            'change_type' => $change['change_type'] ?? $guess,
            'changes' => $diff,
            'note' => $change['change_note'] ?? null,
            'created_by' => $userId,
        ]);
    }
}
