<?php

namespace App\Services\Reports;

use App\Services\HrRules;
use App\Services\StatusBoard;

/** Documents, CNIC expiry and onboarding that isn't finished. Status rules: HrRules via StatusBoard. */
class ComplianceReports
{
    public function documents(ReportFilters $f): ReportResult
    {
        $employees = $f->current()->with('department:id,name')->orderBy('full_name')->get(['id', 'employee_code', 'full_name', 'department_id', 'start_date']);
        $board = new StatusBoard($employees->pluck('id')->all());
        $items = $board->items['document'];
        $r = (new ReportResult)->column('employee', 'Employee', 'employee')->column('department', 'Department')
            ->column('start_date', 'Start date', 'date')->column('count', 'Missing', 'number')->column('missing', 'Documents still owed');
        $owed = [];
        foreach ($employees as $e) {
            $values = $board->values($e->id, 'document');
            $missing = $items->filter(fn ($i) => ! HrRules::isDone($values[$i->key] ?? null, 'document'))->pluck('label')->all();
            foreach ($missing as $label) {
                $owed[$label] = ($owed[$label] ?? 0) + 1;
            }
            if ($missing) {
                $r->rows[] = ['employee' => ReportResult::person($e), 'department' => $e->department?->name,
                    'start_date' => $e->start_date?->toDateString(), 'count' => count($missing), 'missing' => implode(', ', $missing)];
            }
        }
        usort($r->rows, fn ($a, $b) => $b['count'] <=> $a['count'] ?: strcmp($a['employee']['name'], $b['employee']['name']));
        $r->stat('Current employees', $employees->count())
            ->stat('Every document on file', $employees->count() - count($r->rows), 'done')
            ->stat('Something owed', count($r->rows), count($r->rows) ? 'pending' : null);
        arsort($owed);
        if ($owed) {
            $top = array_slice($owed, 0, 4, true);
            $r->note('Most often owed: '.implode(', ', array_map(fn ($l, $n) => "$l ($n)", array_keys($top), $top)).'.');
        }

        return $r;
    }

    /** Private: CNIC dates are HR-only (Employee::PRIVATE_FIELDS). */
    public function cnicExpiry(ReportFilters $f): ReportResult
    {
        $today = $f->today->toDateString();
        $until = $f->today->addDays($f->within)->toDateString();
        $employees = $f->current()->with('department:id,name')->get(['id', 'employee_code', 'full_name', 'department_id', 'cnic', 'cnic_expiry']);
        $r = (new ReportResult)->column('employee', 'Employee', 'employee')->column('department', 'Department')
            ->column('cnic', 'CNIC')->column('expiry', 'Expires', 'date')->column('days', 'Days left', 'number')
            ->column('state', 'Status', 'status', ['tones' => ['Expired' => 'missing', 'Expires soon' => 'pending']]);
        foreach ($employees as $e) {
            $expiry = $e->cnic_expiry?->toDateString();
            if ($expiry && $expiry <= $until) {
                $r->rows[] = ['employee' => ReportResult::person($e), 'department' => $e->department?->name, 'cnic' => $e->cnic,
                    'expiry' => $expiry, 'days' => (int) $f->today->diffInDays($e->cnic_expiry, false), 'state' => $expiry < $today ? 'Expired' : 'Expires soon'];
            }
        }
        usort($r->rows, fn ($a, $b) => strcmp($a['expiry'], $b['expiry']));
        $expired = count(array_filter($r->rows, fn ($x) => $x['state'] === 'Expired'));
        $soon = count($r->rows) - $expired;
        $noDate = $employees->whereNull('cnic_expiry')->count();
        $r->stat('Expired', $expired, $expired ? 'missing' : null)
            ->stat("Expire in {$f->within} days", $soon, $soon ? 'pending' : null)
            ->stat('No expiry date recorded', $noDate);
        if ($noDate) {
            $r->note("$noDate current employees have no CNIC expiry date, so they can't be checked. Add it on their profile.");
        }

        return $r;
    }

    public function onboarding(ReportFilters $f): ReportResult
    {
        $employees = $f->current()->whereHas('onboarding')->with(['department:id,name', 'onboarding'])->orderBy('start_date')
            ->get(['id', 'employee_code', 'full_name', 'department_id', 'start_date']);
        $board = new StatusBoard($employees->pluck('id')->all());
        $tone = ['Complete' => 'done', 'In Progress' => 'pending', 'Not Started' => 'missing', 'Pending' => 'pending',
            'Pass' => 'done', 'Extend' => 'info', 'Fail' => 'missing'];
        $r = (new ReportResult)->column('employee', 'Employee', 'employee')->column('department', 'Department')
            ->column('start_date', 'Start date', 'date')->column('orientation', 'Orientation', 'status', ['tones' => $tone])
            ->column('documents', 'Documents', 'status', ['tones' => $tone])->column('probation', 'Probation', 'status', ['tones' => $tone])
            ->column('probation_end', 'Probation end', 'date');
        $complete = 0;
        foreach ($employees as $e) {
            $result = $e->onboarding->probation_result;
            if ($board->onboarding($e->id, $result) === 'Onboarding Complete') {
                $complete++;

                continue;
            }
            $r->rows[] = ['employee' => ReportResult::person($e), 'department' => $e->department?->name, 'start_date' => $e->start_date?->toDateString(),
                'orientation' => $board->orientation($e->id), 'documents' => $board->documents($e->id), 'probation' => $result ?? 'Pending',
                'probation_end' => $e->onboarding->probation_end_date?->toDateString()];
        }
        $r->stat('In onboarding', $employees->count())->stat('Complete', $complete, 'done')->stat('Not finished', count($r->rows), count($r->rows) ? 'pending' : null);
        $r->note('Complete means all three orientation sessions, signed policy, NDA and NCA, the 30/60/90-day check-ins and a Pass.');

        return $r;
    }
}
