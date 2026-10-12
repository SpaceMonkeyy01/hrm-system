<?php

namespace App\Services\Reports;

use App\Models\Onboarding;
use App\Services\PeopleStats;
use Carbon\CarbonImmutable;

/** Headcount, movement, tenure and probation. Counting rules: PeopleStats. */
class PeopleReports
{
    public function headcount(ReportFilters $f): ReportResult
    {
        $stats = new PeopleStats($f->company, $f->department);
        $on = $stats->onBooksOn($f->date);
        $total = $on->count();
        $r = (new ReportResult)->column('company', 'Company')->column('department', 'Department')
            ->column('headcount', 'Headcount', 'number')->column('share', 'Share', 'percent')
            ->column('full_time', 'Full time', 'number')->column('other', 'Other types', 'number');

        foreach ($on->groupBy(fn ($e) => ($e->company?->name ?? 'No company').'|'.($e->department?->name ?? 'No department')) as $key => $group) {
            [$company, $department] = explode('|', $key);
            $fullTime = $group->filter(fn ($e) => strcasecmp((string) $e->employment_type, 'Full Time') === 0)->count();
            $r->rows[] = ['company' => $company, 'department' => $department, 'headcount' => $group->count(),
                'share' => round($group->count() / max($total, 1) * 100, 1), 'full_time' => $fullTime, 'other' => $group->count() - $fullTime];
        }
        usort($r->rows, fn ($a, $b) => [$a['company'], -$a['headcount']] <=> [$b['company'], -$b['headcount']]);
        $r->stat('Headcount', $total)->stat('Departments', count($r->rows));
        $r->note('People on the books on '.CarbonImmutable::parse($f->date)->format('j M Y').': started by then and not yet left.');

        return $r;
    }

    public function movement(ReportFilters $f): ReportResult
    {
        $stats = new PeopleStats($f->company, $f->department);
        $r = (new ReportResult)->column('date', 'Date', 'date')->column('change', 'Change', 'status', ['tones' => ['Joined' => 'done', 'Left' => 'missing']])
            ->column('employee', 'Employee', 'employee')->column('company', 'Company')->column('department', 'Department')
            ->column('role', 'Role')->column('employment_type', 'Employment type');
        $row = fn ($e, $change, $date) => ['date' => $date, 'change' => $change, 'employee' => ReportResult::person($e),
            'company' => $e->company?->name, 'department' => $e->department?->name, 'role' => $e->role_title, 'employment_type' => $e->employment_type];
        $joiners = $stats->joiners($f->from, $f->to);
        $leavers = $stats->leavers($f->from, $f->to);
        foreach ($joiners as $e) {
            $r->rows[] = $row($e, 'Joined', $e->start_date->toDateString());
        }
        foreach ($leavers as $e) {
            $r->rows[] = $row($e, 'Left', $e->exit_date->toDateString());
        }
        usort($r->rows, fn ($a, $b) => [$a['date'], $a['employee']['name']] <=> [$b['date'], $b['employee']['name']]);

        $opening = $stats->onBooksOn(CarbonImmutable::parse($f->from)->subDay()->toDateString())->count();
        $closing = $stats->onBooksOn($f->to)->count();
        $turnover = PeopleStats::turnover($leavers->count(), $opening, $closing);
        $r->stat('Joined', $joiners->count(), 'done')->stat('Left', $leavers->count(), $leavers->count() ? 'missing' : null)
            ->stat('Net change', sprintf('%+d', $joiners->count() - $leavers->count()))
            ->stat('Turnover', $turnover === null ? 'n/a' : "$turnover%");

        return $r;
    }

