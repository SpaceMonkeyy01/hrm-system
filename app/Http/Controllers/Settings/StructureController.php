<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Company;
use App\Models\Department;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Companies -> departments -> teams, plus categories (tags on employees). Delete is only allowed when nobody is assigned. */
class StructureController extends Controller
{
    public function index(): Response
    {
        $companies = Company::query()->orderBy('name')
            ->with(['departments' => fn ($q) => $q->withCount('employees')->with(['teams' => fn ($t) => $t->withCount('employees')])])
            ->withCount('employees')->get();

        return Inertia::render('settings/structure', [
            'companies' => $companies->map(fn ($c) => [
                'id' => $c->id, 'name' => $c->name, 'code' => $c->code, 'prefix' => $c->employee_code_prefix,
                'employees' => $c->employees_count,
                'departments' => $c->departments->map(fn ($d) => [
                    'id' => $d->id, 'name' => $d->name, 'employees' => $d->employees_count,
                    'teams' => $d->teams->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'employees' => $t->employees_count])->all(),
                ])->all(),
            ]),
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

    public function destroyDepartment(Department $department): RedirectResponse
    {
        if ($department->employees()->exists()) {
            return back()->with('error', "{$department->name} still has employees. Move them first.");
        }
        $department->delete();

        return back()->with('success', "Deleted {$department->name}.");
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
