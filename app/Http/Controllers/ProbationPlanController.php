<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\ChecklistEntry;
use App\Models\ChecklistItem;
use App\Models\Employee;
use App\Models\Onboarding;
use App\Models\ProbationCheckin;
use App\Models\ProbationPlan;
use App\Services\HrRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The 90_Day_Plan_Template sheet, one plan per employee, stored as data instead of copied sheets.
 * Saving a 30/60/90-day check-in with a date ticks the matching onboarding checklist item.
 */
class ProbationPlanController extends Controller
{
    private const PLAN_FIELDS = [
        'plan_date', 'final_performance', 'final_performance_notes', 'final_behaviour', 'final_behaviour_notes',
        'final_reliability', 'final_reliability_notes', 'overall_fit', 'overall_fit_notes',
        'manager_recommendation', 'hr_comments', 'employee_signed_name', 'employee_signed_on',
        'manager_signed_name', 'manager_signed_on', 'hr_signed_name', 'hr_signed_on',
    ];

    private const ITEM_FIELDS = ['title', 'detail', 'due_date', 'status', 'evaluation', 'manager_notes', 'employee_notes'];

    private const CHECKIN_FIELDS = ['held_on', 'manager_summary', 'employee_summary', 'action_items', 'next_follow_up', 'initials'];

    public function show(Employee $employee): Response
    {
        $employee->load(['department:id,name', 'manager:id,full_name', 'onboarding', 'probationPlan.items', 'probationPlan.checkins']);
        $plan = $employee->probationPlan;
        $date = fn ($d) => $d?->toDateString();

        return Inertia::render('plan/show', [
            'employee' => [
                'id' => $employee->id, 'code' => $employee->employee_code, 'name' => $employee->full_name,
                'department' => $employee->department?->name, 'role_title' => $employee->role_title,
                'manager' => $employee->manager?->full_name, 'employment_type' => $employee->employment_type,
                'start_date' => $date($employee->start_date), 'cohort' => $employee->onboarding?->cohort,
            ],
            'probation' => [
                'end_date' => $date($employee->onboarding?->probation_end_date),
                'result' => $employee->onboarding?->probation_result,
            ],
            'plan' => $plan ? [
                ...collect(self::PLAN_FIELDS)->mapWithKeys(fn ($f) => [$f => $plan->$f instanceof \DateTimeInterface ? $date($plan->$f) : $plan->$f])->all(),
                'updated_at' => $plan->updated_at?->toIso8601String(),
                'items' => $plan->items->map(fn ($i) => [
                    'id' => $i->id, 'section' => $i->section, 'title' => $i->title, 'detail' => $i->detail,
                    'due_date' => $date($i->due_date), 'status' => $i->status, 'evaluation' => $i->evaluation,
                    'manager_notes' => $i->manager_notes, 'employee_notes' => $i->employee_notes,
                ])->values(),
                'checkins' => $plan->checkins->map(fn ($c) => [
                    'id' => $c->id, 'kind' => $c->kind, 'label' => ProbationCheckin::KINDS[$c->kind] ?? $c->kind,
                    'held_on' => $date($c->held_on), 'manager_summary' => $c->manager_summary,
                    'employee_summary' => $c->employee_summary, 'action_items' => $c->action_items,
                    'next_follow_up' => $date($c->next_follow_up), 'initials' => $c->initials,
                ])->values(),
            ] : null,
            'options' => [
                'itemStatuses' => config('hrm.plan.item_statuses'),
                'evaluations' => config('hrm.plan.evaluations'),
                'finalEvaluations' => config('hrm.plan.final_evaluations'),
                'overallFit' => config('hrm.plan.overall_fit'),
                'probationResults' => config('hrm.probation_results'),
            ],
        ]);
    }

    public function store(Employee $employee): RedirectResponse
    {
        if ($employee->probationPlan()->exists()) {
            return redirect()->route('plan.show', $employee);
        }

        DB::transaction(function () use ($employee) {
            Onboarding::startFor($employee);
            $plan = ProbationPlan::create(['employee_id' => $employee->id, 'plan_date' => now()->toDateString()]);

            // The template rows are part of "ProbationPlan created" (logged above); logging each of the
            // 18 blank rows would only bury real changes. Edits to the rows are logged one by one.
            $wasMuted = ActivityLog::$muted;
            ActivityLog::$muted = true;
            try {
                foreach (config('hrm.plan.defaults') as $section => $rows) {
                    foreach ($rows as $i => $row) {
                        $plan->items()->create(['section' => $section, 'sort_order' => $i + 1,
                            'title' => $row['title'] ?: null, 'detail' => $row['detail'] ?? null]);
                    }
                }
                foreach (['day30', 'day60', 'day90'] as $i => $kind) {
                    $plan->checkins()->create(['kind' => $kind, 'sort_order' => $i + 1]);
                }
            } finally {
                ActivityLog::$muted = $wasMuted;
            }
        });

        return redirect()->route('plan.show', $employee)->with('success', 'Plan created from the 90-day template.');
    }

