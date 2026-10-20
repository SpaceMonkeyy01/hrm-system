<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Team;
use App\Services\JobHistory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Companies -> departments -> teams, plus categories (tags on employees). Delete is only allowed when nobody is assigned. */
class StructureController extends Controller
{
    public function index(): Response
    {
        // Current = active or leaving; the rest (left, under review) still point at the department, so it can't be deleted.
        $current = ['employees as current_count' => fn ($q) => $q->whereIn('status', ['active', 'offboarding'])];
        $companies = Company::query()->orderBy('name')
            ->with(['departments' => fn ($q) => $q->withCount(['employees', ...$current])
                ->with(['teams' => fn ($t) => $t->withCount(['employees', ...$current]), 'head:id,full_name'])])
            ->withCount(['employees', ...$current])->get();

        return Inertia::render('settings/structure', [
            'companies' => $companies->map(fn ($c) => [
                'id' => $c->id, 'name' => $c->name, 'code' => $c->code, 'prefix' => $c->employee_code_prefix, 'hr_email' => $c->hr_email,
                'employees' => $c->employees_count, 'current' => $c->current_count,
                'departments' => $c->departments->map(fn ($d) => [
                    'id' => $d->id, 'name' => $d->name, 'employees' => $d->employees_count, 'current' => $d->current_count, 'head_id' => $d->head_employee_id,
                    'teams' => $d->teams->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'employees' => $t->employees_count, 'current' => $t->current_count])->all(),
                ])->all(),
            ]),
            // Current staff per company, to pick a department head from.
            'people' => Employee::query()->whereIn('status', ['active', 'offboarding'])->whereNotNull('company_id')->orderBy('full_name')
                ->get(['id', 'full_name', 'employee_code', 'company_id'])->groupBy('company_id')
                ->map(fn ($g) => $g->map(fn ($e) => ['value' => $e->id, 'label' => "{$e->full_name} ({$e->employee_code})"])->values()),
            'categories' => Category::query()->withCount('employees')->orderBy('name')->get()
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'employees' => $c->employees_count]),
        ]);
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80', Rule::unique('categories')]]);
        Category::create($data);

        return back()->with('success', "Added category {$data['name']}.");
    }

    public function updateCategory(Request $request, Category $category): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80', Rule::unique('categories')->ignore($category->id)]]);
        $category->update($data);

        return back()->with('success', 'Renamed.');
    }

    public function destroyCategory(Category $category): RedirectResponse
    {
        if ($category->employees()->exists()) {
            return back()->with('error', "{$category->name} is still on employees. Take it off them first.");
        }
        $category->delete();

        return back()->with('success', "Deleted {$category->name}.");
    }

    /** The address emails to this company's staff come from. Only the default sender's domain is verified for sending. */
    public function updateCompanyEmail(Request $request, Company $company): RedirectResponse
    {
        $domain = strtolower((string) strrchr((string) config('mail.from.address'), '@'));
        $data = $request->validate(['hr_email' => ['nullable', 'email', 'max:190', function ($attr, $value, $fail) use ($domain) {
            if ($domain && ! str_ends_with(strtolower($value), $domain)) {
                $fail("Use an address ending in $domain: that is the domain set up for sending.");
            }
        }]]);
        $company->update(['hr_email' => $data['hr_email'] ? strtolower($data['hr_email']) : null]);

        return back()->with('success', $company->hr_email
            ? "Emails to {$company->name} staff now come from {$company->hr_email}."
            : "Emails to {$company->name} staff now come from the default address.");
    }

    public function storeDepartment(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'name' => ['required', 'string', 'max:120',
                Rule::unique('departments')->where('company_id', $request->integer('company_id'))],
        ]);
        Department::create($data);

        return back()->with('success', "Added department {$data['name']}.");
    }

    public function updateDepartment(Request $request, Department $department): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120',
            Rule::unique('departments')->where('company_id', $department->company_id)->ignore($department->id)]]);
        $department->update($data);

        return back()->with('success', 'Renamed.');
    }

    /** Head from the department's own company (current staff), or none. */
    public function setHead(Request $request, Department $department): RedirectResponse
    {
        $data = $request->validate(['head_employee_id' => ['nullable', 'integer',
            Rule::exists('employees', 'id')->where('company_id', $department->company_id)->whereIn('status', ['active', 'offboarding'])->whereNull('deleted_at')]],
            ['head_employee_id.exists' => 'Pick someone currently working in this company.']);
        $department->update($data);

        return back()->with('success', $department->head_employee_id ? "Saved the head of {$department->name}." : "{$department->name} has no head now.");
    }

    public function destroyDepartment(Department $department): RedirectResponse
    {
        if ($department->employees()->exists()) {
            return back()->with('error', "{$department->name} still has employees. Move them first.");
        }
        $department->delete();

        return back()->with('success', "Deleted {$department->name}.");
    }

    /**
     * Fold one department into another in the same company (duplicates, a renamed department): everyone moves over,
     * former staff included; its teams move too (a team with the same name joins the existing one); then it's deleted.
     * Current staff get a job history row, so their record shows the move.
     */
    public function mergeDepartment(Request $request, Department $department): RedirectResponse
    {
        $data = $request->validate(['into_id' => ['required', 'integer',
            Rule::exists('departments', 'id')->where('company_id', $department->company_id)]],
            ['into_id.exists' => 'Pick a department in the same company.']);
        abort_if((int) $data['into_id'] === $department->id, 422, 'A department can\'t be merged into itself.');
        $into = Department::with('teams')->findOrFail($data['into_id']);

        $moved = DB::transaction(function () use ($department, $into, $request) {
            $note = ['change_note' => "Department {$department->name} merged into {$into->name}"];
            $teamMap = $this->moveTeams($department, $into);
            $moved = 0;
            foreach (Employee::query()->where('department_id', $department->id)->get() as $e) {
                $before = JobHistory::labels($e);
                $e->department_id = $into->id;
                if ($e->team_id) {
                    $e->team_id = $teamMap[$e->team_id] ?? $e->team_id;
                }
                if ($e->secondary_department_id === $into->id) {
                    $e->secondary_department_id = null;
                }
                $e->save();
                if (in_array($e->status, ['active', 'offboarding'], true)) {
                    JobHistory::record($e, $before, $note, $request->user()->id);
                }
                $moved++;
            }
            Employee::query()->where('secondary_department_id', $department->id)->get()
                ->each(fn ($e) => $e->update(['secondary_department_id' => $e->department_id === $into->id ? null : $into->id]));
            if (! $into->head_employee_id && $department->head_employee_id) {
                $into->update(['head_employee_id' => $department->head_employee_id]);
            }
            $department->delete();

            return $moved;
        });

        return back()->with('success', "Merged {$department->name} into {$into->name}".($moved ? ' ('.($moved === 1 ? '1 person' : "$moved people").' moved).' : '.'));
    }

    /**
     * A team to another department of the same company. Its people move department with it (job history for current
     * staff). If that department already has a team with the same name, the two become one.
     */
    public function moveTeam(Request $request, Team $team): RedirectResponse
    {
        $from = $team->department;
        $data = $request->validate(['department_id' => ['required', 'integer',
            Rule::exists('departments', 'id')->where('company_id', $from->company_id)]],
            ['department_id.exists' => 'Pick a department in the same company.']);
        $into = Department::with('teams')->findOrFail($data['department_id']);
        if ($into->id === $from->id) {
            return back();
        }
        $same = $into->teams->first(fn ($t) => mb_strtolower($t->name) === mb_strtolower($team->name));

        DB::transaction(function () use ($team, $from, $into, $same, $request) {
            $note = ['change_note' => "Team {$team->name} moved from {$from->name} to {$into->name}"];
            foreach (Employee::query()->where('team_id', $team->id)->get() as $e) {
                $before = JobHistory::labels($e);
                $e->fill(['department_id' => $into->id, 'team_id' => $same?->id ?? $team->id])->save();
                if (in_array($e->status, ['active', 'offboarding'], true)) {
                    JobHistory::record($e, $before, $note, $request->user()->id);
                }
            }
            $same ? $team->delete() : $team->update(['department_id' => $into->id]);
        });

        return back()->with('success', $same ? "Joined {$team->name} with the {$same->name} team in {$into->name}." : "Moved {$team->name} to {$into->name}.");
    }

    /** @return array<int, int> old team id => the team it became in $into (only for teams joined with a same-named one) */
    private function moveTeams(Department $from, Department $into): array
    {
        $map = [];
        foreach ($from->teams()->get() as $t) {
            $same = $into->teams->first(fn ($x) => mb_strtolower($x->name) === mb_strtolower($t->name));
            if ($same) {
                $map[$t->id] = $same->id;
                Employee::query()->where('team_id', $t->id)->update(['team_id' => $same->id]);
                $t->delete();
            } else {
                $t->update(['department_id' => $into->id]);
            }
        }

        return $map;
    }

    public function storeTeam(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'name' => ['required', 'string', 'max:120',
                Rule::unique('teams')->where('department_id', $request->integer('department_id'))],
        ]);
        Team::create($data);

        return back()->with('success', "Added team {$data['name']}.");
    }

    public function updateTeam(Request $request, Team $team): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120',
            Rule::unique('teams')->where('department_id', $team->department_id)->ignore($team->id)]]);
        $team->update($data);

        return back()->with('success', 'Renamed.');
    }

    public function destroyTeam(Team $team): RedirectResponse
    {
        if ($team->employees()->exists()) {
            return back()->with('error', "{$team->name} still has employees. Move them first.");
        }
        $team->delete();

        return back()->with('success', "Deleted {$team->name}.");
    }
}
