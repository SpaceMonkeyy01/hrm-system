<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\AssetType;
use App\Models\Company;
use App\Models\Employee;
use App\Services\AssetTags;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Asset register. Status rules live here: giving an asset to someone sets it to "assigned"; taking it back sets
 * it to in stock, repair, lost or retired. HR can't type "assigned" by hand, and can't change the status of an
 * asset someone still has without taking it back first.
 */
class AssetController extends Controller
{
    public function index(Request $request): Response
    {
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', 'string', 'max:40'],
            'status' => ['nullable', Rule::in(array_keys(config('hrm.assets.statuses')))],
            'company' => ['nullable', 'integer'],
            'warranty' => ['nullable', Rule::in(['ending', 'expired'])],
        ]);
        $today = CarbonImmutable::today();
        $until = $today->addDays((int) config('hrm.assets.warranty_days', 30))->toDateString();
        $like = isset($f['q']) ? '%'.str_replace(['%', '_'], ['\%', '\_'], $f['q']).'%' : null;
        $assets = Asset::query()
            ->with(['current.employee:id,full_name,employee_code,status', 'assignments' => fn ($q) => $q->limit(500), 'assignments.employee:id,full_name,employee_code'])
            ->when($like, fn ($q) => $q->where(fn ($w) => $w->whereLike('asset_tag', $like)->orWhereLike('name', $like)->orWhereLike('serial_number', $like)
                ->orWhereHas('current.employee', fn ($e) => $e->whereLike('full_name', $like)->orWhereLike('employee_code', $like))))
            ->when($f['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['company'] ?? null, fn ($q, $v) => $q->where('company_id', $v))
            ->when(($f['warranty'] ?? null) === 'ending', fn ($q) => $q->warrantyEnding($today->toDateString(), $until))
            ->when(($f['warranty'] ?? null) === 'expired', fn ($q) => $q->whereNotIn('status', Asset::OUT_OF_USE)->where('warranty_until', '<', $today->toDateString()))
            ->with('company:id,name')
            ->orderBy('asset_tag')->get();

        return Inertia::render('assets/index', [
            'filters' => $f,
            'assets' => $assets->map(fn (Asset $a) => [
                ...$a->only('id', 'asset_tag', 'type', 'name', 'serial_number', 'status', 'notes', 'company_id', 'cost', 'vendor'),
                'company' => $a->company?->name,
                'purchase_date' => $a->purchase_date?->toDateString(),
                'warranty_until' => $a->warranty_until?->toDateString(),
                'holder' => $a->current ? ['id' => $a->current->employee->id, 'name' => $a->current->employee->full_name,
                    'code' => $a->current->employee->employee_code, 'since' => $a->current->assigned_on->toDateString(),
                    'leaving' => $a->current->employee->status !== 'active'] : null,
                'history' => $a->assignments->take(10)->map(fn (AssetAssignment $h) => [
                    'id' => $h->id, 'employee' => $h->employee?->full_name, 'employee_id' => $h->employee_id,
                    'assigned_on' => $h->assigned_on->toDateString(), 'returned_on' => $h->returned_on?->toDateString(),
                    'condition_out' => $h->condition_out, 'condition_in' => $h->condition_in, 'note' => $h->note,
                ])->values(),
            ]),
            'counts' => Asset::query()->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status'),
            'types' => AssetType::activeNames(),
            'assetTypes' => AssetType::query()->orderBy('sort_order')->orderBy('name')->get()
                ->map(fn ($t) => [...$t->only('id', 'name', 'prefix', 'is_active'), 'count' => Asset::where('type', $t->name)->count()]),
            'companies' => Company::query()->orderBy('name')->get(['id', 'name', 'code']),
            // Value of what's still in use, per company (no company = "Not set")
            'totals' => Asset::query()->whereNotIn('status', Asset::OUT_OF_USE)
                ->selectRaw('company_id, count(*) as n, sum(cost) as cost, sum(case when cost is null then 1 else 0 end) as no_cost')
                ->groupBy('company_id')->toBase()->get()
                ->map(fn ($r) => ['company_id' => $r->company_id, 'count' => (int) $r->n, 'cost' => (float) $r->cost, 'no_cost' => (int) $r->no_cost])->values(),
            'warrantyEnding' => Asset::query()->warrantyEnding($today->toDateString(), $until)->count(),
            'warrantyDays' => (int) config('hrm.assets.warranty_days', 30),
            'statuses' => config('hrm.assets.statuses'),
            'people' => $request->user()->can('edit-hr')
                ? Employee::query()->whereIn('status', ['active', 'offboarding'])->orderBy('full_name')->get(['id', 'full_name', 'employee_code'])
                    ->map(fn ($e) => ['value' => $e->id, 'label' => "{$e->full_name} ({$e->employee_code})"])
                : [],
            'today' => CarbonImmutable::today()->toDateString(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, null);
        if (($data['asset_tag'] ?? null) === null) {
            $data['asset_tag'] = AssetTags::next(isset($data['company_id']) ? Company::find($data['company_id']) : null, AssetType::where('name', $data['type'])->first());
        }
        $asset = Asset::create($data);

        return back()->with('success', "Added {$asset->asset_tag}.");
    }

    public function update(Request $request, Asset $asset): RedirectResponse
    {
        $data = $this->validated($request, $asset);
        $asset->loadMissing('current');
        if ($asset->current && $data['status'] !== 'assigned') {
            throw ValidationException::withMessages(['status' => "{$asset->asset_tag} is with someone. Take it back first to change its status."]);
        }
        $asset->update($data);

        return back()->with('success', "Saved {$asset->asset_tag}.");
    }

    public function assign(Request $request, Asset $asset): RedirectResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->withoutTrashed()->whereIn('status', ['active', 'offboarding'])],
            'assigned_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'condition_out' => ['nullable', 'string', 'max:150'],
            'note' => ['nullable', 'string', 'max:500'],
        ], ['employee_id.exists' => 'Pick a current employee.', 'assigned_on.before_or_equal' => "The date can't be in the future."]);
        $asset->loadMissing('current');
        if ($asset->current || $asset->status !== 'in_stock') {
            throw ValidationException::withMessages(['employee_id' => "{$asset->asset_tag} isn't in stock, so it can't be given out."]);
        }
        $employee = Employee::findOrFail($data['employee_id']);

        DB::transaction(function () use ($asset, $data, $request) {
            AssetAssignment::create($data + ['asset_id' => $asset->id, 'assigned_by' => $request->user()->id]);
            $asset->update(['status' => 'assigned']);
        });

        return back()->with('success', "Gave {$asset->asset_tag} to {$employee->full_name}.");
    }

    public function return(Request $request, Asset $asset): RedirectResponse
    {
        $asset->loadMissing('current.employee:id,full_name');
        $current = $asset->current;
        if (! $current) {
            throw ValidationException::withMessages(['returned_on' => "{$asset->asset_tag} isn't with anyone."]);
        }
        $data = $request->validate([
            'returned_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:'.$current->assigned_on->toDateString()],
            'condition_in' => ['nullable', 'string', 'max:150'],
            'status' => ['required', Rule::in(['in_stock', 'repair', 'lost', 'retired'])],
            'note' => ['nullable', 'string', 'max:500'],
        ], ['returned_on.after_or_equal' => 'That is before the day it was given out.', 'returned_on.before_or_equal' => "The date can't be in the future."]);

        DB::transaction(function () use ($asset, $current, $data, $request) {
            $current->update(['returned_on' => $data['returned_on'], 'condition_in' => $data['condition_in'] ?? null, 'returned_by' => $request->user()->id,
                'note' => trim(($current->note ? $current->note."\n" : '').($data['note'] ?? '')) ?: null]);
            $asset->update(['status' => $data['status']]);
        });
        $how = $data['status'] === 'lost' ? 'Marked as lost' : 'Took back';

        return back()->with('success', "$how {$asset->asset_tag} from {$current->employee->full_name}.");
    }

    // ---------------------------------------------------------------- types

    public function storeType(Request $request): RedirectResponse
    {
        $data = $this->typeData($request, null);
        AssetType::create($data + ['sort_order' => (AssetType::max('sort_order') ?? 0) + 10]);

        return back()->with('success', "Added the {$data['name']} type.");
    }

    /** Rename (its assets follow), change the tag prefix (old tags stay as they are), or switch off. */
    public function updateType(Request $request, AssetType $type): RedirectResponse
    {
        $data = $this->typeData($request, $type) + ['is_active' => $request->boolean('is_active', true)];
        DB::transaction(function () use ($type, $data) {
            if ($data['name'] !== $type->name) {
                Asset::where('type', $type->name)->update(['type' => $data['name']]);
            }
            $type->update($data);
        });

        return back()->with('success', "Saved the {$data['name']} type.");
    }

    private function typeData(Request $request, ?AssetType $type): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:40', Rule::unique('asset_types', 'name')->ignore($type?->id)],
            'prefix' => ['required', 'string', 'max:6', 'regex:/^[A-Za-z0-9]+$/', Rule::unique('asset_types', 'prefix')->ignore($type?->id)],
        ], ['prefix.regex' => 'Letters and numbers only, e.g. LAP.', 'prefix.unique' => 'Another type already uses this prefix.']);

        return ['name' => trim($data['name']), 'prefix' => strtoupper($data['prefix'])];
    }

    private function validated(Request $request, ?Asset $asset): array
    {
        $statuses = array_keys(config('hrm.assets.statuses'));

        return $request->validate([
            // Empty on a new asset = the next automatic tag (AssetTags)
            'asset_tag' => [$asset ? 'required' : 'nullable', 'string', 'max:40', Rule::unique('assets', 'asset_tag')->ignore($asset?->id)],
            // A switched-off type can stay on the assets that already have it
            'type' => ['required', Rule::in(array_unique([...AssetType::activeNames(), ...($asset ? [$asset->type] : [])]))],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'cost' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'vendor' => ['nullable', 'string', 'max:100'],
            'warranty_until' => ['nullable', 'date_format:Y-m-d'],
            'name' => ['required', 'string', 'max:150'],
            'serial_number' => ['nullable', 'string', 'max:100'],
            // "assigned" only comes from giving the asset to someone; it stays while they have it.
            'status' => ['required', Rule::in($asset?->status === 'assigned' ? $statuses : array_diff($statuses, ['assigned']))],
            'purchase_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], ['asset_tag.unique' => 'Another asset already has this tag.', 'status.in' => 'Give the asset to someone to mark it as assigned.']);
    }
}
