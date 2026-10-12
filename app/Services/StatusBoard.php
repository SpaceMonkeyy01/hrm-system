<?php

namespace App\Services;

use App\Models\ChecklistEntry;
use App\Models\ChecklistItem;
use Illuminate\Support\Collection;

/**
 * Loads checklist values for a set of employees in one query and computes their statuses
 * with HrRules. Used by the dashboard, both grids, the employee page and the CSV exports,
 * so every screen shows the same numbers.
 */
class StatusBoard
{
    /** @var array<string, Collection<int, ChecklistItem>> active items per list */
    public array $items = [];

    /** @var array<int, array<string, array<string, ?string>>> employee id => list => key => status */
    private array $values = [];

    /** @param  int[]  $employeeIds */
    public function __construct(array $employeeIds)
    {
        $all = ChecklistItem::query()->orderBy('sort_order')->orderBy('id')->get();
        foreach (ChecklistItem::LISTS as $list) {
            $this->items[$list] = $all->where('list', $list)->where('is_active', true)->values();
        }
        $byId = $all->keyBy('id');

        foreach (array_chunk($employeeIds, 900) as $chunk) {   // stay under SQLite's bound-parameter limit
            ChecklistEntry::query()->whereIn('employee_id', $chunk)->whereNotNull('status')
                ->get(['employee_id', 'checklist_item_id', 'status'])
                ->each(function ($e) use ($byId) {
                    $item = $byId[$e->checklist_item_id] ?? null;
                    if ($item) {
                        $this->values[$e->employee_id][$item->list][$item->key] = $e->status;
                    }
                });
        }
    }

    /** @return array<string, ?string> key => status for one list (active items only, blanks included as null) */
    public function values(int $employeeId, string $list): array
    {
        $out = [];
        foreach ($this->items[$list] as $item) {
            $out[$item->key] = $this->values[$employeeId][$list][$item->key] ?? null;
        }

        return $out;
    }

    public function orientation(int $employeeId): string
    {
        return HrRules::orientationStatus($this->values($employeeId, 'onboarding'));
    }

    public function documents(int $employeeId): string
    {
        return HrRules::documentStatus($this->values($employeeId, 'document'));
    }

    public function onboarding(int $employeeId, ?string $probationResult): string
    {
        return HrRules::onboardingStatus(
            $this->values($employeeId, 'onboarding'),
            $this->values($employeeId, 'document'),
            $probationResult,
        );
    }

    /** Items as plain arrays for the frontend: [{id, key, label, option_set, required}] */
    public function columns(string $list): array
    {
        $required = HrRules::REQUIRED_KEYS[$list] ?? [];

        return $this->items[$list]->map(fn ($i) => [
            'id' => $i->id, 'key' => $i->key, 'label' => $i->label, 'option_set' => $i->option_set,
            'description' => $i->description, 'required' => in_array($i->key, $required, true),
        ])->all();
    }

    /** Option sets for the frontend: {set: [{value, tone, done}]} */
    public static function optionSets(): array
    {
        return config('hrm.option_sets');
    }
}
