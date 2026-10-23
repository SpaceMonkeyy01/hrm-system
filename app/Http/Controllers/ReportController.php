<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Team;
use App\Services\Reports\ReportCatalog;
use App\Services\Reports\ReportFilters;
use App\Support\Csv;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The Reports page: the list, one report on screen, and the same report as a CSV. Reports: ReportCatalog. */
class ReportController extends Controller
{
    /** Longest date range a report may cover (attendance works out every day for every person). */
    private const MAX_RANGE_DAYS = 366;

    public function index(Request $request): Response
    {
        $private = $request->user()->can('edit-hr');
        $groups = [];
        foreach (ReportCatalog::GROUPS as $key => $label) {
            $groups[] = ['key' => $key, 'label' => $label, 'reports' => collect(ReportCatalog::all())
                ->filter(fn ($r) => $r['group'] === $key && ($private || empty($r['private'])))
                ->map(fn ($r, $k) => ['key' => $k, 'title' => $r['title'], 'description' => $r['description'], 'private' => (bool) ($r['private'] ?? false)])
                ->values()->all()];
        }

        return Inertia::render('reports/index', ['groups' => $groups]);
    }

    public function show(Request $request, string $report): Response
    {
        [$def, $filters] = $this->resolve($request, $report);
        $result = $this->run($def, $filters);

        return Inertia::render('reports/show', [
            'report' => ['key' => $report, 'title' => $def['title'], 'description' => $def['description'], 'group' => ReportCatalog::GROUPS[$def['group']],
                'filters' => $def['filters'], 'wide' => (bool) ($def['wide'] ?? false)],
            'filters' => [
                'month' => $filters->month, 'from' => $filters->from, 'to' => $filters->to, 'year' => $filters->year,
                'date' => $filters->date, 'within' => $filters->within, 'company' => $filters->company, 'department' => $filters->department,
                'day' => $filters->day, 'week' => $filters->week, 'team' => $filters->team, 'manager' => $filters->manager,
                'category' => $filters->category,
            ],
            'today' => $filters->today->toDateString(),
            'companies' => Company::query()->orderBy('name')->with('departments:id,company_id,name')->get(['id', 'name'])
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'departments' => $c->departments->sortBy('name')->map->only('id', 'name')->values()->all()]),
            // Only for reports with those filters: teams (by department) and everyone who manages someone current.
            'teams' => in_array('team', $def['filters'], true)
                ? Team::query()->orderBy('name')->get(['id', 'name', 'department_id'])->map->only('id', 'name', 'department_id') : [],
            'categories' => in_array('category', $def['filters'], true) ? Category::query()->orderBy('name')->get(['id', 'name'])->map->only('id', 'name') : [],
            'managers' => in_array('manager', $def['filters'], true)
                ? Employee::query()->whereIn('id', Employee::query()->whereIn('status', ['active', 'offboarding'])->whereNotNull('manager_id')->select('manager_id'))
                    ->orderBy('full_name')->get(['id', 'full_name', 'employee_code', 'company_id'])
                    ->map(fn ($e) => ['id' => $e->id, 'name' => $e->full_name, 'code' => $e->employee_code, 'company_id' => $e->company_id]) : [],
            'result' => ['columns' => $result->columns, 'rows' => $result->rows, 'summary' => $result->summary, 'notes' => $result->notes],
        ]);
    }

    public function export(Request $request, string $report): StreamedResponse
    {
        [$def, $filters] = $this->resolve($request, $report);
        [$header, $rows] = $this->run($def, $filters)->toCsv();
        $period = match (true) {
            in_array('month', $def['filters'], true) => $filters->month,
            in_array('day', $def['filters'], true) => $filters->day,
            in_array('week', $def['filters'], true) => "week-of-{$filters->week}",
            in_array('range', $def['filters'], true) => $filters->from === $filters->to ? $filters->from : "{$filters->from}-to-{$filters->to}",
            in_array('year', $def['filters'], true) => (string) $filters->year,
            default => $filters->today->toDateString(),
        };

        // A manager's or team's sheet is named after them, so the files HR forwards are told apart.
        $who = match (true) {
            (bool) $filters->manager => Employee::find($filters->manager)?->full_name,
            (bool) $filters->team => Team::find($filters->team)?->name,
            (bool) $filters->category => Category::find($filters->category)?->name,
            default => null,
        };

        return Csv::download("$report-$period".($who ? '-'.Str::slug($who) : '').'.csv', $header, $rows);
    }

    private function run(array $def, ReportFilters $filters)
    {
        [$class, $method] = $def['run'];

        return app($class)->{$method}($filters);
    }

    /** @return array{0: array, 1: ReportFilters} */
    private function resolve(Request $request, string $report): array
    {
        $def = ReportCatalog::find($report);
        abort_unless($def, 404);
        abort_if(! empty($def['private']) && ! $request->user()->can('edit-hr'), 403);

        $f = $request->validate([
            'month' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'within' => ['nullable', 'integer', 'between:0,730'],
            'day' => ['nullable', 'date_format:Y-m-d'],
            'week' => ['nullable', 'date_format:Y-m-d'],
            'company' => ['nullable', 'integer'],
            'department' => ['nullable', 'integer'],
            'team' => ['nullable', 'integer'],
            'manager' => ['nullable', 'integer'],
            'category' => ['nullable', 'integer'],
        ], ['month.regex' => 'Pick a month like 2026-10.']);

        $today = CarbonImmutable::today();
        $month = $f['month'] ?? $today->format('Y-m');
        if ($month > $today->format('Y-m')) {
            $month = $today->format('Y-m');
        }
        // Any day picked for the week means its Monday; never past the current week (nor a day past today).
        $week = CarbonImmutable::parse($f['week'] ?? $today->toDateString())->startOfWeek(CarbonImmutable::MONDAY)
            ->min($today->startOfWeek(CarbonImmutable::MONDAY))->toDateString();
        $day = min($f['day'] ?? $today->toDateString(), $today->toDateString());
        $from = $f['from'] ?? (($def['range'] ?? null) === 'today' ? $today : $today->startOfMonth())->toDateString();
        $to = $f['to'] ?? $today->toDateString();
        if ($to < $from) {
            throw ValidationException::withMessages(['to' => 'The end date is before the start date.']);
        }
        if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > self::MAX_RANGE_DAYS) {
            throw ValidationException::withMessages(['to' => 'Pick a range of a year or less.']);
        }

        return [$def, new ReportFilters(
            today: $today,
            month: $month,
            from: $from,
            to: $to,
            year: (int) ($f['year'] ?? $today->year),
            date: $f['date'] ?? $today->toDateString(),
            within: (int) ($f['within'] ?? 60),
            day: $day,
            week: $week,
            company: isset($f['company']) ? (int) $f['company'] : null,
            department: isset($f['department']) ? (int) $f['department'] : null,
            team: isset($f['team']) ? (int) $f['team'] : null,
            manager: isset($f['manager']) ? (int) $f['manager'] : null,
            category: isset($f['category']) ? (int) $f['category'] : null,
        )];
    }
}
