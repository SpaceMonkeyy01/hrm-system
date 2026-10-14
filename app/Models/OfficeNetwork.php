<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One of the office's public internet addresses (an IP or a range): an app clock-in from it counts as office time. */
class OfficeNetwork extends Model
{
    use LogsActivity;

    protected $fillable = ['name', 'address', 'created_by'];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function activityLabel(): string
    {
        return "Office network {$this->name} ({$this->address})";
    }
}
