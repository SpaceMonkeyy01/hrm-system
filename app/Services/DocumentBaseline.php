<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\ChecklistEntry;
use App\Models\ChecklistItem;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Old-staff baseline for the document checklist: long-serving people were never put through it,
 * so their blank cells mean "not tracked", not "owed". This marks those BLANK cells "Old employee"
 * (a done value); anything HR already set (Yes, No, Pending...) is left alone.
 *
 * Written in bulk (the live database is far away; one save per cell would time out),
 * with one activity row per cell, like any other checklist change.
 */
class DocumentBaseline
{
    public const VALUE = 'Old employee';

    /** Current staff who started before $before (plus those with no start date when $undated). */
    private function people(string $before, bool $undated): Builder
    {
        return Employee::query()->whereIn('status', ['active', 'offboarding'])
            ->where(fn ($q) => $q->where('start_date', '<', $before)->when($undated, fn ($q) => $q->orWhereNull('start_date')));
    }

    /** @return \Illuminate\Support\Collection<int, ChecklistItem> active document items that accept "Old employee" */
    private function items()
    {
        return ChecklistItem::query()->ofList('document')->where('is_active', true)->get()
            ->filter(fn (ChecklistItem $i) => in_array(self::VALUE, $i->allowedValues(), true))->values();
    }

    /**
     * Blank (employee, item) pairs for these people.
     *
     * @param  array<int>  $employeeIds
     * @return array<int, array{0: int, 1: ChecklistItem, 2: ?int}> [employee id, item, existing entry id or null]
     */
    private function blanks(array $employeeIds): array
    {
        $items = $this->items();
        $existing = ChecklistEntry::query()->whereIn('employee_id', $employeeIds)->whereIn('checklist_item_id', $items->pluck('id'))
            ->get(['id', 'employee_id', 'checklist_item_id', 'status'])
            ->keyBy(fn ($e) => $e->employee_id.':'.$e->checklist_item_id);
        $out = [];
        foreach ($employeeIds as $id) {
            foreach ($items as $item) {
                $entry = $existing->get($id.':'.$item->id);
                if ($entry === null || $entry->status === null || $entry->status === '') {
                    $out[] = [$id, $item, $entry?->id];
                }
            }
        }

        return $out;
    }

    /** @return array{before: string, people: int, cells: int, undated: int, undated_cells: int} */
    public function candidates(string $before): array
    {
        $dated = $this->blanks($this->people($before, false)->pluck('id')->all());
        $undatedIds = Employee::query()->whereIn('status', ['active', 'offboarding'])->whereNull('start_date')->pluck('id')->all();
        $undated = $this->blanks($undatedIds);

        return [
            'before' => $before,
            'people' => count(array_unique(array_column($dated, 0))),
            'cells' => count($dated),
            'undated' => count(array_unique(array_column($undated, 0))),
            'undated_cells' => count($undated),
        ];
    }

    /** @return array{people: int, cells: int} */
    public function apply(string $before, bool $undated, ?int $userId): array
    {
        $blanks = $this->blanks($this->people($before, $undated)->pluck('id')->all());
        if ($blanks === []) {
            return ['people' => 0, 'cells' => 0];
        }

        DB::transaction(function () use ($blanks, $userId) {
            $now = now();
            $toUpdate = array_filter(array_map(fn ($b) => $b[2], $blanks));
            foreach (array_chunk($toUpdate, 500) as $ids) {
                ChecklistEntry::query()->whereIn('id', $ids)->toBase()->update(['status' => self::VALUE, 'updated_by' => $userId, 'updated_at' => $now]);
            }
            $new = array_values(array_filter($blanks, fn ($b) => $b[2] === null));
            foreach (array_chunk($new, 200) as $chunk) {
                ChecklistEntry::query()->insert(array_map(fn ($b) => [
                    'employee_id' => $b[0], 'checklist_item_id' => $b[1]->id, 'status' => self::VALUE,
                    'updated_by' => $userId, 'created_at' => $now, 'updated_at' => $now,
                ], $chunk));
            }

            // One activity row per cell, on that person's timeline.
            $ids = ChecklistEntry::query()->whereIn('employee_id', array_unique(array_column($blanks, 0)))
                ->get(['id', 'employee_id', 'checklist_item_id'])->keyBy(fn ($e) => $e->employee_id.':'.$e->checklist_item_id);
            $rows = array_map(fn ($b) => [
                'user_id' => $userId, 'employee_id' => $b[0], 'subject_type' => 'ChecklistEntry',
                'subject_id' => $ids->get($b[0].':'.$b[1]->id)?->id, 'event' => $b[2] ? 'updated' : 'created',
                'description' => $b[1]->label.' (old-staff baseline)',
                'changes' => json_encode(['status' => [null, self::VALUE]]), 'created_at' => $now,
            ], $blanks);
            if (! ActivityLog::$muted) {
                foreach (array_chunk($rows, 200) as $chunk) {
                    ActivityLog::query()->insert($chunk);
                }
            }
        });

        return ['people' => count(array_unique(array_column($blanks, 0))), 'cells' => count($blanks)];
    }
}
