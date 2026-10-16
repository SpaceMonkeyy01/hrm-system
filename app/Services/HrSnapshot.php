<?php

namespace App\Services;

use App\Models\Onboarding;
use Carbon\CarbonImmutable;

/**
 * Onboarding and document numbers for a set of current employees, as the sheet's "Dashboard" tab
 * should have counted them. Home and Overview both read this, so they always agree.
 *  - onboarding numbers count people in the onboarding process
 *  - document numbers read each document's own column (the sheet read CNIC for every row)
 */
class HrSnapshot
{
    /** @param  int[]  $currentIds */
    public static function build(array $currentIds, CarbonImmutable $today): array
    {
        $board = new StatusBoard($currentIds);

        // ---------------------------------------------------------------- onboarding
        $onboardings = Onboarding::query()->open()->whereIn('employee_id', $currentIds)
            ->with('employee:id,employee_code,full_name,department_id', 'employee.department:id,name')->get();

        $orientation = ['Not Started' => 0, 'In Progress' => 0, 'Complete' => 0];
        $onboardingStatus = ['Onboarding In Progress' => 0, 'Onboarding Complete' => 0];
        $legalPending = ['policy_acknowledgment' => 0, 'nda_signed' => 0, 'nca_signed' => 0];
        $legalAnyPending = 0;
        $probation = ['Pending' => 0, 'Pass' => 0, 'Extend' => 0, 'Fail' => 0, 'Not tracked' => 0];
        $endingSoon = [];
        $overdue = [];

        foreach ($onboardings as $o) {
            $id = $o->employee_id;
            $orientation[$board->orientation($id)]++;
            $onboardingStatus[$board->onboarding($id, $o->probation_result)]++;

            $docs = $board->values($id, 'document');
            $any = false;
            foreach (array_keys($legalPending) as $key) {
                if (! HrRules::isDone($docs[$key] ?? null, 'document')) {
                    $legalPending[$key]++;
                    $any = true;
                }
            }
            $legalAnyPending += $any ? 1 : 0;
            $probation[$o->probation_result ?? 'Not tracked']++;

            $end = $o->probation_end_date?->toDateString();
            $isOverdue = HrRules::isProbationOverdue($end, $o->probation_result, $today);
            $isEnding = HrRules::isProbationEndingSoon($end, $o->probation_result, $today);
            if ($isOverdue || $isEnding) {
                $row = ['id' => $id, 'code' => $o->employee->employee_code, 'name' => $o->employee->full_name,
                    'department' => $o->employee->department?->name, 'end' => $end];
                if ($isOverdue) {
                    $overdue[] = $row;
                } else {
                    $endingSoon[] = $row;
                }
            }
        }
        usort($overdue, fn ($a, $b) => strcmp($a['end'], $b['end']));
        usort($endingSoon, fn ($a, $b) => strcmp($a['end'], $b['end']));

        // ---------------------------------------------------------------- documents (every current employee)
        $documents = [];
        foreach ($board->items['document'] as $item) {
            $documents[$item->key] = ['key' => $item->key, 'label' => $item->label, 'yes' => 0, 'na' => 0, 'pending' => 0, 'no' => 0, 'blank' => 0];
        }
        $compliant = 0;
        foreach ($currentIds as $id) {
            $values = $board->values($id, 'document');
            foreach ($values as $key => $v) {
                $bucket = match (true) {
                    $v === null => 'blank',
                    $v === 'Yes' => 'yes',
                    $v === 'No' => 'no',
                    $v === 'Pending' => 'pending',
                    default => 'na',            // Not Required / Old employee / not available
                };
                $documents[$key][$bucket]++;
            }
            if (HrRules::documentStatus($values) === 'Complete') {
                $compliant++;
            }
        }

        return [
            'onboarding' => [
                'total' => $onboardings->count(),
                'orientation' => $orientation,
                'status' => $onboardingStatus,
                'legalPending' => $legalPending,
                'legalAnyPending' => $legalAnyPending,
                'probation' => $probation,
                'endingSoon' => $endingSoon,
                'overdue' => $overdue,
            ],
            'documents' => [
                'population' => count($currentIds),
                'compliant' => $compliant,
                'items' => array_values($documents),
            ],
        ];
    }
}
