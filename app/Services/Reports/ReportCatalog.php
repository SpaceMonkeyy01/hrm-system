<?php

namespace App\Services\Reports;

/**
 * Every report on the Reports page. A new report is one entry here plus a method on a *Reports class.
 *
 *   filters  which controls the page shows: month | range | year | date | within, plus company, department, team, manager
 *   range    'today': the range starts on today instead of the 1st of the month (reports with a row per person per day)
 *   private  HR and admin only (reads Employee::PRIVATE_FIELDS)
 *   wide     day-by-day grid: the page lets the table scroll sideways
 */
final class ReportCatalog
{
    public const GROUPS = ['attendance' => 'Attendance', 'people' => 'People', 'performance' => 'Performance', 'compliance' => 'Compliance', 'leave' => 'Leave and holidays'];

    /** @return array<string, array{group: string, title: string, description: string, filters: list<string>, run: array{0: class-string, 1: string}, private?: bool, wide?: bool, range?: string}> */
    public static function all(): array
    {
        $org = ['company', 'department'];

        return [
            'attendance-daily' => ['group' => 'attendance', 'title' => 'Daily attendance',
                'description' => 'Everyone on one day: status, check-in and check-out, hours, late, left early and where they worked.',
                'filters' => ['day', ...$org], 'run' => [AttendanceReports::class, 'daily']],
            'attendance-weekly' => ['group' => 'attendance', 'title' => 'Weekly attendance',
                'description' => 'One line per person for a week, Monday to Sunday: a code for each day plus present, late, absent, leave and hours.',
                'filters' => ['week', ...$org], 'run' => [AttendanceReports::class, 'weekly'], 'wide' => true],
            'attendance-monthly' => ['group' => 'attendance', 'title' => 'Monthly attendance summary',
                'description' => 'One line per person: days present, late, absent, on leave, hours worked and overtime.',
                'filters' => ['month', ...$org], 'run' => [AttendanceReports::class, 'monthly']],
            'attendance-sheet' => ['group' => 'attendance', 'title' => 'Monthly attendance sheet',
                'description' => 'Everyone by every day of the month, one code per day. Replaces the combined machine and HR.my file.',
                'filters' => ['month', ...$org], 'run' => [AttendanceReports::class, 'sheet'], 'wide' => true],
            'team-sign-in' => ['group' => 'attendance', 'title' => 'Team sign-in sheet',
                'description' => 'One row per person per day, by manager: first and second sign-in and sign-out, hours and remarks. Pick a manager or team, then download it to share.',
                'filters' => ['range', ...$org, 'team', 'manager'], 'range' => 'today', 'run' => [AttendanceReports::class, 'signIn']],
            'attendance-log' => ['group' => 'attendance', 'title' => 'Attendance log',
                'description' => 'Every day with its check-ins and check-outs, including split days (office, then remote).',
                'filters' => ['range', ...$org], 'run' => [AttendanceReports::class, 'log']],
            'late-arrivals' => ['group' => 'attendance', 'title' => 'Late arrivals',
                'description' => 'Each day someone checked in after their start time plus the grace period.',
                'filters' => ['range', ...$org], 'run' => [AttendanceReports::class, 'late']],
            'absences' => ['group' => 'attendance', 'title' => 'Absences and missing punches',
                'description' => 'Absent days, half days and days with only a check-in or only a check-out.',
                'filters' => ['range', ...$org], 'run' => [AttendanceReports::class, 'absences']],
            'overtime' => ['group' => 'attendance', 'title' => 'Overtime',
                'description' => 'Overtime per person, counted by each work schedule\'s overtime rule.',
                'filters' => ['range', ...$org], 'run' => [AttendanceReports::class, 'overtime']],

            'headcount' => ['group' => 'people', 'title' => 'Headcount by department',
                'description' => 'How many people each department has on a given day.',
                'filters' => ['date', ...$org], 'run' => [PeopleReports::class, 'headcount']],
            'joiners-leavers' => ['group' => 'people', 'title' => 'Joiners and leavers',
                'description' => 'Who started and who left between two dates, with the turnover for the period.',
                'filters' => ['range', ...$org], 'run' => [PeopleReports::class, 'movement']],
            'turnover' => ['group' => 'people', 'title' => 'Monthly turnover',
                'description' => 'Twelve months of opening headcount, joiners, leavers and turnover.',
                'filters' => ['month', ...$org], 'run' => [PeopleReports::class, 'turnover']],
            'codes' => ['group' => 'people', 'title' => 'Employee codes: ours and HR.my',
                'description' => 'Each person\'s code next to their HR.my ID and machine number, to see where the numbering differs.',
                'filters' => $org, 'run' => [PeopleReports::class, 'codes']],
            'tenure' => ['group' => 'people', 'title' => 'Length of service',
                'description' => 'How long each current employee has been with us, grouped into bands.',
                'filters' => $org, 'run' => [PeopleReports::class, 'tenure']],
            'probation' => ['group' => 'people', 'title' => 'Probation outcomes',
                'description' => 'Probations ending between two dates and their result.',
                'filters' => ['range', ...$org], 'run' => [PeopleReports::class, 'probation']],

            'review-outcomes' => ['group' => 'performance', 'title' => 'Review outcomes',
                'description' => 'Finished performance reviews for a year: overall rating, band and recommendation.',
                'filters' => ['year', ...$org], 'run' => [PerformanceReports::class, 'outcomes'], 'private' => true],

            'missing-documents' => ['group' => 'compliance', 'title' => 'Missing documents',
                'description' => 'Current employees still owing a document, and which ones.',
                'filters' => $org, 'run' => [ComplianceReports::class, 'documents']],
            'cnic-expiry' => ['group' => 'compliance', 'title' => 'CNIC expiry',
                'description' => 'CNICs that have expired or expire soon.',
                'filters' => ['within', ...$org], 'run' => [ComplianceReports::class, 'cnicExpiry'], 'private' => true],
            'onboarding-incomplete' => ['group' => 'compliance', 'title' => 'Unfinished onboarding',
                'description' => 'People in onboarding who still have orientation, documents, check-ins or probation open.',
                'filters' => $org, 'run' => [ComplianceReports::class, 'onboarding']],

            'leave-taken' => ['group' => 'leave', 'title' => 'Leave taken',
                'description' => 'Days of leave per person and leave type.',
                'filters' => ['range', ...$org], 'run' => [LeaveReports::class, 'taken']],
            'holidays' => ['group' => 'leave', 'title' => 'Holiday calendar',
                'description' => 'Public and company holidays for a year.',
                'filters' => ['year', 'company'], 'run' => [LeaveReports::class, 'holidays']],
        ];
    }

    public static function find(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }
}
