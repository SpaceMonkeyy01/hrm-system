<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\AttendancePunch;
use App\Models\AttendanceRequest;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRecord;
use App\Models\Offboarding;
use App\Services\AttendanceBoard;
use App\Services\HrSnapshot;
use App\Services\Reminders;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Home: what needs doing today. Reminders, the lists to check, probation endings, the latest attendance day,
 * who is away, who is new, and recent changes. Company-wide charts are on Overview (OverviewController).
 */
class DashboardController extends Controller
{
    /** Days ahead for leave, anniversaries and birthdays. */
    private const AHEAD_DAYS = 7;

    public function __invoke(): Response
    {
        $today = CarbonImmutable::today();
        $now = CarbonImmutable::now();
        $private = request()->user()->can('edit-hr');
        $current = Employee::query()->whereIn('status', ['active', 'offboarding'])
            ->get(['id', 'employee_code', 'full_name', 'company_id', 'department_id', 'start_date', 'exit_date', 'status',
                'attendance_machine_id', 'hr_portal_id', 'date_of_birth', 'role_title']);
        $snapshot = HrSnapshot::build($current->pluck('id')->all(), $today);

        $activity = ActivityLog::query()->with('user:id,name', 'employee:id,employee_code,full_name')
            ->latest('id')->limit(12)->get()->map(fn ($a) => [
                'id' => $a->id, 'when' => $a->created_at?->toIso8601String(), 'user' => $a->user?->name ?? 'System',
                'event' => $a->event, 'description' => $a->description, 'changes' => $a->changes,
                'employee' => $a->employee ? ['id' => $a->employee->id, 'name' => $a->employee->full_name, 'code' => $a->employee->employee_code] : null,
            ]);

        return Inertia::render('dashboard', [
            'today' => $today->toDateString(),
            // Viewers don't see reminders built on private fields (CNIC expiry).
            'reminders' => array_values(array_filter(Reminders::forDate($today),
                fn ($r) => $private || ! in_array($r['type'], Reminders::PRIVATE_TYPES, true))),
            'totals' => [
                'current' => $current->count(),
                'unassigned' => $current->whereNull('company_id')->count(),
                'needsReview' => Employee::where('needs_review', true)->count(),
                'inOnboarding' => $snapshot['onboarding']['total'],
                // Skip cases whose employee was soft-deleted, like every other tile (the relation itself includes trashed).
                'offboarding' => Offboarding::where('status', 'in_progress')->whereHas('employee', fn ($q) => $q->withoutTrashed())->count(),
                'requests' => AttendanceRequest::where('status', 'pending')->whereHas('employee')->count(),
            ],
            ...$snapshot,
            'attendance' => $this->latestAttendance($current, $today, $now),
            'away' => $this->away($today),
            'newPeople' => $this->newPeople($current, $today),
            'celebrations' => $this->celebrations($current, $today, $private),
            'holidays' => Holiday::query()->with('company:id,name')->whereBetween('date', [$today->toDateString(), $today->addDays(60)->toDateString()])
                ->orderBy('date')->limit(4)->get()
                ->map(fn ($h) => ['date' => $h->date->toDateString(), 'name' => $h->name, 'company' => $h->company?->name]),
            'activity' => $activity,
        ]);
    }

    /** Today, or the last day punches were loaded for (attendance arrives as uploaded files). */
    private function latestAttendance($current, CarbonImmutable $today, CarbonImmutable $now): ?array
    {
        $last = AttendancePunch::query()->where('work_date', '<=', $today->toDateString())->max('work_date');
        if (! $last) {
            return null;
        }
        $date = substr((string) $last, 0, 10);
        $totals = (new AttendanceBoard($current, $date, $date))->totals($now);

        return ['date' => $date] + $totals;
    }

    /** On leave from today through the next week. */
    private function away(CarbonImmutable $today): array
    {
        return LeaveRecord::query()->with('employee:id,full_name', 'type:id,name')
            ->where('start_date', '<=', $today->addDays(self::AHEAD_DAYS)->toDateString())->where('end_date', '>=', $today->toDateString())
            ->whereHas('employee', fn ($q) => $q->whereIn('status', ['active', 'offboarding']))
            ->orderBy('start_date')->limit(12)->get()
            ->map(fn ($l) => ['id' => $l->employee->id, 'name' => $l->employee->full_name, 'type' => $l->type?->name ?? 'Leave',
                'from' => $l->start_date->toDateString(), 'to' => $l->end_date->toDateString(), 'part' => $l->part])
            ->values()->all();
    }

    /** Started in the last 30 days or starting in the next 30. */
    private function newPeople($current, CarbonImmutable $today): array
    {
        $from = $today->subDays(30)->toDateString();
        $to = $today->addDays(30)->toDateString();

        return $current->filter(fn ($e) => $e->start_date && $e->start_date->toDateString() >= $from && $e->start_date->toDateString() <= $to)
            ->sortByDesc(fn ($e) => $e->start_date->toDateString())->take(8)
            ->map(fn ($e) => ['id' => $e->id, 'name' => $e->full_name, 'role' => $e->role_title, 'start' => $e->start_date->toDateString()])
            ->values()->all();
    }

    /** Work anniversaries for everyone; birthdays only for HR (date of birth is private). */
    private function celebrations($current, CarbonImmutable $today, bool $private): array
    {
        $out = [];
        for ($i = 0; $i <= self::AHEAD_DAYS; $i++) {
            $day = $today->addDays($i);
            $md = $day->format('m-d');
            foreach ($current as $e) {
                if ($e->start_date && $e->start_date->format('m-d') === $md && $e->start_date->year < $day->year) {
                    $out[] = ['id' => $e->id, 'name' => $e->full_name, 'date' => $day->toDateString(), 'kind' => 'anniversary',
                        'years' => $day->year - $e->start_date->year];
                }
                if ($private && $e->date_of_birth && $e->date_of_birth->format('m-d') === $md) {
                    $out[] = ['id' => $e->id, 'name' => $e->full_name, 'date' => $day->toDateString(), 'kind' => 'birthday', 'years' => null];
                }
            }
        }

        return array_slice($out, 0, 10);
    }
}