    public function update(Request $request, Employee $employee): RedirectResponse
    {
        $plan = $employee->probationPlan()->firstOrFail();
        $text = ['nullable', 'string', 'max:4000'];
        $data = $request->validate([
            'plan_date' => ['nullable', 'date'],
            'final_performance' => ['nullable', Rule::in(config('hrm.plan.final_evaluations'))],
            'final_behaviour' => ['nullable', Rule::in(config('hrm.plan.final_evaluations'))],
            'final_reliability' => ['nullable', Rule::in(config('hrm.plan.final_evaluations'))],
            'final_performance_notes' => $text, 'final_behaviour_notes' => $text, 'final_reliability_notes' => $text,
            'overall_fit' => ['nullable', Rule::in(config('hrm.plan.overall_fit'))],
            'overall_fit_notes' => $text, 'manager_recommendation' => $text, 'hr_comments' => $text,
            'employee_signed_name' => ['nullable', 'string', 'max:150'], 'employee_signed_on' => ['nullable', 'date'],
            'manager_signed_name' => ['nullable', 'string', 'max:150'], 'manager_signed_on' => ['nullable', 'date'],
            'hr_signed_name' => ['nullable', 'string', 'max:150'], 'hr_signed_on' => ['nullable', 'date'],
            'probation_result' => ['nullable', Rule::in(config('hrm.probation_results'))],
            'probation_end_date' => ['nullable', 'date'],
            'items' => ['array'],
            'items.*.id' => ['required', 'integer'],
            'items.*.title' => ['nullable', 'string', 'max:255'],
            'items.*.detail' => $text,
            'items.*.due_date' => ['nullable', 'date'],
            'items.*.status' => ['nullable', Rule::in(config('hrm.plan.item_statuses'))],
            'items.*.evaluation' => ['nullable', Rule::in(config('hrm.plan.evaluations'))],
            'items.*.manager_notes' => $text, 'items.*.employee_notes' => $text,
            'checkins' => ['array'],
            'checkins.*.id' => ['required', 'integer'],
            'checkins.*.held_on' => ['nullable', 'date'],
            'checkins.*.manager_summary' => $text, 'checkins.*.employee_summary' => $text, 'checkins.*.action_items' => $text,
            'checkins.*.next_follow_up' => ['nullable', 'date'],
            'checkins.*.initials' => ['nullable', 'string', 'max:20'],
        ]);

        $onboarding = array_key_exists('probation_result', $data) ? Onboarding::startFor($employee) : null;
        if ($onboarding && ! HrRules::isValidExtension(
            $onboarding->probation_result,
            $onboarding->probation_end_date?->toDateString(),
            $data['probation_result'],
            $data['probation_end_date'] ?? null,
        )) {
            throw ValidationException::withMessages(['probation_end_date' => HrRules::EXTEND_NEEDS_LATER_END]);
        }

        $ticked = DB::transaction(function () use ($plan, $employee, $data, $request, $onboarding) {
            // Each model logs its own changes (old and new value) through LogsActivity.
            $plan->update(array_intersect_key($data, array_flip(self::PLAN_FIELDS)));

            $items = $plan->items()->get()->each->setRelation('plan', $plan)->keyBy('id');
            foreach ($data['items'] ?? [] as $row) {
                $items->get($row['id'])?->update(array_intersect_key($row, array_flip(self::ITEM_FIELDS)));
            }
            $checkins = $plan->checkins()->get()->each->setRelation('plan', $plan)->keyBy('id');
            foreach ($data['checkins'] ?? [] as $row) {
                $checkins->get($row['id'])?->update(array_intersect_key($row, array_flip(self::CHECKIN_FIELDS)));
            }

            if ($onboarding) {
                $changes = ['probation_result' => $data['probation_result']];
                if ($data['probation_result'] === 'Extend' && ! empty($data['probation_end_date'])) {
                    $changes['probation_end_date'] = $data['probation_end_date'];
                }
                $onboarding->update($changes);
            }

            // A dated 30/60/90-day check-in ticks the matching onboarding checklist item.
            $ticked = [];
            foreach ($checkins as $c) {
                $key = config("hrm.plan.checkin_items.{$c->kind}");
                $item = $key ? ChecklistItem::where('list', 'onboarding')->where('key', $key)->first() : null;
                if ($item && $c->held_on) {
                    $entry = ChecklistEntry::firstOrNew(['employee_id' => $employee->id, 'checklist_item_id' => $item->id]);
                    if ($entry->status !== 'Yes') {
                        $entry->setRelation('item', $item);
                        $entry->fill(['status' => 'Yes', 'updated_by' => $request->user()->id])->save();
                        $ticked[] = $item->label;
                    }
                }
            }

            return $ticked;
        });

        return back()->with('success', 'Plan saved.'.($ticked ? ' Ticked on the onboarding checklist: '.implode(', ', $ticked).'.' : ''));
    }

    public function addCheckin(Employee $employee): RedirectResponse
    {
        $plan = $employee->probationPlan()->firstOrFail();
        $plan->checkins()->create(['kind' => 'extra', 'sort_order' => ($plan->checkins()->max('sort_order') ?? 0) + 1]);

        return back();
    }
}
