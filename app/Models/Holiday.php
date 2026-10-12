<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A public or company holiday. company_id null = every company. */
class Holiday extends Model
{
    use LogsActivity;

    protected $fillable = ['date', 'name', 'company_id'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function activityLabel(): string
    {
        return "Holiday {$this->name}";
    }
}
