<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A ZKTeco attendance machine the app reads over the network (MachineSyncer, read only). Kept on Attendance >
 * Devices. The Comm Key is stored encrypted, never sent to the browser and masked in the activity log.
 */
class AttendanceDevice extends Model
{
    use LogsActivity;

    /** Logged as "changed", never the value (LogsActivity). */
    public const PRIVATE_FIELDS = ['comm_key'];

    protected $fillable = ['name', 'site', 'host', 'port', 'comm_key', 'is_active', 'auto_sync', 'timeout_seconds', 'lookback_days',
        'clock_warn_seconds', 'notes', 'model_name', 'serial', 'firmware', 'user_count', 'record_count', 'info_read_at'];

    protected $hidden = ['comm_key'];

    protected $attributes = ['port' => 4370, 'is_active' => true, 'auto_sync' => true, 'timeout_seconds' => 20, 'lookback_days' => 3,
        'clock_warn_seconds' => 60];

    protected function casts(): array
    {
        return ['comm_key' => 'encrypted', 'is_active' => 'boolean', 'auto_sync' => 'boolean', 'port' => 'integer', 'timeout_seconds' => 'integer',
            'lookback_days' => 'integer', 'clock_warn_seconds' => 'integer', 'user_count' => 'integer', 'record_count' => 'integer',
            'info_read_at' => 'datetime'];
    }

    public function syncs(): HasMany
    {
        return $this->hasMany(MachineSync::class);
    }

    public function activityLabel(): string
    {
        return "Attendance device {$this->name}";
    }
}
