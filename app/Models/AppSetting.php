<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;

/**
 * A setting HR changes in the app, stored as JSON by key. Read with AppSetting::get('probation_days', default):
 * a missing key gives the default (config/hrm.php), so a fresh install behaves exactly as before.
 * Changes go in the activity log from put() (not LogsActivity: the log's subject_id is a number, this key is text).
 */
class AppSetting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'json'];
    }

    /** @var array<string, mixed>|null all settings, loaded once per request */
    private static ?array $cache = null;

    public static function get(string $key, mixed $default = null): mixed
    {
        if (self::$cache === null) {
            try {
                self::$cache = static::query()->pluck('value', 'key')->map(fn ($v) => is_string($v) ? json_decode($v, true) : $v)->all();
            } catch (QueryException) {
                return $default;   // table not there yet (before the migration runs): behave as before
            }
        }

        return self::$cache[$key] ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        $old = static::get($key);
        static::updateOrCreate(['key' => $key], ['value' => $value]);
        self::$cache = null;
        if ($old !== $value && ! ActivityLog::$muted) {
            ActivityLog::create(['user_id' => auth()->id(), 'subject_type' => 'AppSetting', 'subject_id' => null,
                'event' => 'updated', 'description' => "Setting $key", 'changes' => [$key => [$old, $value]]]);
        }
    }

    /** Forget what was loaded (tests, and after a change). */
    public static function flush(): void
    {
        self::$cache = null;
    }
}
