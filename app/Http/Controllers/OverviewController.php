<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\AttendanceBoard;
use App\Services\HrSnapshot;
use App\Services\PeopleStats;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Overview: how the company looks. Headcount over time, movement, departments, documents, onboarding, attendance this month. */
class OverviewController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $f = $request->validate(['company' => ['nullable', 'integer']]);
        $company = isset($f['company']) ? (int) $f['company'] : null;
        $today = CarbonImmutable::today();
        $now = CarbonImmutable::now();

        $stats = new PeopleStats($company);
        $current = $stats->employees->whereIn('status', ['active', 'offboarding'])->values();
        $monthly = $stats->monthly($today, 12, $today);
        $yearAgo = $stats->onBooksOn($today->subYear()->toDateString())->count();
        $left = array_sum(array_column($monthly, 'leavers'));
        $snapshot = HrSnapshot::build($current->pluck('id')->all(), $today);

        // Attendance month to date, for current staff.
        $month = (new AttendanceBoard($current, $today->startOfMonth()->toDateString(), $today->toDateString()))->totals($now);

        $departments = $current->groupBy(fn ($e) => (int) $e->department_id)
            ->map(fn ($g) => ['id' => $g->first()->department_id, 'name' => $g->first()->department?->name ?? 'No department',
                'company' => $g->first()->company?->name, 'count' => $g->count()])
            ->sortByDesc('count')->values()->all();

        return Inertia::render('overview', [
            'today' => $today->toDateString(),
            'filters' => ['company' => $company],
            'companies' => Company::query()->orderBy('name')->get(['id', 'name']),
            'tiles' => [
                'headcount' => $current->count(),
                'yearAgo' => $yearAgo,
                'joined30' => $stats->joiners($today->subDays(30)->toDateString(), $today->toDateString())->count(),
                'left30' => $stats->leavers($today->subDays(30)->toDateString(), $today->toDateString())->count(),
                'turnover12' => PeopleStats::turnover($left, $monthly[0]['opening'], end($monthly)['closing']),
                'left12' => $left,
            ],
            'monthly' => $monthly,
            'departments' => $departments,
            'attendance' => $month,
            ...$snapshot,
        ]);
    }
}
