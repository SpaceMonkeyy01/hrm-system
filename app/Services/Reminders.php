<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Offboarding;
use App\Models\Onboarding;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * What HR has to act on today, computed live from the data and never stored (like the statuses).
 * Used by the dashboard's "Needs attention" card and the daily email (php artisan hr:send-reminders).
 *
 * Numbers live in config hrm.reminders; date rules in HrRules; checklist values come from StatusBoard
 * (one query for everyone).
 */
class Reminders
{
    /** Reminder types, in the order the email groups them, with the heading HR sees. */
    public const TYPES = [
        'probation_overdue' => 'Probation past its end date',
        'probation_ending' => 'Probation ending soon',
        'checkin_missed' => 'Check-ins missed',
        'checkin_due' => 'Check-ins due',
        'documents_pending' => 'Documents missing',
        'offboarding_due' => 'Offboarding clearance due',
    ];

    /**
     * @return list<array{type: string, severity: 'overdue'|'due_soon', employee_id: int, employee_code: string,
     *     employee_name: string, due_date: string, days: int, message: string, link: string}>
     */
    public static function forDate(CarbonInterface $today): array
    {
        $today = CarbonImmutable::parse($today)->startOfDay();
        $cfg = config('hrm.reminders');

        $active = Employee::query()->where('status', 'active')
            ->get(['id', 'employee_code', 'full_name', 'start_date', 'status']);
        $onboardings = Onboarding::query()->whereHas('employee', fn ($q) => $q->where('status', 'active'))
            ->get(['id', 'employee_id', 'probation_end_date', 'probation_result'])->keyBy('employee_id');
        // The relation includes trashed employees (case history), so skip them explicitly.
        $offboardings = Offboarding::query()->where('status', 'in_progress')->whereNotNull('last_working_day')
            ->whereHas('employee', fn ($q) => $q->withoutTrashed()->where('status', 'offboarding'))
            ->with('employee:id,employee_code,full_name')
            ->get(['id', 'employee_id', 'last_working_day', 'status']);

        $board = new StatusBoard(array_values(array_unique([...$active->pluck('id')->all(), ...$offboardings->pluck('employee_id')->all()])));
        $onboardingItems = $board->items['onboarding']->keyBy('key');

        $out = [];
        $add = function (Employee $e, string $type, string $severity, string $due, string $message, string $link) use (&$out, $today) {
            $out[] = [
                'type' => $type,
                'severity' => $severity,
                'employee_id' => $e->id,
                'employee_code' => $e->employee_code,
                'employee_name' => $e->full_name,
                'due_date' => $due,
                'days' => HrRules::daysUntil($due, $today),
                'message' => $message,
                'link' => $link,
            ];
        };

        foreach ($active as $e) {
            if (! HrRules::isRecentStarter($e->start_date, $today)) {
                continue; // counted by documentsBacklog() instead
            }
            $o = $onboardings->get($e->id);

            // ---------------------------------------------------------------- probation
            $end = $o?->probation_end_date?->toDateString();
            if ($o && $end) {
                $days = HrRules::daysUntil($end, $today);
                if (HrRules::isProbationOverdue($end, $o->probation_result, $today)) {
                    $add($e, 'probation_overdue', 'overdue', $end,
                        'Probation ended '.self::when($days).' and still needs a decision', "/employees/{$e->id}/plan");
                } elseif (HrRules::isProbationEndingSoon($end, $o->probation_result, $today, (int) $cfg['probation_ending_days'])) {
                    $add($e, 'probation_ending', 'due_soon', $end, 'Probation ends '.self::when($days), "/employees/{$e->id}/plan");
                }
            }

            // ---------------------------------------------------------------- check-ins
            if ($o && $e->start_date && ! in_array($o->probation_result, ['Pass', 'Fail'], true)) {
                $values = $board->values($e->id, 'onboarding');
                foreach (array_keys($cfg['checkin_offsets']) as $key) {
                    $item = $onboardingItems->get($key);
                    if (! $item || HrRules::isDone($values[$key] ?? null, $item->option_set)) {
                        continue;
                    }
                    $due = HrRules::checkinDueDate($e->start_date, $key);
                    $severity = HrRules::dueSeverity($due, $today, (int) $cfg['checkin_due_days']);
                    if ($severity) {
                        $days = HrRules::daysUntil($due, $today);
                        $message = $severity === 'overdue'
                            ? "{$item->label} was due ".self::when($days)
                            : "{$item->label} is due ".self::when($days);
                        $add($e, $severity === 'overdue' ? 'checkin_missed' : 'checkin_due', $severity, $due, $message, "/employees/{$e->id}/plan");
                    }
                }
            }

            // ---------------------------------------------------------------- documents
            $due = HrRules::documentsDueDate($e->start_date);
            if ($due && $due <= $today->toDateString()) {
                $documents = $board->values($e->id, 'document');
                $missing = count(array_filter($documents, fn ($v) => ! HrRules::isDone($v, 'document')));
                // No active document items also reads as Pending; there is nothing to chase then, so skip it.
                if (HrRules::documentStatus($documents) === 'Pending' && $missing > 0) {
                    $started = -HrRules::daysUntil($e->start_date->toDateString(), $today);
                    $message = self::plural($missing, 'document').' still missing, started '.self::plural($started, 'day').' ago';
                    $days = HrRules::daysUntil($due, $today);
                    $add($e, 'documents_pending', $days < 0 ? 'overdue' : 'due_soon', $due, $message, "/employees/{$e->id}");
                }
            }
        }

        // ---------------------------------------------------------------- offboarding
        foreach ($offboardings as $case) {
            $last = $case->last_working_day->toDateString();
            $severity = HrRules::dueSeverity($last, $today, (int) $cfg['offboarding_days']);
            if (! $severity) {
                continue;
            }
            $open = count(array_filter($board->values($case->employee_id, 'offboarding'), fn ($v) => ! HrRules::isDone($v, 'clearance')));
            if ($open === 0) {
                continue;
            }
            $days = HrRules::daysUntil($last, $today);
            $message = ($days < 0 ? 'Last working day was ' : 'Last working day is ').self::when($days)
                .', '.self::plural($open, 'clearance item').' still open';
            $add($case->employee, 'offboarding_due', $severity, $last, $message, "/offboarding/{$case->id}");
        }

        usort($out, fn ($a, $b) => [$a['severity'] === 'overdue' ? 0 : 1, $a['due_date'], $a['employee_name'], $a['type']]
            <=> [$b['severity'] === 'overdue' ? 0 : 1, $b['due_date'], $b['employee_name'], $b['type']]);

        return $out;
    }

