<?php

namespace App\Services;

use App\Models\AppSetting;

/**
 * HR's leave numbers (Leave > Leave types, "Deductions and short leave"), stored in app_settings.
 * Defaults are how HR's own sheet counted (BCC Leave balance, 2026): absent 1 day, late sign-in or early sign-off
 * a quarter day each, short leave a quarter day. Deductions from attendance only count from "deductions_from"
 * (empty = off), so days already counted in an uploaded sheet aren't counted twice.
 */
class LeaveSettings
{
    public const DEFAULTS = ['absent_days' => 1.0, 'late_early_days' => 0.25, 'short_leave_days' => 0.25, 'deductions_from' => null];

    /** @return array{absent_days: float, late_early_days: float, short_leave_days: float, deductions_from: ?string} */
    public static function all(): array
    {
        $out = [];
        foreach (self::DEFAULTS as $key => $default) {
            $v = AppSetting::get("leave.$key", $default);
            $out[$key] = $key === 'deductions_from' ? ($v ?: null) : (float) $v;
        }

        return $out;
    }

    public static function get(string $key): mixed
    {
        return self::all()[$key];
    }
}
