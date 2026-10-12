<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** A piece of company property. Who has it: the assignment without a return date (current()). */
class Asset extends Model
{
    use LogsActivity;

    protected $fillable = ['asset_tag', 'type', 'name', 'serial_number', 'status', 'purchase_date', 'notes'];

    protected $attributes = ['status' => 'in_stock'];

    protected function casts(): array
    {
        return ['purchase_date' => 'date:Y-m-d'];
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