    public function turnover(ReportFilters $f): ReportResult
    {
        $stats = new PeopleStats($f->company, $f->department);
        $r = (new ReportResult)->column('label', 'Month')->column('opening', 'Start of month', 'number')->column('joiners', 'Joined', 'number')
            ->column('leavers', 'Left', 'number')->column('closing', 'End of month', 'number')->column('turnover', 'Turnover', 'percent');
        $r->rows = $stats->monthly($f->monthStart(), 12, $f->today);
        $joined = array_sum(array_column($r->rows, 'joiners'));
        $left = array_sum(array_column($r->rows, 'leavers'));
        $yearly = PeopleStats::turnover($left, $r->rows[0]['opening'], end($r->rows)['closing']);
        $r->stat('Joined in 12 months', $joined, 'done')->stat('Left in 12 months', $left, $left ? 'missing' : null)
            ->stat('12-month turnover', $yearly === null ? 'n/a' : "$yearly%");
        $r->note('Turnover = people who left / average of the opening and closing headcount. People with no start or exit date can\'t be placed in a month.');

        return $r;
    }

    public function tenure(ReportFilters $f): ReportResult
    {
        $today = $f->today;
        $bands = ['Under 6 months' => 0, '6-12 months' => 0, '1-2 years' => 0, '2-5 years' => 0, '5+ years' => 0, 'No start date' => 0];
        $r = (new ReportResult)->column('employee', 'Employee', 'employee')->column('company', 'Company')->column('department', 'Department')
            ->column('role', 'Role')->column('start_date', 'Start date', 'date')->column('years', 'Years', 'number')->column('band', 'Band');
        $years = [];
        foreach ($f->current()->with(['company:id,name', 'department:id,name'])->orderBy('start_date')->get() as $e) {
            $y = $e->start_date ? round($e->start_date->diffInDays($today) / 365.25, 1) : null;
            $band = match (true) {
                $y === null => 'No start date',
                $y < 0.5 => 'Under 6 months',
                $y < 1 => '6-12 months',
                $y < 2 => '1-2 years',
                $y < 5 => '2-5 years',
                default => '5+ years',
            };
            $bands[$band]++;
            if ($y !== null) {
                $years[] = $y;
            }
            $r->rows[] = ['employee' => ReportResult::person($e), 'company' => $e->company?->name, 'department' => $e->department?->name,
                'role' => $e->role_title, 'start_date' => $e->start_date?->toDateString(), 'years' => $y, 'band' => $band];
        }
        $r->stat('Average', $years ? round(array_sum($years) / count($years), 1).' years' : 'n/a');
        foreach ($bands as $band => $n) {
            if ($n) {
                $r->stat($band, $n);
            }
        }

        return $r;
    }

    public function probation(ReportFilters $f): ReportResult
    {
        $tones = ['Pending' => 'pending', 'Pass' => 'done', 'Extend' => 'info', 'Fail' => 'missing'];
        $r = (new ReportResult)->column('employee', 'Employee', 'employee')->column('department', 'Department')
            ->column('start_date', 'Start date', 'date')->column('end', 'Probation end', 'date')
            ->column('result', 'Result', 'status', ['tones' => $tones])->column('overdue', 'Overdue');
        $ids = $f->employees()->select('id');
        $rows = Onboarding::query()->with(['employee:id,employee_code,full_name,department_id,start_date', 'employee.department:id,name'])
            ->whereIn('employee_id', $ids)->whereBetween('probation_end_date', [$f->from, $f->to])->orderBy('probation_end_date')->get();
        foreach ($rows as $o) {
            $end = $o->probation_end_date->toDateString();
            $r->rows[] = ['employee' => ReportResult::person($o->employee), 'department' => $o->employee->department?->name,
                'start_date' => $o->employee->start_date?->toDateString(), 'end' => $end, 'result' => $o->probation_result ?? 'Pending',
                'overdue' => ($o->probation_result ?? 'Pending') === 'Pending' && $end < $f->today->toDateString() ? 'Yes' : ''];
        }
        $count = array_count_values(array_column($r->rows, 'result'));
        foreach ($tones as $result => $tone) {
            $r->stat($result, $count[$result] ?? 0, ($count[$result] ?? 0) ? $tone : null);
        }
        $r->note('Everyone whose probation ends between the two dates.');

        return $r;
    }
}
