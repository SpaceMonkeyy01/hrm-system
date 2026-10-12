<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Employee;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Org chart for current staff, two ways:
 *  - structure  company > department > team > people (from department and team on each profile)
 *  - managers   who reports to whom (from Manager on each profile); a person is a root when they have reports
 *               but no current manager. People with neither a manager nor reports are counted, not drawn.
 */
class OrgChartController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $f = $request->validate(['company' => ['nullable', 'integer']]);
        $people = Employee::query()->whereIn('status', ['active', 'offboarding'])
            ->with(['company:id,name', 'department:id,name', 'team:id,name'])
            ->when($f['company'] ?? null, fn ($q, $v) => $q->where('company_id', $v))
            ->orderBy('full_name')
            ->get(['id', 'employee_code', 'full_name', 'role_title', 'company_id', 'department_id', 'team_id', 'manager_id', 'status', 'photo_path']);
        $node = fn (Employee $e) => ['id' => $e->id, 'name' => $e->full_name, 'code' => $e->employee_code, 'role' => $e->role_title, 'photo' => $e->photoUrl(),
            'department' => $e->department?->name, 'leaving' => $e->status === 'offboarding'];

        // ---------------------------------------------------------------- structure
        $structure = [];
        foreach ($people->groupBy(fn ($e) => $e->company?->name ?? 'No company yet') as $company => $inCompany) {
            $departments = [];
            foreach ($inCompany->groupBy(fn ($e) => $e->department?->name ?? 'No department') as $department => $inDept) {
                $teams = [];
                foreach ($inDept->groupBy(fn ($e) => $e->team?->name ?? '') as $team => $inTeam) {
                    $teams[] = ['name' => $team === '' ? null : $team, 'people' => $inTeam->map($node)->values()->all()];
                }
                // Named teams A-Z, people without a team last.
                usort($teams, fn ($a, $b) => [$a['name'] === null, $a['name']] <=> [$b['name'] === null, $b['name']]);
                $departments[] = ['id' => $inDept->first()->department_id, 'name' => $department, 'count' => $inDept->count(), 'teams' => $teams];
            }
            usort($departments, fn ($a, $b) => $b['count'] <=> $a['count']);
            $structure[] = ['name' => $company, 'count' => $inCompany->count(), 'departments' => $departments];
        }
        usort($structure, fn ($a, $b) => [$a['name'] === 'No company yet', $a['name']] <=> [$b['name'] === 'No company yet', $b['name']]);

        // ---------------------------------------------------------------- managers
        $byId = $people->keyBy('id');
        $reports = $people->filter(fn ($e) => $e->manager_id && $byId->has($e->manager_id))->groupBy('manager_id');
        $build = function (Employee $e, array $seen) use (&$build, $reports, $node): array {
            $seen[$e->id] = true;   // a loop (A manages B manages A) stops here instead of running forever
            $children = ($reports->get($e->id) ?? collect())->reject(fn ($r) => isset($seen[$r->id]))
                ->map(fn ($r) => $build($r, $seen))->values()->all();

            return $node($e) + ['reports' => $children, 'total' => array_sum(array_map(fn ($c) => 1 + $c['total'], $children))];
        };
        $roots = $people->filter(fn ($e) => $reports->has($e->id) && (! $e->manager_id || ! $byId->has($e->manager_id)))
            ->map(fn ($e) => $build($e, []))->sortByDesc('total')->values()->all();
        $inTree = $people->filter(fn ($e) => $reports->has($e->id) || ($e->manager_id && $byId->has($e->manager_id)))->count();

        return Inertia::render('org-chart', [
            'filters' => ['company' => isset($f['company']) ? (int) $f['company'] : null],
            'companies' => Company::query()->orderBy('name')->get(['id', 'name']),
            'total' => $people->count(),
            'structure' => $structure,
            'managers' => ['roots' => $roots, 'inTree' => $inTree, 'withoutManager' => $people->count() - $inTree],
        ]);
    }
}
