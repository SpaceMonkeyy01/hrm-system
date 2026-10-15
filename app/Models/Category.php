<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** A tag on employees ("Google Ads Neon", "Etsy BS"); several per person. Kept in Settings > Structure. */
class Category extends Model
{
    protected $fillable = ['name'];

    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'employee_category');
    }
}
