<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\ChecklistItem;
use App\Services\HrRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HR can add a new onboarding step, document type or clearance item without a developer.
 * Items the status rules depend on (HrRules::REQUIRED_KEYS) can be renamed but not switched off.
 */
class ChecklistItemController extends Controller
{
    private const SETS = ['onboarding' => ['yes_no', 'session'], 'document' => ['document'], 'offboarding' => ['clearance']];

    public function index(): Response
    {
        $items = ChecklistItem::query()->withCount(['entries' => fn ($q) => $q->whereNotNull('status')])
            ->orderBy('sort_order')->orderBy('id')->get();

        return Inertia::render('settings/checklists', [
            'lists' => collect(ChecklistItem::LISTS)->mapWithKeys(fn ($list) => [$list => $items->where('list', $list)->values()->map(fn ($i) => [
                ...$i->only('id', 'key', 'label', 'option_set', 'description', 'sort_order', 'is_active'),
                'used' => $i->entries_count,
                'required' => in_array($i->key, HrRules::REQUIRED_KEYS[$list] ?? [], true),
            ])]),
            'optionSets' => config('hrm.option_sets'),
            'setsPerList' => self::SETS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'list' => ['required', Rule::in(ChecklistItem::LISTS)],
            'label' => ['required', 'string', 'max:120'],
            'option_set' => ['required', 'string'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);
        if (! in_array($data['option_set'], self::SETS[$data['list']], true)) {
            throw ValidationException::withMessages(['option_set' => 'That set of values can\'t be used on this list.']);
        }
        $key = $base = Str::snake(Str::ascii($data['label'])) ?: 'item';
        $n = 1;
        // Unique across ALL lists: grids that show two lists side by side (onboarding + legal documents)
        // key values by item key, so a shared key would make one column show the other's value.
        while (ChecklistItem::where('key', $key)->exists()) {
            $key = $base.'_'.(++$n);
        }
        ChecklistItem::create($data + [
            'key' => $key,
            'sort_order' => (ChecklistItem::where('list', $data['list'])->max('sort_order') ?? 0) + 10,
            'is_active' => true,
        ]);

        return back()->with('success', "Added \"{$data['label']}\".");
    }

    public function update(Request $request, ChecklistItem $item): RedirectResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:65000'],
        ]);
        if (! $data['is_active'] && in_array($item->key, HrRules::REQUIRED_KEYS[$item->list] ?? [], true)) {
            throw ValidationException::withMessages(['is_active' => "\"{$item->label}\" is used by the status rules, so it can't be switched off."]);
        }
        $item->update($data);

        return back()->with('success', 'Saved.');
    }
}
