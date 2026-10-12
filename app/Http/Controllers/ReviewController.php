<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Review;
use App\Models\ReviewCycle;
use App\Models\User;
use App\Services\ReviewRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Performance review cycles and HR's side of each review. HR and admin only (routes: can:edit-hr).
 * The person's side (self-review, acknowledging the result) is on My page (MeController).
 */
class ReviewController extends Controller
{
    public function index(): Response
    {
        $counts = Review::query()->select('review_cycle_id', 'status', DB::raw('count(*) as n'))->groupBy('review_cycle_id', 'status')->get()
            ->groupBy('review_cycle_id')->map(fn ($g) => $g->pluck('n', 'status'));

        return Inertia::render('reviews/index', [
            'cycles' => ReviewCycle::query()->latest('period_end')->latest('id')->get()->map(fn (ReviewCycle $c) => [
                ...$this->cycleProps($c), 'counts' => $counts->get($c->id) ?? (object) [],
            ]),
            'statuses' => ReviewRules::STATUSES,
            'cycleStatuses' => ReviewRules::CYCLE_STATUSES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $cycle = ReviewCycle::create($this->cycleData($request) + [
            'criteria' => config('hrm.reviews.default_criteria'), 'status' => 'draft', 'created_by' => $request->user()->id,
        ]);

        return redirect()->route('reviews.show', $cycle)->with('success', "Created {$cycle->name}. Check what is rated, add people, then open it.");
    }

    public function show(ReviewCycle $cycle): Response
    {
        $reviews = $cycle->reviews()->with(['employee:id,employee_code,full_name,department_id,company_id', 'employee.department:id,name'])->get()
            ->sortBy(fn ($r) => $r->employee->full_name)->values();

        return Inertia::render('reviews/show', [
            'cycle' => $this->cycleProps($cycle) + ['criteria' => $cycle->criteria],
            'reviews' => $reviews->map(fn (Review $r) => [
                'id' => $r->id, 'status' => $r->status, 'overall' => $r->overall(), 'band' => ReviewRules::band($r->overall()),
                'recommendation' => $r->recommendation, 'acknowledged' => $r->acknowledged_at !== null,
                'employee' => ['id' => $r->employee->id, 'name' => $r->employee->full_name, 'code' => $r->employee->employee_code, 'department' => $r->employee->department?->name],
            ]),
            'statuses' => ReviewRules::STATUSES,
            'cycleStatuses' => ReviewRules::CYCLE_STATUSES,
            'companies' => Company::query()->orderBy('name')->with('departments:id,company_id,name')->get(['id', 'name'])
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'departments' => $c->departments->sortBy('name')->map->only('id', 'name')->values()->all()]),
            'eligible' => $this->eligible($cycle)->count(),
        ]);
    }

    public function update(Request $request, ReviewCycle $cycle): RedirectResponse
    {
        abort_if($cycle->status === 'closed', 403);
        $data = $this->cycleData($request);
        if ($request->has('criteria')) {
            if ($cycle->status !== 'draft') {
                throw ValidationException::withMessages(['criteria' => 'What is rated can only change before the cycle opens.']);
            }
            $data['criteria'] = $this->criteria($request, $cycle);
        }
        $cycle->update($data);

        return back()->with('success', "Saved {$cycle->name}.");
    }

    /** Add everyone eligible, or one company / department. People already in the cycle are skipped. */
    public function addPeople(Request $request, ReviewCycle $cycle): RedirectResponse
    {
        abort_if($cycle->status === 'closed', 403);
        $data = $request->validate([
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'employee_id' => ['nullable', 'integer', Rule::exists('employees', 'id')->withoutTrashed()],
        ]);
        $people = $this->eligible($cycle)
            ->when($data['company_id'] ?? null, fn ($q, $v) => $q->where('company_id', $v))
            ->when($data['department_id'] ?? null, fn ($q, $v) => $q->where('department_id', $v))
            ->when($data['employee_id'] ?? null, fn ($q, $v) => $q->whereKey($v))
            ->get(['id']);
        $withLogin = User::query()->where('is_active', true)->whereIn('employee_id', $people->pluck('id'))->pluck('employee_id')->flip();
        DB::transaction(function () use ($people, $cycle, $withLogin) {
            foreach ($people as $e) {
                Review::create(['review_cycle_id' => $cycle->id, 'employee_id' => $e->id, 'status' => ReviewRules::initialStatus($withLogin->has($e->id))]);
            }
        });

        return back()->with('success', $people->isEmpty() ? 'Nobody new to add: everyone who fits is already in.' : 'Added '.$people->count().' '.Str::plural('person', $people->count()).'.');
    }

