<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\ChecklistItem;
use App\Services\HrRules;
use App\Models\AppSetting;
use App\Models\Onboarding;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
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
            'probation' => [
                'days' => HrRules::probationDays(),
                'checkins' => collect(HrRules::checkinOffsets())->map(fn ($days, $key) => [
                    'key' => $key, 'days' => $days, 'label' => $items->firstWhere('key', $key)?->label ?? $key,
                ])->values(),
            ],
        ]);
    }

    /**
     * Probation length and check-in days for everyone (Settings > Checklists > Onboarding). Check-in due dates are worked
     * out live, so they follow at once. Stored end dates change only for open probations still on the old length, when
     * HR asks; anyone extended or with an end date typed by hand keeps theirs.
     */
    public function updateProbation(Request $request): RedirectResponse
    {
        $keys = array_keys(config('hrm.reminders.checkin_offsets'));
        $data = $request->validate([
            'days' => ['required', 'integer', 'min:7', 'max:730'],
            'checkins' => ['required', 'array'],
            ...collect($keys)->mapWithKeys(fn ($k) => ["checkins.$k" => ['required', 'integer', 'min:1', 'lte:days']])->all(),
            'update_open' => ['boolean'],
        ], ['checkins.*.lte' => 'A check-in can\'t be after the end of probation.']);
        $days = array_map(fn ($k) => (int) $data['checkins'][$k], $keys);
        $sorted = $days;
        sort($sorted);
        if ($days !== $sorted || count(array_unique($days)) !== count($days)) {
            throw ValidationException::withMessages(['checkins' => 'Put the check-ins in order, each on a later day than the one before.']);
        }

        $oldDays = HrRules::probationDays();
        $moved = 0;
        DB::transaction(function () use ($data, $keys, $oldDays, $request, &$moved) {
            AppSetting::put('probation_days', (int) $data['days']);
            AppSetting::put('checkin_offsets', array_combine($keys, array_map(fn ($k) => (int) $data['checkins'][$k], $keys)));
            if ($request->boolean('update_open') && $oldDays !== (int) $data['days']) {
                $open = Onboarding::query()->open()->where('probation_result', 'Pending')->whereNotNull('probation_end_date')
                    ->whereHas('employee', fn ($q) => $q->whereIn('status', ['active', 'offboarding'])->whereNotNull('start_date'))
                    ->with('employee:id,start_date')->get();
                foreach ($open as $o) {
                    $start = CarbonImmutable::parse($o->employee->start_date);
                    if ($o->probation_end_date->toDateString() === $start->addDays($oldDays)->toDateString()) {
                        $o->update(['probation_end_date' => $start->addDays((int) $data['days'])->toDateString()]);
                        $moved++;
                    }
                }
            }
        });

        return back()->with('success', 'Saved. Probation is now '.$data['days'].' days.'
            .($moved ? ' Moved the end date for '.($moved === 1 ? '1 open probation.' : "$moved open probations.") : ''));
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
