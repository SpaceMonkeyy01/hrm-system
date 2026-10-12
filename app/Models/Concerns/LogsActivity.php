<?php

namespace App\Models\Concerns;

use App\Models\ActivityLog;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes a row to activity_log whenever the model is created, updated or deleted.
 * Stores only the fields that changed, as {"field": [old, new]}.
 * Date casts are logged as Y-m-d and datetime casts as ISO 8601 (CLAUDE.md rule 7), for old and new values alike.
 *
 * A model using this trait may define:
 *   activityEmployeeId(): ?int   -> which employee timeline the change belongs to
 *   activityLabel(): string      -> short human description, e.g. "NDA Signed"
 */
trait LogsActivity
{
    /** Fields never written to the log. */
    protected static array $activityIgnore = ['updated_at', 'created_at', 'remember_token', 'password'];

    public static function bootLogsActivity(): void
    {
        static::created(function (Model $m) {
            $attrs = [];
            foreach ($m->getAttributes() as $field => $value) {
                $attrs[$field] = static::activityValue($m, $field, $value);
            }
            static::writeActivity($m, 'created', static::cleanChanges($attrs, []));
        });

        static::updated(function (Model $m) {
            $changes = [];
            foreach ($m->getChanges() as $field => $new) {
                $changes[$field] = [static::activityValue($m, $field, $m->getOriginal($field)), static::activityValue($m, $field, $new)];
            }
            $changes = static::cleanChanges($changes, null);
            if ($changes) {
                static::writeActivity($m, 'updated', $changes);
            }
        });

        static::deleted(fn (Model $m) => static::writeActivity($m, 'deleted', null));
    }

    /** Normalise a raw attribute value for the log: dates as Y-m-d, timestamps as ISO 8601, everything else unchanged. */
    protected static function activityValue(Model $m, string $field, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }
        $cast = strtolower(explode(':', (string) ($m->getCasts()[$field] ?? ''), 2)[0]);
        $isDate = in_array($cast, ['date', 'immutable_date'], true);
        $isDateTime = in_array($cast, ['datetime', 'immutable_datetime', 'timestamp'], true);
        if (! $isDate && ! $isDateTime) {
            return $value;
        }
        try {
            $d = $value instanceof DateTimeInterface ? CarbonImmutable::instance($value) : CarbonImmutable::parse((string) $value);
        } catch (\Throwable) {
            return $value;
        }

        return $isDate ? $d->toDateString() : $d->toIso8601String();
    }

    protected static function cleanChanges(array $data, ?array $_): array
    {
        foreach (static::$activityIgnore as $f) {
            unset($data[$f]);
        }
        // Private fields: keep the fact that they changed, never the values (viewers can read the log).
        foreach (defined(static::class.'::PRIVATE_FIELDS') ? static::PRIVATE_FIELDS : [] as $f) {
            if (array_key_exists($f, $data)) {
                $data[$f] = is_array($data[$f]) ? ['hidden', 'hidden'] : (in_array($data[$f], [null, ''], true) ? $data[$f] : 'hidden');
            }
        }

        return $data;
    }

    protected static function writeActivity(Model $m, string $event, ?array $changes): void
    {
        if (ActivityLog::$muted) {
            return;
        }

        ActivityLog::create([
            'user_id' => auth()->id(),
            'employee_id' => method_exists($m, 'activityEmployeeId') ? $m->activityEmployeeId() : null,
            'subject_type' => class_basename($m),
            'subject_id' => $m->getKey(),
            'event' => $event,
            'description' => method_exists($m, 'activityLabel') ? $m->activityLabel() : null,
            'changes' => $changes ?: null,
        ]);
    }
}