    public function open(ReviewCycle $cycle): RedirectResponse
    {
        if ($cycle->status !== 'draft') {
            return back()->with('error', 'This cycle is already open or closed.');
        }
        if (! $cycle->reviews()->exists()) {
            return back()->with('error', 'Add people before opening the cycle.');
        }
        $cycle->update(['status' => 'open']);

        return back()->with('success', 'Opened. People with a login can now fill in their self-review on My page.');
    }

    public function close(ReviewCycle $cycle): RedirectResponse
    {
        if ($cycle->status !== 'open') {
            return back()->with('error', 'Only an open cycle can be closed.');
        }
        $cycle->update(['status' => 'closed']);

        return back()->with('success', "Closed {$cycle->name}. Nothing in it can be changed now.");
    }

    // ------------------------------------------------------------------ one review

    public function review(ReviewCycle $cycle, Review $review): Response
    {
        $this->belongs($cycle, $review);
        $review->load(['employee:id,employee_code,full_name,department_id,role_title,start_date,manager_id', 'employee.department:id,name',
            'employee.manager:id,full_name', 'reviewer:id,name']);
        $e = $review->employee;

        return Inertia::render('reviews/review', [
            'cycle' => $this->cycleProps($cycle) + ['criteria' => $cycle->criteria],
            'review' => $this->reviewProps($review, withManager: true),
            'employee' => ['id' => $e->id, 'name' => $e->full_name, 'code' => $e->employee_code, 'department' => $e->department?->name,
                'role' => $e->role_title, 'start_date' => $e->start_date?->toDateString(), 'manager' => $e->manager?->full_name],
            'canEdit' => ReviewRules::canManagerReview($review, $cycle),
            'scale' => config('hrm.reviews.scale'),
            'recommendations' => config('hrm.reviews.recommendations'),
            'statuses' => ReviewRules::STATUSES,
        ]);
    }

    /** HR's ratings and comments. "submit" finishes the review; without it the answers are saved as a draft. */
    public function saveReview(Request $request, ReviewCycle $cycle, Review $review): RedirectResponse
    {
        $this->belongs($cycle, $review);
        if (! ReviewRules::canManagerReview($review, $cycle)) {
            throw ValidationException::withMessages(['review' => 'This review can\'t be changed now.']);
        }
        $data = $this->answers($request, $cycle, 'manager') + $request->validate([
            'goals' => ['nullable', 'array', 'max:10'],
            'goals.*.title' => ['required', 'string', 'max:200'],
            'goals.*.measure' => ['nullable', 'string', 'max:300'],
            'goals.*.due' => ['nullable', 'date_format:Y-m-d'],
            'recommendation' => ['nullable', Rule::in(config('hrm.reviews.recommendations'))],
            'submit' => ['boolean'],
        ], ['goals.*.title.required' => 'Give each goal a title, or remove it.']);
        $submit = (bool) ($data['submit'] ?? false);
        unset($data['submit']);
        if ($submit) {
            $missing = ReviewRules::missingRatings($cycle->criteria, $data['manager_ratings']);
            if ($missing) {
                throw ValidationException::withMessages(['manager_ratings' => 'Rate every line before finishing: '.implode(', ', $missing).'.']);
            }
            $data += ['status' => 'completed', 'manager_submitted_at' => now(), 'reviewed_by' => $request->user()->id];
        }
        $review->update($data);

        return back()->with('success', $submit ? 'Review finished. The person can now see it on My page.' : 'Saved as a draft.');
    }

    /** The self-review isn't coming (no time, on leave): go straight to HR's review. */
    public function skipSelf(ReviewCycle $cycle, Review $review): RedirectResponse
    {
        $this->belongs($cycle, $review);
        if ($review->status !== 'self_review') {
            return back()->with('error', 'This review isn\'t waiting for a self-review.');
        }
        $review->update(['status' => 'manager_review']);

        return back()->with('success', 'Skipped the self-review.');
    }

    /** Take someone out: deleted while the cycle is being set up, kept as "taken out" once it is open. */
    public function remove(ReviewCycle $cycle, Review $review): RedirectResponse
    {
        $this->belongs($cycle, $review);
        abort_if($cycle->status === 'closed', 403);
        if ($review->status === 'completed') {
            return back()->with('error', 'A finished review can\'t be taken out.');
        }
        $cycle->status === 'draft' ? $review->delete() : $review->update(['status' => 'cancelled']);

        return back()->with('success', 'Taken out of the cycle.');
    }

    // ------------------------------------------------------------------ shared

