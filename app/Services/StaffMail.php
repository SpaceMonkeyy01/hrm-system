<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\User;
use App\Notifications\StaffNotice;
use Illuminate\Support\Facades\Notification;

/**
 * Emails a member of staff that something is waiting on their My page: a letter, something to acknowledge, or HR's
 * answer to a request. Only people with an active login are emailed. A mail failure never stops what HR was doing:
 * it is reported to the log and the action still saves.
 */
class StaffMail
{
    /** Whether the person has an active login (so an email would reach them). */
    public static function reachable(int $employeeId): bool
    {
        return User::query()->where('employee_id', $employeeId)->where('is_active', true)->exists();
    }

    /**
     * @param  list<string>  $lines
     * @return bool  whether an email went out
     */
    public static function send(Employee|int $employee, string $subject, array $lines, string $path, string $button = 'Open My page'): bool
    {
        $users = User::query()->where('employee_id', $employee instanceof Employee ? $employee->id : $employee)->where('is_active', true)->get();
        if ($users->isEmpty()) {
            return false;
        }
        try {
            Notification::send($users, new StaffNotice($subject, $lines, $path, $button));

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    /** " Ali was emailed." / "" for a success message. */
    public static function note(bool $sent, string $name): string
    {
        return $sent ? " $name was emailed." : '';
    }

    /** "22 Oct" or "22 Oct to 24 Oct" */
    public static function dates(\DateTimeInterface $from, \DateTimeInterface $to): string
    {
        $a = $from->format('j M Y');
        $b = $to->format('j M Y');

        return $a === $b ? $a : "$a to $b";
    }

    /** HR's note as a line, when there is one. */
    public static function hrNote(?string $note): array
    {
        return $note ? ["HR's note: $note"] : [];
    }
}
