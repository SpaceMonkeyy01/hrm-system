<?php

namespace App\Http\Controllers;

use App\Models\AssetAssignment;
use App\Models\AttendanceDevice;
use App\Models\AttendancePunch;
use App\Models\Category;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Models\EmploymentHistory;
use App\Models\Onboarding;
use App\Models\Team;
use App\Models\WorkSchedule;
use App\Services\EmployeeRecords;
use App\Services\HrRules;
use App\Services\EmployeeFilters;
use App\Services\JobHistory;
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
        $f = $request->validate(EmployeeFilters::rules() + [
            'sort' => ['nullable', Rule::in(['name', 'code', 'start_date', 'department'])],
        ]);
        $status = $f['status'] ?? 'current';

        $q = EmployeeFilters::apply(Employee::query()->with(['company:id,name', 'department:id,name', 'team:id,name', 'manager:id,full_name']), $f);

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
            'manager' => $e->manager ? ['id' => $e->manager->id, 'name' => $e->manager->full_name] : null,
        ]);

        return Inertia::render('employees/index', [
            'employees' => $page,
            'filters' => ['status' => $status] + $f,
            // "61 current, 3 left" under the title, and "Showing 6 of 61": everyone with this status, before the other filters.
            'counts' => ['current' => Employee::whereIn('status', ['active', 'offboarding'])->count(), 'left' => Employee::where('status', 'exited')->count()],
            'statusTotal' => EmployeeFilters::apply(Employee::query(), ['status' => $status])->count(),
            'companies' => $this->companyOptions(),
            'employmentTypes' => config('hrm.employment_types'),
            'workModes' => config('hrm.work_modes'),
            // Managers: anyone who manages someone, for the filter and the bulk change.
            'managers' => Employee::query()->whereIn('status', ['active', 'offboarding'])->orderBy('full_name')->get(['id', 'employee_code', 'full_name'])
                ->map(fn ($e) => ['value' => $e->id, 'label' => "{$e->full_name} ({$e->employee_code})"]),
            'managerIds' => Employee::query()->whereNotNull('manager_id')->distinct()->pluck('manager_id')
                ->merge(Employee::query()->whereNotNull('secondary_manager_id')->distinct()->pluck('secondary_manager_id'))->unique()->values(),
            'schedules' => WorkSchedule::query()->orderBy('name')->get(['id', 'name'])->map(fn ($s) => ['value' => $s->id, 'label' => $s->name]),
        ]);
    }

    public function create(Request $request): Response
    {
        // From Attendance > Links: a number that punches but has no employee. The form opens filled in.
        $prefill = $request->validate([
            'name' => ['nullable', 'string', 'max:150'],
            'machine' => ['nullable', 'string', 'max:60'],
            'hrmy' => ['nullable', 'string', 'max:60'],
            'from' => ['nullable', Rule::in(['links'])],
        ]);

        return Inertia::render('employees/form', ['employee' => null, 'prefill' => array_filter($prefill) ?: null] + $this->formOptions());
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, null);
        $categories = $this->pullCategories($data);
        $company = Company::findOrFail($data['company_id']);
        $data['employee_code'] = ($data['employee_code'] ?? null) ?: $company->nextEmployeeCode();
        // Off when HR adds someone who already works here (found on Attendance > Links): no probation, no 90-day plan.
        $startOnboarding = $request->boolean('start_onboarding', true);
        $fromLinks = $request->input('from') === 'links';
        if (! empty($data['attendance_machine_id']) && empty($data['attendance_device_id'])) {
            $data['attendance_device_id'] = AttendancePunch::deviceFor($data['attendance_machine_id']);   // ATD-### it punches on
        }

        $schedule = $this->validatedSchedule($request);
        $employee = DB::transaction(function () use ($data, $schedule, $request, $categories, $startOnboarding) {
            $employee = Employee::create($data + ['status' => 'active']);
            if ($categories !== null) {
                $employee->categories()->sync($categories);
            }
            if ($schedule) {
                ScheduleAssigner::assign([$employee], $schedule['schedule'], $schedule['starts_on'] ?? ($employee->start_date?->toDateString() ?? CarbonImmutable::today()->toDateString()), $request->user()->id);
            }
            // New hires start onboarding immediately, on probation until the 90-day evaluation.
            if ($startOnboarding) {
                Onboarding::create([
                    'employee_id' => $employee->id,
                    'probation_end_date' => HrRules::probationEnd($employee->start_date),
                    'probation_result' => 'Pending',
                ]);
            }

            return $employee;
        });

        $linked = implode(' and ', array_filter([
            $employee->attendance_machine_id ? "machine no. {$employee->attendance_machine_id}" : null,
            $employee->hr_portal_id ? "HR.my ID {$employee->hr_portal_id}" : null,
        ]));
        $message = "Added {$employee->full_name} as {$employee->employee_code}".($linked ? ", linked to $linked" : '').'.';

        return $fromLinks
            ? redirect('/attendance/links?tab=numbers')->with('success', $message)
            : redirect()->route('employees.show', $employee)->with('success', $message);
    }

    public function show(Employee $employee): Response
    {
        $employee->load(['company:id,name', 'department:id,name', 'team:id,name', 'manager:id,employee_code,full_name',
            'secondaryManager:id,employee_code,full_name', 'secondaryDepartment:id,name', 'categories:id,name', 'attendanceDevice:id,code,name',
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
                'secondary_manager' => $employee->secondaryManager ? ['id' => $employee->secondaryManager->id, 'name' => $employee->secondaryManager->full_name, 'code' => $employee->secondaryManager->employee_code] : null,
                'secondary_department' => $employee->secondaryDepartment?->name,
                'categories' => $employee->categories->pluck('name')->all(),
                'attendance_device' => $employee->attendanceDevice ? ['code' => $employee->attendanceDevice->code, 'name' => $employee->attendanceDevice->name] : null,
                // Values the master was cleaned from (old lists, Odoo). Read only.
                'legacy' => $employee->legacy ?: null,
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
                'closed_at' => $o->closed_at?->toDateString(),
                'closed_note' => $o->closed_note,
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
            // Warnings, appreciation and meeting notes: HR and admin only.
            'feedback' => $private ? FeedbackController::forEmployee($employee->id) : null,
            // Their login, if any (HR can create staff logins and send the link: StaffLoginsController). HR and admin only.
            'login' => $private ? (fn (?User $u) => $u ? [...$u->only('id', 'email', 'role', 'is_active'), 'last_login_at' => $u->last_login_at?->toIso8601String()] : null)(
                User::where('employee_id', $employee->id)->first(['id', 'email', 'role', 'is_active', 'last_login_at'])) : null,
            'employmentHistory' => $employee->employmentHistory->map(fn (EmploymentHistory $h) => [
                'id' => $h->id, 'effective_date' => $h->effective_date->toDateString(), 'change_type' => $h->change_type,
                'changes' => $h->changes ?? [], 'note' => $h->note, 'by' => $h->author?->name,
            ])->values(),
            'lists' => $lists,
            'optionSets' => StatusBoard::optionSets(),
            'plan' => $employee->probationPlan ? ['updated_at' => $employee->probationPlan->updated_at?->toIso8601String()] : null,
            'planEnabled' => (bool) config('hrm.probation_plan'),
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
                    'secondary_department_id', 'secondary_manager_id', 'attendance_device_id',
                    'hr_portal_id', 'attendance_machine', 'attendance_machine_id', 'drive_folder_url', 'notes',
                    'needs_review', 'review_note', ...self::PROFILE_FIELDS]),
                ...collect(self::PROFILE_DATES)->mapWithKeys(fn ($f) => [$f => $employee->{$f}?->toDateString()])->all(),
                'start_date' => $employee->start_date?->toDateString(),
                'category_ids' => $employee->categories()->pluck('categories.id')->all(),
                'work_schedule_id' => ScheduleAssigner::current([$employee->id], CarbonImmutable::today()->toDateString())[$employee->id] ?? null,
            ],
        ] + $this->formOptions($employee->id));
    }

    public function update(Request $request, Employee $employee): RedirectResponse
    {
        $data = $this->validated($request, $employee);
        $categories = $this->pullCategories($data);
        $change = $request->validate([
            'change_effective_date' => ['nullable', 'date'],
            'change_type' => ['nullable', Rule::in(config('hrm.history_types'))],
            'change_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $schedule = $this->validatedSchedule($request);

        DB::transaction(function () use ($employee, $data, $change, $request, $schedule, $categories) {
            $jobBefore = JobHistory::labels($employee);
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
            if ($categories !== null) {
                $employee->categories()->sync($categories);
            }
            JobHistory::record($employee, $jobBefore, $change, $request->user()?->id);

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

    /**
     * Someone with status "review" (an Odoo record nobody had confirmed): HR confirms them as current, which gives
     * a REV-### or UNASSIGNED-### code the company's next real code, or records that they left.
     */
    public function decideReview(Request $request, Employee $employee): RedirectResponse
    {
        abort_unless($employee->status === 'review', 422, 'This person is not waiting for a decision.');
        $data = $request->validate([
            'decision' => ['required', Rule::in(['active', 'exited'])],
            'exit_date' => ['nullable', 'date'],
        ]);

        if ($data['decision'] === 'active') {
            if (! $employee->company_id) {
                return back()->with('error', 'Set the company first, so the right code can be given.');
            }
            $update = ['status' => 'active', 'needs_review' => false];
            if (preg_match('/^(REV|UNASSIGNED)-/', $employee->employee_code)) {
                $update['employee_code'] = $employee->company->nextEmployeeCode();
            }
            $employee->update($update);

            return back()->with('success', "{$employee->full_name} is now current staff as {$employee->employee_code}.");
        }

        $employee->update(['status' => 'exited', 'needs_review' => false, 'exit_date' => $data['exit_date'] ?? null]);

        return back()->with('success', "{$employee->full_name} is marked as left.");
    }

    // ------------------------------------------------------------------

    /** category_ids from the form: null when the form did not send them (keep what is stored). */
    private function pullCategories(array &$data): ?array
    {
        if (! array_key_exists('category_ids', $data)) {
            return null;
        }
        $ids = array_values(array_unique(array_map('intval', $data['category_ids'] ?? [])));
        unset($data['category_ids']);

        return $ids;
    }

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
            'secondary_manager_id' => ['nullable', 'integer', Rule::exists('employees', 'id')->withoutTrashed(), Rule::notIn([$employee?->id])],
            'secondary_department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'category_ids' => ['sometimes', 'array'],
            'category_ids.*' => ['integer', 'exists:categories,id'],
            'attendance_device_id' => ['nullable', 'integer', 'exists:attendance_devices,id'],
            'employment_type' => ['nullable', Rule::in(config('hrm.employment_types'))],
            'start_date' => ['nullable', 'date'],
            'hr_portal_id' => ['nullable', 'string', 'max:60', Rule::unique('employees', 'hr_portal_id')->ignore($employee?->id)->withoutTrashed()],
            'attendance_machine' => ['nullable', 'string', 'max:60'],
            'attendance_machine_id' => ['nullable', 'string', 'max:60', Rule::unique('employees', 'attendance_machine_id')->ignore($employee?->id)->withoutTrashed()],
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
            'secondary_manager_id.not_in' => 'An employee cannot be their own manager.',
            'attendance_machine_id.unique' => 'This machine number already belongs to someone else.',
            'hr_portal_id.unique' => 'This HR.my ID already belongs to someone else.',
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
            $secondId = $request->exists('secondary_department_id') ? ($request->integer('secondary_department_id') ?: null) : $employee?->secondary_department_id;
            $second = $secondId ? Department::find($secondId) : null;
            if ($second && $second->company_id !== $companyId) {
                $v->errors()->add('secondary_department_id', 'This department belongs to another company.');
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
                    'id' => $d->id, 'name' => $d->name, 'head_id' => $d->head_employee_id,
                    'teams' => $d->teams->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])->all(),
                ])->all(),
            ])->all(),
            'managers' => Employee::query()->whereIn('status', ['active', 'offboarding'])
                ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
                ->orderBy('full_name')->get(['id', 'employee_code', 'full_name'])
                ->map(fn ($e) => ['id' => $e->id, 'label' => "{$e->full_name} ({$e->employee_code})"])->all(),
            'employmentTypes' => config('hrm.employment_types'),
            'categories' => Category::query()->orderBy('name')->get(['id', 'name'])->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->all(),
            'devices' => AttendanceDevice::query()->orderBy('id')->get(['id', 'code', 'name'])->map(fn ($d) => ['id' => $d->id, 'label' => "{$d->code} {$d->name}"])->all(),
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
}
