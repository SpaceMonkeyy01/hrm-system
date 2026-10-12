<?php

namespace Tests\Unit;

use App\Services\HrRules;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/** Date helpers behind the reminders (config hrm.reminders). */
class ReminderRulesTest extends TestCase
{
    public function test_checkin_due_date_is_start_plus_offset(): void
    {
        $start = CarbonImmutable::parse('2026-07-01');
        $this->assertSame('2026-07-31', HrRules::checkinDueDate($start, 'day30_checkin'));
        $this->assertSame('2026-08-30', HrRules::checkinDueDate($start, 'day60_checkin'));
        $this->assertSame('2026-09-29', HrRules::checkinDueDate($start, 'day90_evaluation'));
        $this->assertNull(HrRules::checkinDueDate(null, 'day30_checkin'));
        $this->assertNull(HrRules::checkinDueDate($start, 'email_created'));
    }

    public function test_documents_due_date_uses_the_grace_days(): void
    {
        $this->assertSame('2026-07-15', HrRules::documentsDueDate(CarbonImmutable::parse('2026-07-01')));
        config(['hrm.reminders.documents_grace_days' => 30]);
        $this->assertSame('2026-07-31', HrRules::documentsDueDate(CarbonImmutable::parse('2026-07-01')));
    }

    public function test_due_severity_and_days_until(): void
    {
        $today = CarbonImmutable::parse('2026-10-08');
        $this->assertSame('overdue', HrRules::dueSeverity('2026-10-07', $today, 3));
        $this->assertSame('due_soon', HrRules::dueSeverity('2026-10-08', $today, 3));
        $this->assertSame('due_soon', HrRules::dueSeverity('2026-10-11', $today, 3));
        $this->assertNull(HrRules::dueSeverity('2026-10-12', $today, 3));
        $this->assertNull(HrRules::dueSeverity(null, $today, 3));

        $this->assertSame(-1, HrRules::daysUntil('2026-10-07', $today));
        $this->assertSame(0, HrRules::daysUntil('2026-10-08', $today->setTime(23, 59)));
        $this->assertSame(31, HrRules::daysUntil('2026-11-08', $today));
    }

    public function test_ending_soon_window_comes_from_config(): void
    {
        $today = CarbonImmutable::parse('2026-10-08');
        $this->assertFalse(HrRules::isProbationEndingSoon('2026-10-18', 'Pending', $today));
        config(['hrm.reminders.probation_ending_days' => 10]);
        $this->assertTrue(HrRules::isProbationEndingSoon('2026-10-18', 'Pending', $today));
    }
}
