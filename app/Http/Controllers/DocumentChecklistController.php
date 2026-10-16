<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Employee;
use App\Services\HrRules;
use App\Services\StatusBoard;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** The Onboarding_Document_Checklist sheet as an editable grid, for every current employee. */
class DocumentChecklistController extends Controller
{
    public function index(Request $request): Response
    {
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'company' => ['nullable', 'integer'],
            'department' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['Pending', 'Complete'])],
            'missing' => ['nullable', 'string', 'max:60'],
            'scope' => ['nullable', Rule::in(['all', 'onboarding'])],
        ]);

        $employees = Employee::query()
            ->whereIn('status', ['active', 'offboarding'])
            ->with(['company:id,name', 'department:id,name'])
            ->search($f['q'] ?? null)
            ->when($f['company'] ?? null, fn ($q, $v) => $q->where('company_id', $v))
            ->when($f['department'] ?? null, fn ($q, $v) => $q->where('department_id', $v))
            ->when(($f['scope'] ?? 'all') === 'onboarding', fn ($q) => $q->whereHas('onboarding', fn ($q) => $q->open()))
            ->orderBy('full_name')
            ->get();

        $board = new StatusBoard($employees->pluck('id')->all());

        $all = $employees->map(fn (Employee $e) => [
            'id' => $e->id, 'code' => $e->employee_code, 'name' => $e->full_name,
            'company' => $e->company?->name, 'department' => $e->department?->name,
            'drive_folder_url' => $e->drive_folder_url, 'needs_review' => $e->needs_review,
            'values' => $board->values($e->id, 'document'),
            'status' => $board->documents($e->id),
        ]);

        // Header badges: how many people still owe each document (before the status filters)
        $owed = [];
        foreach ($board->items['document'] as $item) {
            $owed[$item->key] = $all->filter(fn ($r) => ! HrRules::isDone($r['values'][$item->key] ?? null, 'document'))->count();
        }

        $rows = $all->filter(fn ($r) => (! ($f['status'] ?? null) || $r['status'] === $f['status'])
            && (! ($f['missing'] ?? null) || ! HrRules::isDone($r['values'][$f['missing']] ?? null, 'document')))->values();

        return Inertia::render('documents/index', [
            'rows' => $rows,
            'columns' => array_map(fn ($c) => $c + ['list' => 'document', 'group' => 'Documents'], $board->columns('document')),
            'owed' => $owed,
            'optionSets' => StatusBoard::optionSets(),
            'filters' => $f,
            'companies' => Company::query()->orderBy('name')->with('departments:id,company_id,name')->get(['id', 'name']),
        ]);
    }
}
