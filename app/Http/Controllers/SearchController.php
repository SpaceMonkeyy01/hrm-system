<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** "Find an employee" (Ctrl K): a handful of matches as JSON, current staff first. Same matching as the Employees list. */
class SearchController extends Controller
{
    public function employees(Request $request): JsonResponse
    {
        $q = $request->validate(['q' => ['required', 'string', 'max:100']])['q'];
        $hits = Employee::query()->with('department:id,name')->search($q)
            ->orderByRaw("case when status = 'exited' then 1 else 0 end")->orderBy('full_name')
            ->limit(8)->get(['id', 'full_name', 'employee_code', 'department_id', 'status']);

        return response()->json($hits->map(fn (Employee $e) => [
            'id' => $e->id, 'name' => $e->full_name, 'code' => $e->employee_code, 'department' => $e->department?->name, 'status' => $e->status,
        ]));
    }
}
