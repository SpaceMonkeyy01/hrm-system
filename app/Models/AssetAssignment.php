<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One asset given to one person, from assigned_on until returned_on (null = still with them). */
class AssetAssignment extends Model
{
    use LogsActivity;

    protected $fillable = ['asset_id', 'employee_id', 'assigned_on', 'returned_on', 'condition_out', 'condition_in', 'note', 'assigned_by', 'returned_by'];

    protected $attributes = ['returned_on' => null];

    protected function casts(): array
    {
        return ['assigned_on' => 'date:Y-m-d', 'returned_on' => 'date:Y-m-d'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function activityEmployeeId(): ?int
    {
        return $this->employee_id;
    }

    public function activityLabel(): string
    {
        return 'Asset assignment';
    }

    /** One person's assets for their profile, My page and offboarding: still with them first, then returned. */
    public static function rowsFor(int $employeeId, bool $withReturned = true): array
    {
        return self::query()->with('asset:id,asset_tag,name,type,serial_number')->where('employee_id', $employeeId)
            ->when(! $withReturned, fn ($q) => $q->whereNull('returned_on'))
            ->orderByRaw('returned_on is not null')->orderByDesc('assigned_on')->get()
            ->map(fn (self $a) => [
                'id' => $a->id, 'asset_id' => $a->asset_id, 'tag' => $a->asset->asset_tag, 'name' => $a->asset->name, 'type' => $a->asset->type,
                'serial_number' => $a->asset->serial_number, 'assigned_on' => $a->assigned_on->toDateString(),
                'returned_on' => $a->returned_on?->toDateString(), 'condition_out' => $a->condition_out, 'condition_in' => $a->condition_in,
            ])->all();
    }

    /** Assets still out with these people, as "LAP-014 Dell Latitude" strings, keyed by employee id. */
    public static function outstandingFor(array $employeeIds): array
    {
        return self::query()->with('asset:id,asset_tag,name,type')->whereIn('employee_id', $employeeIds)->whereNull('returned_on')->get()
            ->groupBy('employee_id')->map(fn ($g) => $g->map(fn ($a) => "{$a->asset->asset_tag} {$a->asset->name}")->all())->all();
    }
}
