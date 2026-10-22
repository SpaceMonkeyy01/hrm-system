<?php

/*
|--------------------------------------------------------------------------
| HRM business rules
|--------------------------------------------------------------------------
| Every status value and every "is this done?" rule lives here, in one place.
| These mirror the formulas in the original HR_Onboarding.xlsx so numbers can
| be reconciled 1:1 with the sheet. Change a rule here and the whole app
| (grids, dashboard, employee pages) follows.
*/

return [

    // Roles a login can have. admin > hr > viewer see HR records; employee sees only their own (My page).
    'roles' => [
        'admin' => 'Admin: everything, plus users and settings',
        'hr' => 'HR: add and edit employee records',
        'viewer' => 'Viewer: read only',
        'employee' => 'Employee: only their own record',
    ],

    // Asset register (Assets page). Types are managed by HR on the Assets page (asset_types table).
    'assets' => [
        // The daily HR email lists warranties ending within this many days (assets still in use).
        'warranty_days' => 30,
        // status => [label, status-pill tone]. "assigned" is set only by giving the asset to someone.
        'statuses' => [
            'in_stock' => ['label' => 'In stock', 'tone' => 'done'],
            'assigned' => ['label' => 'With someone', 'tone' => 'info'],
            'repair' => ['label' => 'In repair', 'tone' => 'pending'],
            'lost' => ['label' => 'Lost', 'tone' => 'missing'],
            'retired' => ['label' => 'Retired', 'tone' => 'neutral'],
        ],
    ],

    // Performance reviews (rules: app/Services/ReviewRules.php). HR and admin only: ratings are as private as pay.
    'reviews' => [
        'scale' => [1 => 'Unsatisfactory', 2 => 'Needs improvement', 3 => 'Meets expectations', 4 => 'Exceeds expectations', 5 => 'Outstanding'],
        // What a new cycle rates. Each cycle keeps its own copy, so changing this never rewrites old reviews.
        'default_criteria' => [
            ['key' => 'quality', 'label' => 'Quality of work', 'description' => 'Accurate, thorough, meets the standard without rework.'],
            ['key' => 'delivery', 'label' => 'Delivery and deadlines', 'description' => 'Gets agreed work done on time.'],
            ['key' => 'ownership', 'label' => 'Ownership and reliability', 'description' => 'Takes responsibility, follows through, can be counted on.'],
            ['key' => 'communication', 'label' => 'Communication', 'description' => 'Clear, timely and professional with the team and clients.'],
            ['key' => 'teamwork', 'label' => 'Teamwork', 'description' => 'Helps others, shares knowledge, works well across teams.'],
            ['key' => 'growth', 'label' => 'Learning and growth', 'description' => 'Takes feedback, builds skills, improves over the period.'],
            ['key' => 'attendance', 'label' => 'Attendance and punctuality', 'description' => 'On time, follows the leave policy.'],
        ],
        'recommendations' => ['No change', 'Pay increase', 'Promotion', 'Training', 'Improvement plan', 'Confirm employment'],
    ],

    // Secret for /cron/reminders/{token}, opened by an outside cron service on hosts without a scheduler (Render free).
    // Empty = the URL doesn't exist. Make it long and random: anyone with it can trigger the daily email.
    'cron_token' => env('CRON_TOKEN', ''),

    // Attendance machines are kept in the app (Attendance > Devices, attendance_devices), each with its own settings.
    // These ZK_* values only seed the first device once, when upgrading from the single-machine version; the rest
    // are the defaults for a new device.
    'machine' => [
        'host' => env('ZK_HOST', ''),
        'port' => (int) env('ZK_PORT', 4370),
        'comm_key' => (int) env('ZK_COMM_KEY', 0),   // Comm. > Comm Key on the machine; 0 = none
        'timeout' => 20,
        // Each sync saves the machine's punches from this many days before our latest machine punch (the first
        // sync saves everything). Covers a sync that failed for a few days without re-checking months of punches.
        'lookback_days' => 3,
        // Warn HR when the machine's clock is off by more than this.
        'clock_warn_seconds' => 60,
        // A device on automatic sync with no good sync for this long is "Sync overdue" (the cron job has probably
        // stopped). The cron runs round the clock, so this holds at night too.
        'stale_after_minutes' => 60,
    ],

    // Clock in / out on the My page (a punch with source "app"). A clock-in from one of the office IPs counts as office
    // time, from anywhere else as remote. Comma-separated, CIDR allowed: "203.0.113.7,198.51.100.0/24". Empty = all remote.
    'clock' => [
        'enabled' => (bool) env('HRM_CLOCK_ENABLED', true),
        'office_ips' => array_values(array_filter(array_map('trim', explode(',', (string) env('HRM_OFFICE_IPS', ''))))),
        // A clock-out this long after an open clock-in still closes it (night shifts end the next morning).
        // Past this the clock-in is left open (Missing punch) and the next press starts a new day.
        'max_shift_hours' => 18,
        // A second press this soon after the last one is ignored (a double tap).
        'double_tap_seconds' => 60,
    ],

    // Login page: the Islamic date is the Umm al-Qura calendar; Pakistan's moon sighting is often a day behind it.
    // -1 shows it a day earlier. Set HRM_HIJRI_OFFSET in the environment if the date on the login page is off.
    'hijri_offset' => (int) env('HRM_HIJRI_OFFSET', 0),

    // Self-service (My page)
    'self_service' => [
        // How far back someone can ask HR to fix a day.
        'request_days_back' => 60,
        // Contact details people can update themselves (written to the activity log like any change).
        'editable_fields' => ['phone', 'alternate_phone', 'personal_email', 'current_address', 'city',
            'emergency_contact_name', 'emergency_contact_relation', 'emergency_contact_phone'],
    ],

    /*
    | Option sets used by checklist items.
    | tone        -> colour of the status pill in the UI
    | done        -> counts as complete in status rules and dashboard
    | Order matters: it is the order shown in the status picker.
    */
    'option_sets' => [

        // Onboarding tracker columns L..Q, V..AB in the sheet ("Yes,No" dropdown)
        'yes_no' => [
            ['value' => 'Yes', 'tone' => 'done', 'done' => true],
            ['value' => 'No', 'tone' => 'missing', 'done' => false],
        ],

        // Orientation sessions R..T in the sheet ("Not Started,Done" dropdown)
        'session' => [
            ['value' => 'Done', 'tone' => 'done', 'done' => true],
            ['value' => 'Not Started', 'tone' => 'missing', 'done' => false],
        ],

        // Onboarding_Document_Checklist J..V ("Yes,No,Pending,Not Required,Old employee,not available")
        // Sheet formula W: Pending if any "No", any "Pending" or any blank; otherwise Complete.
        // So "Not Required", "Old employee" and "not available" all count as done, exactly like the sheet.
        'document' => [
            ['value' => 'Yes', 'tone' => 'done', 'done' => true],
            ['value' => 'No', 'tone' => 'missing', 'done' => false],
            ['value' => 'Pending', 'tone' => 'pending', 'done' => false],
            ['value' => 'Not Required', 'tone' => 'neutral', 'done' => true],
            ['value' => 'Old employee', 'tone' => 'info', 'done' => true],
            ['value' => 'not available', 'tone' => 'muted', 'done' => true],
        ],

        // Offboarding clearance
        'clearance' => [
            ['value' => 'Done', 'tone' => 'done', 'done' => true],
            ['value' => 'Pending', 'tone' => 'pending', 'done' => false],
            ['value' => 'Not Required', 'tone' => 'neutral', 'done' => true],
        ],
    ],

    // Probation result dropdown (tracker column AC). "Pending" = on probation.
    'probation_results' => ['Pending', 'Pass', 'Extend', 'Fail'],

    // Results that mean "still on probation": flagged as overdue or ending soon. Extend = Pending with a later end date.
    'probation_open_results' => ['Pending', 'Extend'],

    // Probation length used for Probation_End_Date (sheet formula: =H+90)
    // Default probation length for new joiners. HR changes it in Settings > Checklists (app_settings 'probation_days').
    'probation_days' => 90,

    // The separate 90-day plan page (goals, ratings, check-in notes). Switched off: the check-ins on the onboarding
    // checklist cover it. Tests switch it on to keep its code covered (phpunit.xml).
    'probation_plan' => (bool) env('HRM_PROBATION_PLAN', false),

    // Employment_Type dropdown (tracker column J)
    'employment_types' => ['Full Time', 'Intern', 'Contract'],

    // Scanned documents on the employee profile (Documents tab). Stored on the private disk, HR and admin only.
    // The server's PHP upload_max_filesize and post_max_size must be above max_kb (see docs/DEPLOY-cPanel.md).
    'uploads' => [
        'extensions' => ['pdf', 'jpg', 'jpeg', 'png'],
        'max_kb' => 10240,   // 10 MB
        // Where scanned documents are kept: "local" (storage/app/private) or "s3" for a bucket on hosts
        // without a lasting disk (Render free). See docs/DEPLOY-Render.md.
        'disk' => env('HRM_FILES_DISK', 'local'),
        // Off on hosts where the local disk is wiped on restart and no bucket is set up yet (Render free):
        // an upload that vanishes later is worse than no upload. Viewing and removing existing files still works.
        'enabled' => (bool) env('HRM_FILES_ENABLED', true),
    ],

    // Attendance (app/Services/AttendanceRules.php). Office timings are Work schedules, set up in the app.
    'attendance' => [
        // Punches this close together are one punch (people often tap twice).
        'duplicate_punch_minutes' => 5,
        // Remote staff (app clock-ins and HR.my): a punch before this time belongs to the day before (a check-out at
        // 01:30 ends yesterday's day; from 03:00 it is the new day). Machine punches keep their calendar day.
        // AttendanceRules::workDate().
        'day_starts_at' => env('HRM_DAY_STARTS_AT', '03:00'),
        // Day statuses and their colour (status-pill tones).
        'statuses' => [
            'Present' => 'done',
            'Half day' => 'pending',
            'Absent' => 'missing',
            'Missing punch' => 'pending',
            'Worked day off' => 'info',
            'Day off' => 'neutral',
            'At work' => 'info',
            'Not in yet' => 'neutral',
            'On leave' => 'muted',
            'Holiday' => 'neutral',
            'No punches' => 'neutral',
            'Not linked' => 'neutral',   // no machine or HR.my number, so no punches can arrive
            'No data' => 'neutral',      // no attendance file covers that day yet (AttendanceBoard)
            'Not tracked' => 'muted',    // on a No clock-in schedule and didn't punch: nothing expected
        ],
        // Statuses that mean the person came in (punched) that day: Home's "At work today" card.
        'came_in' => ['Present', 'Half day', 'Missing punch', 'Worked day off', 'At work'],
    ],

    // Master profile dropdowns
    'genders' => ['Male', 'Female', 'Other'],
    'marital_statuses' => ['Single', 'Married', 'Divorced', 'Widowed'],
    'blood_groups' => ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'],
    // Office = attendance from the ZKTeco machine, Remote = the online check-in portal, Hybrid = both
    'work_modes' => ['Office', 'Remote', 'Hybrid'],

    // Employment history entry types. The first six are written automatically (a job field changed on Edit, or a new work schedule);
    // HR can also add any of them by hand (e.g. a past promotion).
    'history_types' => ['Transfer', 'Role change', 'Employment type change', 'Manager change', 'Work mode change',
        'Work schedule change', 'Promotion', 'Confirmation', 'Other'],

    // Group column (tracker G) - cohort label used by HR
    'cohorts' => ['A', 'B', 'C'],

    'employee_statuses' => [
        'active' => 'Active',
        // Not confirmed as current yet (For_Review rows of Employee_Master.xlsx). Left out of attendance, reports
        // and headcounts until HR confirms them (next company code) or marks them as left.
        'review' => 'Review',
        'offboarding' => 'Offboarding',
        'exited' => 'Exited',
    ],

    // Offboarding_Tracker column B, plus common types
    'offboarding_types' => ['Resignation', 'Termination', 'Retirement', 'Contract End', 'Other'],

    // Controlled_Documents
    'document_types' => ['Policy', 'Template', 'Form', 'Letter', 'SOP'],
    'document_statuses' => ['Draft', 'Active', 'Obsolete'],
    'document_number_prefix' => 'BCC-',

    // 90_Day_Plan_Template dropdowns
    'plan' => [
        'item_statuses' => ['Not Started', 'In Progress', 'Completed'],   // goals, deliverables
        'evaluations' => ['Meets', 'Needs Improvement'],                    // behaviour, attendance
        'final_evaluations' => ['Meets', 'Missing', 'Exceeds'],             // final evaluation criteria
        'overall_fit' => ['Strong', 'Moderate', 'Weak'],
        // Default rows created for a new plan (copied from the template sheet)
        'defaults' => [
            'goal' => [
                ['title' => ''], ['title' => ''], ['title' => ''], ['title' => '', 'optional' => true],
            ],
            'behaviour' => [
                ['title' => 'Professional communication & tone'],
                ['title' => 'Teamwork & collaboration'],
                ['title' => 'Attitude, responsibility, and ethics'],
                ['title' => 'Adaptability & openness to feedback'],
            ],
            'attendance' => [
                ['title' => 'Punctuality', 'detail' => 'Arrives on time consistently'],
                ['title' => 'Leave Policy Compliance', 'detail' => 'Follows proper reporting'],
                ['title' => 'Responsiveness', 'detail' => 'Responds appropriately to manager/team'],
                ['title' => 'Reliability', 'detail' => 'Meets deadlines, owns tasks'],
            ],
            'deliverable' => [
                ['title' => ''], ['title' => ''], ['title' => ''],
            ],
        ],
        // Completing a check-in ticks the matching onboarding checklist item
        'checkin_items' => [
            'day30' => 'day30_checkin',
            'day60' => 'day60_checkin',
            'day90' => 'day90_evaluation',
        ],
    ],

    /*
    | Reminders: things HR has to act on, computed live from the data (never stored), like the statuses.
    | Shown on the dashboard ("Needs attention") and sent as a daily email (php artisan hr:send-reminders).
    | Rules: app/Services/HrRules.php (dates) and app/Services/Reminders.php (the list).
    | "Within N days" includes today and today + N. Past the date counts as overdue.
    */
    'reminders' => [
        // Probation is open (Pending or Extend) and ends within this many days. Past the end date = overdue.
        'probation_ending_days' => 7,

        // Check-ins: onboarding checklist item key => days after the start date it is due. HR changes the days in
        // Settings > Checklists (app_settings 'checkin_offsets'); HrRules::checkinOffsets() merges them over these.
        'checkin_offsets' => [
            'day30_checkin' => 30,
            'day60_checkin' => 60,
            'day90_evaluation' => 90,
        ],
        // Remind when a check-in is not done and is due within this many days. Past the due date = missed.
        'checkin_due_days' => 3,

        // Remind about missing documents once the start date is at least this many days ago.
        'documents_grace_days' => 14,

        // Probation, check-in and document reminders are per person only for people who started within this
        // many days (or have no start date). Older staff with missing documents become one weekly summary line.
        'recent_start_days' => 180,
        // Day the summary line goes in the email (ISO weekday: 1 = Monday ... 7 = Sunday).
        'documents_backlog_weekday' => 1,

        // CNIC expires within this many days (or has expired). Applies to every active employee with an expiry date.
        'cnic_expiry_days' => 30,

        // In-progress offboarding with clearance still open and the last working day within this many days (or past).
        'offboarding_days' => 3,

        // Active logins with one of these roles get the daily email. Viewers are never emailed.
        'recipient_roles' => ['admin', 'hr'],

        // Time the daily email goes out (24-hour clock, app timezone APP_TIMEZONE). Needs the schedule:run cron.
        'send_at' => '08:00',
    ],
];
