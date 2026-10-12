<?php

namespace App\Services\Reports;

use App\Models\Review;
use App\Services\ReviewRules;

/** Finished performance reviews. Private: HR and admin only (ReportCatalog). */
class PerformanceReports
{
    public function outcomes(ReportFilters $f): ReportResult
    {
        $reviews = Review::query()->with(['cycle:id,name,period_end', 'employee:id,employee_code,full_name,department_id', 'employee.department:id,name'])
            ->where('status', 'completed')
            ->whereIn('employee_id', $f->employees()->select('id'))
            ->whereHas('cycle', fn ($q) => $q->whereYear('period_end', (string) $f->year))
            ->get();
        $bands = array_values(config('hrm.reviews.scale'));
        $tones = array_combine($bands, ['missing', 'pending', 'neutral', 'info', 'done']);
        $r = (new ReportResult)->column('employee', 'Employee', 'employee')->column('department', 'Department')->column('cycle', 'Cycle')
            ->column('overall', 'Overall', 'number')->column('band', 'Band', 'status', ['tones' => $tones])
            ->column('recommendation', 'Recommendation')->column('read', 'Read by them');
        foreach ($reviews as $rev) {
            $overall = $rev->overall();
            $r->rows[] = ['employee' => ReportResult::person($rev->employee), 'department' => $rev->employee->department?->name, 'cycle' => $rev->cycle->name,
                'overall' => $overall, 'band' => ReviewRules::band($overall), 'recommendation' => $rev->recommendation, 'read' => $rev->acknowledged_at ? 'Yes' : 'Not yet'];
        }
        usort($r->rows, fn ($a, $b) => ($b['overall'] ?? 0) <=> ($a['overall'] ?? 0) ?: strcmp($a['employee']['name'], $b['employee']['name']));
        $overalls = array_filter(array_column($r->rows, 'overall'), fn ($v) => $v !== null);
        $r->stat('Reviews done', count($r->rows))->stat('Average', $overalls ? round(array_sum($overalls) / count($overalls), 1) : 'n/a');
        foreach (array_count_values(array_filter(array_column($r->rows, 'recommendation'))) as $rec => $n) {
            $r->stat($rec, $n);
        }
        $r->note('Finished reviews in cycles whose period ends in '.$f->year.'. Overall is the average of HR\'s ratings, 1 to 5.');

        return $r;
    }
}
