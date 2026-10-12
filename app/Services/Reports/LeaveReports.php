<?php

namespace App\Services\Reports;

use App\Models\Holiday;
use App\Models\LeaveRecord;

/** Leave HR recorded (approved in HR.my) and the holiday calendar. */
class LeaveReports
{
    public function taken(ReportFilters $f): ReportResult
    {
        $records = LeaveRecord::query()->with(['employee:id,employee_code,full_name,department_id', 'employee.department:id,name', 'type:id,name'])
            ->whereIn('employee_id', $f->employees()->select('id'))
            ->whereBetween('start_date', [$f->from, $f->to])->get();
        $r = (new ReportResult)->column('employee', 'Employee', 'employee')->column('department', 'Department')
            ->column('type', 'Leave type')->column('records', 'Times', 'number')->column('days', 'Days', 'number');
        foreach ($records->groupBy(fn ($l) => $l->employee_id.'|'.$l->type?->name) as $group) {
            $first = $group->first();
            $r->rows[] = ['employee' => ReportResult::person($first->employee), 'department' => $first->employee->department?->name,
                'type' => $first->type?->name ?? 'Leave', 'records' => $group->count(), 'days' => round($group->sum('days'), 1)];
        }
        usort($r->rows, fn ($a, $b) => [$a['employee']['name'], $a['type']] <=> [$b['employee']['name'], $b['type']]);
        $r->stat('Days of leave', round($records->sum('days'), 1))->stat('People', $records->pluck('employee_id')->unique()->count());
        foreach ($records->groupBy(fn ($l) => $l->type?->name ?? 'Leave') as $type => $group) {
            $r->stat($type, round($group->sum('days'), 1).' days');
        }
        $r->note('Leave that starts between the two dates. Days skip weekends and holidays.');

        return $r;
    }

    public function holidays(ReportFilters $f): ReportResult
    {
        $holidays = Holiday::query()->with('company:id,name')->whereYear('date', (string) $f->year)
            ->when($f->company, fn ($q, $v) => $q->where(fn ($w) => $w->whereNull('company_id')->orWhere('company_id', $v)))
            ->orderBy('date')->get();
        $r = (new ReportResult)->column('date', 'Date', 'date')->column('weekday', 'Day')->column('name', 'Holiday')->column('company', 'For');
        foreach ($holidays as $h) {
            $r->rows[] = ['date' => $h->date->toDateString(), 'weekday' => $h->date->format('l'), 'name' => $h->name, 'company' => $h->company?->name ?? 'Everyone'];
        }
        $today = $f->today->toDateString();
        $r->stat('Holidays', $holidays->count())
            ->stat('Still to come', $holidays->filter(fn ($h) => $h->date->toDateString() >= $today)->count())
            ->stat('On a weekday', $holidays->filter(fn ($h) => ! $h->date->isWeekend())->count());

        return $r;
    }
}
