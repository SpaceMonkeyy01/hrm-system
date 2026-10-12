<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Onboarding;
use App\Services\HrRules;
use App\Services\StatusBoard;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** The Onboarding_Tracker sheet as an editable grid. One row per person in the onboarding process. */
class OnboardingController extends Controller
{
    /** Column groups for the grid header. Custom items added in Settings land in "Other". */
    private const GROUPS = [
        'email_created' => 'Setup', 'desk_allocated' => 'Setup', 'system_access' => 'Setup',
        'contact_info' => 'Setup', 'hr_database' => 'Setup',
        'orientation_1' => 'Orientation', 'orientation_2' => 'Orientation', 'orientation_3' => 'Orientation',
        'day30_checkin' => 'Check-ins', 'day60_checkin' => 'Check-ins', 'day90_evaluation' => 'Check-ins',
    ];

    private const LEGAL = ['policy_acknowledgment', 'nda_signed', 'nca_signed'];

    public function index(Request $request): Response
    {
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'company' => ['nullable', 'integer'],
            'department' => ['nullable', 'integer'],
            'cohort' => ['nullable', Rule::in([...config('hrm.cohorts'), 'none'])],
            'status' => ['nullable', Rule::in(['in_progress', 'complete'])],
            'orientation' => ['nullable', Rule::in(['Not Started', 'In Progress', 'Complete'])],
            'docs' => ['nullable', Rule::in(['Pending', 'Complete'])],
            'legal' => ['nullable', Rule::in(['pending'])],
            'probation' => ['nullable', Rule::in([...config('hrm.probation_results'), 'none', 'overdue', 'ending'])],
        ]);

        $employees = Employee::query()
            ->whereIn('status', ['active', 'offboarding'])
            ->whereHas('onboarding')
            ->with(['onboarding', 'company:id,name', 'department:id,name'])
            ->search($f['q'] ?? null)
            ->when($f['company'] ?? null, fn ($q, $v) => $q->where('company_id', $v))
            ->when($f['department'] ?? null, fn ($q, $v) => $q->where('department_id', $v))
            ->orderBy('full_name')
            ->get();

        $board = new StatusBoard($employees->pluck('id')->all());
        $today = CarbonImmutable::today();

        $rows = $employees->map(function (Employee $e) use ($board, $today) {
            $o = $e->onboarding;
            $end = $o->probation_end_date?->toDateString();
            $docs = $board->values($e->id, 'document');

            return [
                'id' => $e->id, 'code' => $e->employee_code, 'name' => $e->full_name,
                'company' => $e->company?->name, 'department' => $e->department?->name,
                'needs_review' => $e->needs_review,
                'cohort' => $o->cohort,
                'start_date' => $e->start_date?->toDateString(),
                'employment_type' => $e->employment_type,
                'probation_end_date' => $end,
                'probation_result' => $o->probation_result,
                'overdue' => HrRules::isProbationOverdue($end, $o->probation_result, $today),
                'ending' => HrRules::isProbationEndingSoon($end, $o->probation_result, $today),
                // Legal document values first, so a Legal documents column always reads the document list
                // even if an older onboarding step has the same key.
                'values' => array_intersect_key($docs, array_flip(self::LEGAL)) + $board->values($e->id, 'onboarding'),
                'legal_pending' => collect(self::LEGAL)->contains(fn ($k) => ! HrRules::isDone($docs[$k] ?? null, 'document')),
                'orientation' => $board->orientation($e->id),
                'documents' => $board->documents($e->id),
                'status' => $board->onboarding($e->id, $o->probation_result),
            ];
        })->filter(function (array $r) use ($f) {
            $cohort = $f['cohort'] ?? null;
            $prob = $f['probation'] ?? null;

            return (! $cohort || ($cohort === 'none' ? $r['cohort'] === null : $r['cohort'] === $cohort))
                && (! ($f['status'] ?? null) || ($f['status'] === 'complete') === ($r['status'] === 'Onboarding Complete'))
                && (! ($f['orientation'] ?? null) || $r['orientation'] === $f['orientation'])
                && (! ($f['docs'] ?? null) || $r['documents'] === $f['docs'])
                && (! ($f['legal'] ?? null) || $r['legal_pending'])
                && (! $prob || match ($prob) {
                    'overdue' => $r['overdue'],
                    'ending' => $r['ending'],
                    'none' => $r['probation_result'] === null,
                    default => $r['probation_result'] === $prob,
                });
        })->values();

        $columns = array_map(fn ($c) => $c + ['list' => 'onboarding', 'group' => self::GROUPS[$c['key']] ?? 'Other'], $board->columns('onboarding'));
        foreach ($board->columns('document') as $c) {
            if (in_array($c['key'], self::LEGAL, true)) {
                $columns[] = $c + ['list' => 'document', 'group' => 'Legal documents'];
            }
        }

        return Inertia::render('onboarding/index', [
            'rows' => $rows,
            'columns' => $columns,
            'optionSets' => StatusBoard::optionSets(),
            'filters' => $f,
            'companies' => Company::query()->orderBy('name')->with('departments:id,company_id,name')->get(['id', 'name']),
            'cohorts' => config('hrm.cohorts'),
            'probationResults' => config('hrm.probation_results'),
            'notInOnboarding' => Employee::query()->whereIn('status', ['active', 'offboarding'])->whereDoesntHave('onboarding')->count(),
        ]);
    }

    /** Inline edits of the non-checklist columns: Group, probation end date, probation result, notes. */
    public function update(Request $request, Employee $employee): RedirectResponse
    {
        $data = $request->validate([
            'cohort' => ['sometimes', 'nullable', Rule::in(config('hrm.cohorts'))],
            'probation_end_date' => ['sometimes', 'nullable', 'date'],
            'probation_result' => ['sometimes', 'nullable', Rule::in(config('hrm.probation_results'))],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);
        $onboarding = Onboarding::startFor($employee);
        if (array_key_exists('probation_result', $data) && ! HrRules::isValidExtension(
            $onboarding->probation_result,
            $onboarding->probation_end_date?->toDateString(),
            $data['probation_result'],
            $data['probation_end_date'] ?? null,
        )) {
            throw ValidationException::withMessages(['probation_end_date' => HrRules::EXTEND_NEEDS_LATER_END]);
        }
        $onboarding->update($data);

        return back();
    }

    /** Put someone who isn't in the onboarding process yet into it. */
    public function start(Employee $employee): RedirectResponse
    {
        Onboarding::startFor($employee);

        return back()->with('success', "{$employee->full_name} is now in onboarding.");
    }
}
