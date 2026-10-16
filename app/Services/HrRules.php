<?php

namespace App\Services;

use App\Models\AppSetting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The spreadsheet formulas, as plain PHP. Every computed status in the app comes from here.
 *
 * Sheet -> rule
 *   Onboarding_Tracker!U   (Orientation_Status)        -> orientationStatus()
 *   Onboarding_Document_Checklist!W (Status)          -> documentStatus()
 *   Onboarding_Tracker!AE  (Onboarding Status)         -> onboardingStatus()
 *   Onboarding_Tracker!I   (Probation_End_Date = H+90) -> probationEnd()
 */
class HrRules
{
    /** Checklist keys the rules below depend on. They can be renamed in Settings but not switched off. */
    public const REQUIRED_KEYS = [
        'onboarding' => ['orientation_1', 'orientation_2', 'orientation_3', 'day30_checkin', 'day60_checkin', 'day90_evaluation'],
        'document' => ['nda_signed', 'nca_signed', 'policy_acknowledgment'],
        'offboarding' => [],
    ];

    /** Validation message when Extend is chosen without a later end date. */
    public const EXTEND_NEEDS_LATER_END = 'Set the new probation end date (later than the current one).';

    /** @return array<int, array{value: string, tone: string, done: bool}> */
    public static function options(string $optionSet): array
    {
        return config("hrm.option_sets.$optionSet", []);
    }

    public static function isAllowed(?string $status, string $optionSet): bool
    {
        return $status === null || in_array($status, array_column(self::options($optionSet), 'value'), true);
    }

    /** Is this status "complete" for its option set? Blank is never done. */
    public static function isDone(?string $status, string $optionSet): bool
    {
        foreach (self::options($optionSet) as $opt) {
            if ($opt['value'] === $status) {
                return $opt['done'];
            }
        }

        return false;
    }

    /**
     * Sheet U: =IF(AND(R="Done",S="Done",T="Done"),"Complete",IF(OR(R="Done",S="Done",T="Done"),"In Progress","Not Started"))
     *
     * @param  array<string, ?string>  $onboarding  onboarding-list statuses keyed by item key
     */
    public static function orientationStatus(array $onboarding): string
    {
        $done = 0;
        foreach (['orientation_1', 'orientation_2', 'orientation_3'] as $key) {
            if (($onboarding[$key] ?? null) === 'Done') {
                $done++;
            }
        }

        return match (true) {
            $done === 3 => 'Complete',
            $done > 0 => 'In Progress',
            default => 'Not Started',
        };
    }

    /**
     * Sheet W: Pending if any document is "No", "Pending" or blank; otherwise Complete.
     *
     * @param  array<string, ?string>  $documents  statuses of all ACTIVE document items, keyed by item key
     */
    public static function documentStatus(array $documents): string
    {
        if ($documents === []) {
            return 'Pending';
        }
        foreach ($documents as $status) {
            if (! self::isDone($status, 'document')) {
                return 'Pending';
            }
        }

        return 'Complete';
    }

    /**
     * Sheet AE: Complete when orientation is Complete, Policy/NDA/NCA are signed, the 30/60/90-day
     * check-ins are Yes and probation is Pass. In the sheet, Policy/NDA/NCA were typed twice
     * (tracker V..X and checklist L, M, T); here they are read from the document checklist only.
     */
    public static function onboardingStatus(array $onboarding, array $documents, ?string $probationResult): string
    {
        $complete = self::orientationStatus($onboarding) === 'Complete'
            && self::isDone($documents['policy_acknowledgment'] ?? null, 'document')
            && self::isDone($documents['nda_signed'] ?? null, 'document')
            && self::isDone($documents['nca_signed'] ?? null, 'document')
            && ($onboarding['day30_checkin'] ?? null) === 'Yes'
            && ($onboarding['day60_checkin'] ?? null) === 'Yes'
            && ($onboarding['day90_evaluation'] ?? null) === 'Yes'
            && $probationResult === 'Pass';

        return $complete ? 'Onboarding Complete' : 'Onboarding In Progress';
    }

    /** Sheet I: =IF(H="","",H+90) */
    public static function probationEnd(?CarbonInterface $start): ?string
    {
        return $start ? CarbonImmutable::parse($start)->addDays(self::probationDays())->toDateString() : null;
    }

    /** Probation length in days: HR's setting (Settings > Checklists), else config hrm.probation_days. */
    public static function probationDays(): int
    {
        return (int) AppSetting::get('probation_days', config('hrm.probation_days', 90));
    }

