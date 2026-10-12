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

    // Asset register (Assets page). Types are a plain list HR picks from; add one here when needed.
    'assets' => [
        'types' => ['Laptop', 'Desktop', 'Monitor', 'Mobile phone', 'SIM card', 'ID card', 'Access card', 'Headset', 'Other'],
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
    'probation_days' => 90,

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
        // Day statuses and their colour (status-pill tones).
        'statuses' => [
            'Present' => 'done',
            'Half day' => 'pending',
            'Absent' => 'missing',
            'Missing punch' => 'pending',
            'Worked day off' => 'info',
            'Day off' => 'neutral',
            'In office' => 'info',
            'Not in yet' => 'neutral',
            'On leave' => 'muted',
            'Holiday' => 'neutral',
            'No punches' => 'neutral',
            'Not linked' => 'neutral',   // no machine or HR.my number, so no punches can arrive
            'No data' => 'neutral',      // no attendance file covers that day yet (AttendanceBoard)
        ],
    ],

    // Master profile dropdowns
    'genders' => ['Male', 'Female'],
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

        // Check-ins: onboarding checklist item key => days after the start date it is due.
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