    /**
     * Active people who started before the recent-starter window and still have missing documents.
     * They get no per-person reminders; the email mentions the count once a week (documents_backlog_weekday).
     */
    public static function documentsBacklog(CarbonInterface $today): int
    {
        $today = CarbonImmutable::parse($today)->startOfDay();
        $older = Employee::query()->where('status', 'active')->whereNotNull('start_date')->get(['id', 'start_date'])
            ->reject(fn (Employee $e) => HrRules::isRecentStarter($e->start_date, $today));
        if ($older->isEmpty()) {
            return 0;
        }
        $board = new StatusBoard($older->pluck('id')->all());

        return $older->filter(function (Employee $e) use ($board) {
            $documents = $board->values($e->id, 'document');

            return HrRules::documentStatus($documents) === 'Pending'
                && count(array_filter($documents, fn ($v) => ! HrRules::isDone($v, 'document'))) > 0;
        })->count();
    }

    /** True on the weekday the documents backlog line goes in the email. */
    public static function isBacklogDay(CarbonInterface $today): bool
    {
        return $today->isoWeekday() === (int) config('hrm.reminders.documents_backlog_weekday', 1);
    }

    /** -3 => "3 days ago", -1 => "yesterday", 0 => "today", 1 => "tomorrow", 5 => "in 5 days" */
    private static function when(int $days): string
    {
        return match (true) {
            $days === 0 => 'today',
            $days === 1 => 'tomorrow',
            $days === -1 => 'yesterday',
            $days > 1 => "in $days days",
            default => -$days.' days ago',
        };
    }

    private static function plural(int $n, string $word): string
    {
        return $n.' '.($n === 1 ? $word : $word.'s');
    }
}
