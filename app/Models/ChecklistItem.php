<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use App\Services\HrRules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChecklistItem extends Model
{
    use LogsActivity;

    public const LISTS = ['onboarding', 'document', 'offboarding'];

    protected $fillable = ['list', 'key', 'label', 'option_set', 'description', 'sort_order', 'is_active'];

    protected $attributes = ['is_active' => true, 'sort_order' => 0];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(ChecklistEntry::class);
    }

    public function scopeOfList(Builder $q, string $list): Builder
    {
        return $q->where('list', $list)->orderBy('sort_order')->orderBy('id');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    /** Values this item accepts, e.g. ['Yes', 'No']. */
    public function allowedValues(): array
    {
        return array_column(HrRules::options($this->option_set), 'value');
    }

    public function activityLabel(): string
    {
        return "Checklist item {$this->label}";
    }
}
