<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\Employee;
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
        ]);
        $like = isset($f['q']) ? '%'.str_replace(['%', '_'], ['\%', '\_'], $f['q']).'%' : null;
        $assets = Asset::query()
            ->with(['current.employee:id,full_name,employee_code,status', 'assignments' => fn ($q) => $q->limit(500), 'assignments.employee:id,full_name,employee_code'])
            ->when($like, fn ($q) => $q->where(fn ($w) => $w->where('asset_tag', 'like', $like)->orWhere('name', 'like', $like)->orWhere('serial_number', 'like', $like)
                ->orWhereHas('current.employee', fn ($e) => $e->where('full_name', 'like', $like)->orWhere('employee_code', 'like', $like))))
            ->when($f['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderBy('asset_tag')->get();

        return Inertia::render('assets/index', [
            'filters' => $f,
            'assets' => $assets->map(fn (Asset $a) => [
                ...$a->only('id', 'asset_tag', 'type', 'name', 'serial_number', 'status', 'notes'),
                'purchase_date' => $a->purchase_date?->toDateString(),
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
            'types' => config('hrm.assets.types'),
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
        $asset = Asset::create($this->validated($request, null));

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

    private function validated(Request $request, ?Asset $asset): array
    {
        $statuses = array_keys(config('hrm.assets.statuses'));

        return $request->validate([
            'asset_tag' => ['required', 'string', 'max:40', Rule::unique('assets', 'asset_tag')->ignore($asset?->id)],
            'type' => ['required', Rule::in(config('hrm.assets.types'))],
            'name' => ['required', 'string', 'max:150'],
            'serial_number' => ['nullable', 'string', 'max:100'],
            // "assigned" only comes from giving the asset to someone; it stays while they have it.
            'status' => ['required', Rule::in($asset?->status === 'assigned' ? $statuses : array_diff($statuses, ['assigned']))],
            'purchase_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], ['asset_tag.unique' => 'Another asset already has this tag.', 'status.in' => 'Give the asset to someone to mark it as assigned.']);
    }
}
