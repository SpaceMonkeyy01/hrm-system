<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    protected $table = 'activity_log';

    public const UPDATED_AT = null;

    /** The importer sets this to true so 5,000 imported cells don't create 5,000 log rows. */
    public static bool $muted = false;

    protected $fillable = ['user_id', 'employee_id', 'subject_type', 'subject_id', 'event', 'description', 'changes'];

    protected function casts(): array
    {
        return ['changes' => 'array', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }
}
