<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmploymentHistory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Hand-added employment history (a past promotion, a confirmation). Changes made on the Edit page
 * are recorded by EmployeeController@update instead.
 */
class EmploymentHistoryController extends Controller
{
    public function store(Request $request, Employee $employee): RedirectResponse
    {
        $data = $request->validate([
            'effective_date' => ['required', 'date'],
            'change_type' => ['required', Rule::in(config('hrm.history_types'))],
            'note' => ['required', 'string', 'max:2000'],
        ]);

        EmploymentHistory::create($data + ['employee_id' => $employee->id, 'created_by' => $request->user()->id]);

        return back()->with('success', 'Added to employment history.');
    }

    public function destroy(Employee $employee, EmploymentHistory $history): RedirectResponse
    {
        abort_unless($history->employee_id === $employee->id, 404);
        $history->delete();

        return back()->with('success', 'Removed from employment history.');
    }
}
