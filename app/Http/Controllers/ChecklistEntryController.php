<?php

namespace App\Http\Controllers;

use App\Models\ChecklistEntry;
use App\Models\ChecklistItem;
use App\Models\Employee;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Every status cell in every grid saves through here. Each change is written to the activity log. */
class ChecklistEntryController extends Controller
{
    public function update(Request $request, Employee $employee, ChecklistItem $item): RedirectResponse
    {
        abort_unless($item->is_active, 404);   // switched off in Settings: no new values
        $data = $request->validate([
            'status' => ['nullable', 'string', Rule::in($item->allowedValues())],
        ]);
        $this->save($employee->id, $item, $data['status'] ?? null, $request->user()->id);

        return back();
    }

    /** Set one item to one status for many people at once (e.g. everyone who attended orientation 2). */
    public function bulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'employee_ids' => ['required', 'array', 'min:1', 'max:500'],
            'employee_ids.*' => ['integer', Rule::exists('employees', 'id')->withoutTrashed()],
            'item_id' => ['required', 'integer', Rule::exists('checklist_items', 'id')->where('is_active', true)],
            'status' => ['nullable', 'string'],
        ]);
        $item = ChecklistItem::findOrFail($data['item_id']);
        $status = $data['status'] ?? null;
        if ($status !== null && ! in_array($status, $item->allowedValues(), true)) {
            throw ValidationException::withMessages(['status' => "\"$status\" is not a valid value for {$item->label}."]);
        }

        DB::transaction(function () use ($data, $item, $status, $request) {
            foreach (array_unique($data['employee_ids']) as $employeeId) {
                $this->save($employeeId, $item, $status, $request->user()->id);
            }
        });

        $n = count(array_unique($data['employee_ids']));

        return back()->with('success', "{$item->label} set to ".($status ?? 'blank')." for $n ".($n === 1 ? 'person' : 'people').'.');
    }

    private function save(int $employeeId, ChecklistItem $item, ?string $status, int $userId): void
    {
        $entry = ChecklistEntry::firstOrNew(['employee_id' => $employeeId, 'checklist_item_id' => $item->id]);
        if ($entry->exists && $entry->status === $status) {
            return;   // unchanged: no write, no log row
        }
        $entry->setRelation('item', $item);
        $entry->status = $status;
        $entry->updated_by = $userId;
        $entry->save();
    }
}