    /** @return array<string, int> check-in item key => days after the start date (HR's setting over the config) */
    public static function checkinOffsets(): array
    {
        $saved = AppSetting::get('checkin_offsets', []);

        return array_map('intval', array_merge(config('hrm.reminders.checkin_offsets', []), array_intersect_key(is_array($saved) ? $saved : [], config('hrm.reminders.checkin_offsets', []))));
    }

    /** Is the probation still open (no final decision yet)? Pending and Extend both count, see config hrm.probation_open_results. */
    public static function isProbationOpen(?string $result): bool
    {
        return $result !== null && in_array($result, config('hrm.probation_open_results', ['Pending', 'Extend']), true);
    }

    /**
     * Sheet conditional format on column I: red when the end date has passed and the result is still open
     * (Pending, or Extend past its new end date).
     */
    public static function isProbationOverdue(?string $endDate, ?string $result, ?CarbonInterface $today = null): bool
    {
        $today ??= CarbonImmutable::today();

        return $endDate !== null && self::isProbationOpen($result) && $endDate < $today->toDateString();
    }

    /** Probation still open and ending between today and today + $days (inclusive). Default: config hrm.reminders.probation_ending_days. */
    public static function isProbationEndingSoon(?string $endDate, ?string $result, ?CarbonInterface $today = null, ?int $days = null): bool
    {
        $today = CarbonImmutable::parse($today ?? CarbonImmutable::today());
        $days ??= (int) config('hrm.reminders.probation_ending_days', 7);

        return $endDate !== null && self::isProbationOpen($result)
            && $endDate >= $today->toDateString() && $endDate <= $today->addDays($days)->toDateString();
    }

    /**
     * Due date of a check-in: start date + its offset (checkinOffsets(): day30_checkin => 30, day60_checkin => 60,
     * day90_evaluation => 90 unless HR changed them). Null without a start date or for an unknown key.
     */
    public static function checkinDueDate(?CarbonInterface $start, string $key): ?string
    {
        $offset = self::checkinOffsets()[$key] ?? null;

        return $start && $offset !== null ? CarbonImmutable::parse($start)->addDays((int) $offset)->toDateString() : null;
    }

    /**
     * Started within config hrm.reminders.recent_start_days (inclusive), or no start date known.
     * Only these people get per-person reminders; years-old gaps from the import would drown the rest.
     */
    public static function isRecentStarter(?CarbonInterface $start, CarbonInterface $today): bool
    {
        return $start === null || self::daysUntil($start->toDateString(), $today) >= -(int) config('hrm.reminders.recent_start_days', 180);
    }

    /** Date documents should all be in: start date + config hrm.reminders.documents_grace_days. */
    public static function documentsDueDate(?CarbonInterface $start): ?string
    {
        return $start ? CarbonImmutable::parse($start)->addDays((int) config('hrm.reminders.documents_grace_days', 14))->toDateString() : null;
    }

    /** Whole calendar days from $today to $date (negative = $date has passed). */
    public static function daysUntil(string $date, CarbonInterface $today): int
    {
        return (int) round(CarbonImmutable::parse($today)->startOfDay()->diffInDays(CarbonImmutable::parse($date)->startOfDay(), false));
    }

    /**
     * Reminder severity for a due date: 'overdue' once it has passed, 'due_soon' from $days before it
     * up to the day itself (inclusive), otherwise null (nothing to remind about yet).
     *
     * @return 'overdue'|'due_soon'|null
     */
    public static function dueSeverity(?string $dueDate, CarbonInterface $today, int $days): ?string
    {
        if ($dueDate === null) {
            return null;
        }
        $today = CarbonImmutable::parse($today);

        return match (true) {
            $dueDate < $today->toDateString() => 'overdue',
            $dueDate <= $today->addDays($days)->toDateString() => 'due_soon',
            default => null,
        };
    }

    /**
     * ARCH §4 "Pending -> Extend : move the end date". Changing the result TO Extend needs a new end date
     * later than the current one. Staying on Extend, or any other result, has no extra requirement.
     */
    public static function isValidExtension(?string $oldResult, ?string $oldEnd, ?string $newResult, ?string $newEnd): bool
    {
        if ($newResult !== 'Extend' || $oldResult === 'Extend') {
            return true;
        }
        if ($newEnd === null || $newEnd === '') {
            return false;
        }
        $newEnd = CarbonImmutable::parse($newEnd)->toDateString();

        return $oldEnd === null || $newEnd > $oldEnd;
    }
}
