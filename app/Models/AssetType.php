<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;

/** A kind of asset (Laptop, SIM card...), managed by HR on the Assets page. The prefix goes in new tags: BCC-LAP-001. */
class AssetType extends Model
{
    use LogsActivity;

    protected $fillable = ['name', 'prefix', 'is_active', 'sort_order'];

    protected $attributes = ['is_active' => true, 'sort_order' => 0];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return list<string> names of the types in use, in order */
    public static function activeNames(): array
    {
        return self::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->pluck('name')->all();
    }

    public function activityLabel(): string
    {
        return "Asset type {$this->name}";
    }
}
