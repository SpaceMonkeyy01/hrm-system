<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** A piece of company property. Who has it: the assignment without a return date (current()). */
class Asset extends Model
{
    use LogsActivity;

    protected $fillable = ['company_id', 'asset_tag', 'type', 'name', 'serial_number', 'status', 'purchase_date', 'cost', 'vendor', 'warranty_until', 'notes'];

    /** Statuses of assets no longer in use: their warranty doesn't matter. */
    public const OUT_OF_USE = ['lost', 'retired'];

    protected $attributes = ['status' => 'in_stock'];

    protected function casts(): array
    {
        return ['purchase_date' => 'date:Y-m-d', 'warranty_until' => 'date:Y-m-d', 'cost' => 'float'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Still in use with a warranty ending between today and $until (inclusive). */
    public function scopeWarrantyEnding(Builder $q, string $today, string $until): Builder
    {
        return $q->whereNotIn('status', self::OUT_OF_USE)->whereBetween('warranty_until', [$today, $until]);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(AssetAssignment::class)->orderByDesc('assigned_on')->orderByDesc('id');
    }

    public function current(): HasOne
    {
        return $this->hasOne(AssetAssignment::class)->whereNull('returned_on');
    }

    public function activityLabel(): string
    {
        return "Asset {$this->asset_tag}";
    }
}
