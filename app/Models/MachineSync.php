<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One attempt to read the attendance machine (an operations log, not an HR record, so not in the activity log). */
class MachineSync extends Model
{
    public $timestamps = false;

    protected $fillable = ['trigger', 'user_id', 'started_at', 'finished_at', 'ok', 'machine_records', 'checked', 'added',
        'clock_offset', 'error', 'attendance_import_id'];

    protected $attributes = ['checked' => 0, 'added' => 0];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime', 'ok' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
