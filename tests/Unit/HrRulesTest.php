<?php

namespace Tests\Unit;

use App\Services\HrRules;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/** The sheet formulas, re-checked as code. */
class HrRulesTest extends TestCase
{
    public function test_orientation_status_matches_sheet_column_u(): void
    {
        $this->assertSame('Complete', HrRules::orientationStatus(['orientation_1' => 'Done', 'orientation_2' => 'Done', 'orientation_3' => 'Done']));
        $this->assertSame('In Progress', HrRules::orientationStatus(['orientation_1' => 'Done', 'orientation_2' => 'Not Started']));
        $this->assertSame('Not Started', HrRules::orientationStatus(['orientation_1' => 'Not Started']));
        $this->assertSame('Not Started', HrRules::orientationStatus([]));
    }

    public function test_document_status_matches_sheet_column_w(): void
    {
        $done = ['a' => 'Yes', 'b' => 'Not Required', 'c' => 'Old employee', 'd' => 'not available'];
        $this->assertSame('Complete', HrRules::documentStatus($done));
        $this->assertSame('Pending', HrRules::documentStatus($done + ['e' => 'No']));
        $this->assertSame('Pending', HrRules::documentStatus($done + ['e' => 'Pending']));
        $this->assertSame('Pending', HrRules::documentStatus($done + ['e' => null]), 'blank counts as Pending, like the sheet');
        $this->assertSame('Pending', HrRules::documentStatus([]));
    }

    public function test_onboarding_status_matches_sheet_column_ae(): void
    {
        $onb = ['orientation_1' => 'Done', 'orientation_2' => 'Done', 'orientation_3' => 'Done',
            'day30_checkin' => 'Yes', 'day60_checkin' => 'Yes', 'day90_evaluation' => 'Yes'];
        $docs = ['policy_acknowledgment' => 'Yes', 'nda_signed' => 'Yes', 'nca_signed' => 'Not Required'];

        $this->assertSame('Onboarding Complete', HrRules::onboardingStatus($onb, $docs, 'Pass'));
        $this->assertSame('Onboarding In Progress', HrRules::onboardingStatus($onb, $docs, 'Extend'));
        $this->assertSame('Onboarding In Progress', HrRules::onboardingStatus($onb, ['nda_signed' => 'No'] + $docs, 'Pass'));
        $this->assertSame('Onboarding In Progress', HrRules::onboardingStatus(['day90_evaluation' => 'No'] + $onb, $docs, 'Pass'));
    }

    public function test_probation_end_is_start_plus_90_days(): void
    {
        $this->assertSame('2026-04-14', HrRules::probationEnd(CarbonImmutable::parse('2026-01-14')));
        $this->assertSame('2017-04-01', HrRules::probationEnd(CarbonImmutable::parse('2017-01-01')));
        $this->assertNull(HrRules::probationEnd(null));
    }

    public function test_overdue_only_when_pending_and_past(): void
    {
        $today = CarbonImmutable::parse('2026-10-08');
        $this->assertTrue(HrRules::isProbationOverdue('2026-10-07', 'Pending', $today));
        $this->assertFalse(HrRules::isProbationOverdue('2026-10-08', 'Pending', $today));
        $this->assertFalse(HrRules::isProbationOverdue('2026-10-07', 'Pass', $today));
        $this->assertFalse(HrRules::isProbationOverdue('2026-10-07', null, $today));
    }

    public function test_is_done_per_option_set(): void
    {
        $this->assertTrue(HrRules::isDone('Done', 'session'));
        $this->assertFalse(HrRules::isDone('Not Started', 'session'));
        $this->assertTrue(HrRules::isDone('Not Required', 'clearance'));
        $this->assertFalse(HrRules::isDone(null, 'yes_no'));
        $this->assertFalse(HrRules::isDone('Maybe', 'yes_no'));
    }
}
