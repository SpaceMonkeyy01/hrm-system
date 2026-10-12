<?php

namespace Tests\Unit;

use App\Services\HrRules;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/** Regression tests for review findings R2 and R6 (probation rules). */
class HrRulesRegressionTest extends TestCase
{
    private CarbonImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->today = CarbonImmutable::parse('2026-10-08');
    }

    /** R2: an extended probation is still open, so it is flagged once its (new) end date passes. */
    public function test_extend_is_overdue_like_pending(): void
    {
        $this->assertTrue(HrRules::isProbationOverdue('2026-10-07', 'Extend', $this->today));
        $this->assertFalse(HrRules::isProbationOverdue('2026-10-08', 'Extend', $this->today));
        $this->assertFalse(HrRules::isProbationOverdue('2026-10-07', 'Fail', $this->today));
        $this->assertFalse(HrRules::isProbationOverdue(null, 'Extend', $this->today));
    }

    /** R6: "ending soon" lives in HrRules: open result, end between today and today + 7 inclusive. */
    public function test_ending_soon(): void
    {
        foreach (['Pending', 'Extend'] as $open) {
            $this->assertTrue(HrRules::isProbationEndingSoon('2026-10-08', $open, $this->today), "$open today");
            $this->assertTrue(HrRules::isProbationEndingSoon('2026-10-15', $open, $this->today), "$open +7");
            $this->assertFalse(HrRules::isProbationEndingSoon('2026-10-16', $open, $this->today), "$open +8");
            $this->assertFalse(HrRules::isProbationEndingSoon('2026-10-07', $open, $this->today), "$open overdue is not ending soon");
        }
        $this->assertFalse(HrRules::isProbationEndingSoon('2026-10-10', 'Pass', $this->today));
        $this->assertFalse(HrRules::isProbationEndingSoon('2026-10-10', null, $this->today));
        $this->assertFalse(HrRules::isProbationEndingSoon(null, 'Pending', $this->today));
        $this->assertTrue(HrRules::isProbationEndingSoon('2026-10-20', 'Pending', $this->today, 14));
    }

    /** R2: changing to Extend needs a later end date; other changes don't. */
    public function test_extension_needs_a_later_end_date(): void
    {
        $this->assertFalse(HrRules::isValidExtension('Pending', '2026-04-01', 'Extend', null));
        $this->assertFalse(HrRules::isValidExtension('Pending', '2026-04-01', 'Extend', '2026-04-01'));
        $this->assertFalse(HrRules::isValidExtension('Pending', '2026-04-01', 'Extend', '2026-03-01'));
        $this->assertTrue(HrRules::isValidExtension('Pending', '2026-04-01', 'Extend', '2026-05-01'));
        $this->assertTrue(HrRules::isValidExtension(null, null, 'Extend', '2026-05-01'));
        $this->assertFalse(HrRules::isValidExtension(null, null, 'Extend', null));
        $this->assertTrue(HrRules::isValidExtension('Extend', '2026-04-01', 'Extend', null), 'already extended: no new date needed');
        $this->assertTrue(HrRules::isValidExtension('Pending', '2026-04-01', 'Pass', null));
    }
}