    /** Ratings 1-5, comments and summary for one side (self | manager), only for this cycle's criteria. */
    public static function answers(Request $request, ReviewCycle $cycle, string $side): array
    {
        $keys = array_column($cycle->criteria, 'key');
        $data = $request->validate([
            "{$side}_ratings" => ['nullable', 'array'],
            "{$side}_ratings.*" => ['nullable', 'integer', 'between:1,5'],
            "{$side}_comments" => ['nullable', 'array'],
            "{$side}_comments.*" => ['nullable', 'string', 'max:2000'],
            "{$side}_summary" => ['nullable', 'string', 'max:5000'],
        ]);
        $pick = fn ($values) => array_intersect_key(array_filter($values ?? [], fn ($v) => $v !== null && $v !== ''), array_flip($keys));

        return [
            "{$side}_ratings" => array_map('intval', $pick($data["{$side}_ratings"] ?? [])),
            "{$side}_comments" => $pick($data["{$side}_comments"] ?? []),
            "{$side}_summary" => $data["{$side}_summary"] ?? null,
        ];
    }

    /**
     * One review for the pages. Each side's draft stays with whoever wrote it:
     *   HR's page ($withManager)  HR's answers; the self-review only once the person has sent it
     *   My page                   their own answers (draft or sent); HR's only once the review is finished
     */
    public static function reviewProps(Review $review, bool $withManager): array
    {
        $showManager = $withManager || $review->status === 'completed';
        $showSelf = ! $withManager || $review->self_submitted_at !== null;

        return [
            'id' => $review->id, 'status' => $review->status,
            'self_ratings' => $showSelf ? (object) ($review->self_ratings ?? []) : (object) [],
            'self_comments' => $showSelf ? (object) ($review->self_comments ?? []) : (object) [],
            'self_summary' => $showSelf ? $review->self_summary : null, 'self_submitted_at' => $review->self_submitted_at?->toIso8601String(),
            'manager_ratings' => $showManager ? (object) ($review->manager_ratings ?? []) : (object) [],
            'manager_comments' => $showManager ? (object) ($review->manager_comments ?? []) : (object) [],
            'manager_summary' => $showManager ? $review->manager_summary : null,
            'goals' => $showManager ? ($review->goals ?? []) : [],
            'recommendation' => $showManager ? $review->recommendation : null,
            'overall' => $showManager ? $review->overall() : null,
            'band' => $showManager ? ReviewRules::band($review->overall()) : null,
            'reviewed_by' => $showManager && $review->relationLoaded('reviewer') ? $review->reviewer?->name : null,
            'manager_submitted_at' => $review->manager_submitted_at?->toIso8601String(),
            'acknowledged_at' => $review->acknowledged_at?->toIso8601String(),
            'employee_comment' => $review->employee_comment,
        ];
    }

    public static function cycleProps(ReviewCycle $c): array
    {
        return [
            ...$c->only('id', 'name', 'status', 'notes'),
            'period_start' => $c->period_start->toDateString(), 'period_end' => $c->period_end->toDateString(),
            'self_due' => $c->self_due?->toDateString(), 'manager_due' => $c->manager_due?->toDateString(),
        ];
    }

    private function cycleData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'period_start' => ['required', 'date_format:Y-m-d'],
            'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start'],
            'self_due' => ['nullable', 'date_format:Y-m-d'],
            'manager_due' => ['nullable', 'date_format:Y-m-d', ...($request->filled('self_due') ? ['after_or_equal:self_due'] : [])],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], ['period_end.after_or_equal' => 'The period ends before it starts.', 'manager_due.after_or_equal' => 'HR reviews are due before the self-reviews.']);
    }

    /** Criteria from the form: existing ones keep their key (so answers stay attached), new ones get one. */
    private function criteria(Request $request, ReviewCycle $cycle): array
    {
        $data = $request->validate([
            'criteria' => ['required', 'array', 'min:1', 'max:15'],
            'criteria.*.key' => ['nullable', 'string', 'max:60'],
            'criteria.*.label' => ['required', 'string', 'max:120', 'distinct'],
            'criteria.*.description' => ['nullable', 'string', 'max:300'],
        ], ['criteria.min' => 'Keep at least one thing to rate.', 'criteria.*.label.distinct' => 'Two lines have the same name.', 'criteria.*.label.required' => 'Give each line a name, or remove it.']);
        $used = [];
        $out = [];
        foreach ($data['criteria'] as $c) {
            $key = ($c['key'] ?? null) ?: Str::slug($c['label'], '_');
            while (isset($used[$key])) {
                $key .= '_2';
            }
            $used[$key] = true;
            $out[] = ['key' => $key, 'label' => $c['label'], 'description' => $c['description'] ?? null];
        }

        return $out;
    }

    /** Current staff who started on or before the end of the period and aren't in the cycle yet. */
    private function eligible(ReviewCycle $cycle)
    {
        return Employee::query()->whereIn('status', ['active', 'offboarding'])
            ->where(fn ($q) => $q->whereNull('start_date')->orWhere('start_date', '<=', $cycle->period_end->toDateString()))
            ->whereNotIn('id', $cycle->reviews()->select('employee_id'));
    }

    private function belongs(ReviewCycle $cycle, Review $review): void
    {
        abort_unless($review->review_cycle_id === $cycle->id, 404);
    }
}
