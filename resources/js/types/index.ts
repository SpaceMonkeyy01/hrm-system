// Shapes of the props the Laravel controllers send. Keep in sync with app/Http/Controllers.

export type Role = 'admin' | 'hr' | 'viewer' | 'employee';

export type SharedProps = {
    appName: string;
    auth: {
        user: { id: number; name: string; email: string; role: Role } | null;
        /** edit: admin+hr; admin; view: sees HR records (not employee logins); self: login linked to an employee (My page) */
        can: { edit: boolean; admin: boolean; view: boolean; self: boolean };
    };
    flash: { success: string | null; error: string | null; id: string | null };
    sidebarOpen: boolean;
    /** HR and admin only: counts shown as sidebar badges (HandleInertiaRequests). */
    nav: { requests: number; reviews: number; leave: number; inbox: number } | null;
};

export type Tone = 'done' | 'missing' | 'pending' | 'neutral' | 'info' | 'muted';

export type StatusOption = { value: string; tone: Tone; done: boolean };

/** config/hrm.php "option_sets": yes_no | session | document | clearance */
export type OptionSets = Record<string, StatusOption[]>;

export type ChecklistColumn = {
    id: number;
    key: string;
    label: string;
    option_set: string;
    description: string | null;
    required: boolean;
    list?: 'onboarding' | 'document' | 'offboarding';
    group?: string;
};

export type CompanyOption = { id: number; name: string; departments: { id: number; name: string }[] };

export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    links: { url: string | null; label: string; active: boolean }[];
};

export type ActivityItem = {
    id: number;
    when: string | null;
    user: string;
    event: string;
    description: string | null;
    changes: Record<string, [unknown, unknown]> | Record<string, number> | null;
    employee?: { id: number; name: string; code: string } | null;
};

/** app/Services/Reminders.php: one thing HR has to act on, computed live (never stored). */
export type ReminderType = 'probation_overdue' | 'probation_ending' | 'checkin_missed' | 'checkin_due' | 'documents_pending' | 'offboarding_due' | 'cnic_expired' | 'cnic_expiring';

export type Reminder = {
    type: ReminderType;
    severity: 'overdue' | 'due_soon';
    employee_id: number;
    employee_code: string;
    employee_name: string;
    /** Y-m-d */
    due_date: string;
    /** Days from today to due_date; negative = overdue. */
    days: number;
    message: string;
    /** App path to act on it, e.g. /employees/5/plan or /offboarding/3 */
    link: string;
};
