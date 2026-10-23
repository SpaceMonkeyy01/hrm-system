# HRM System: architecture

_For developers joining the project. Last rewritten 2026-10-23 against `main`. Every diagram is Mermaid source (renders on GitHub and in VS Code). The ERDs in section 5 were generated from the migrated schema, not drawn by hand._

**Keep this file true.** A new table, a new subsystem, a new external service or a change to who can do what updates the matching section here in the same commit. `CLAUDE.md` sends every developer and AI agent here first.

## 0. The system in one paragraph

An internal HR app for two companies, BCC and Emerge (about 280 people). HR keeps the employee master, onboarding and document checklists, probation, offboarding, leave, attendance (from a ZKTeco fingerprint machine, the HR.my portal and an in-app clock-in), staff requests (WFH, attendance fixes, loans, medical), performance reviews, letters, assets and controlled documents. Staff get a self-service "My page". One Laravel monolith serves server-rendered React pages through Inertia: there is no separate API and no SPA backend.

| | |
|---|---|
| Backend | Laravel 13, PHP 8.3 |
| Frontend | React 19 + TypeScript, Inertia 3, Tailwind 4, shadcn/ui, Vite 8 |
| Database | PostgreSQL (Neon) in production; SQLite locally and in tests; the suite also runs on Postgres |
| Hosting | Render (Docker, free plan), Cloudflare R2 for files, Resend for email, cron-job.org for timed jobs |
| Size | 46 app tables, ~56 models, ~48 controllers, ~50 pages, ~380 feature tests |

```
hrm-system/
  app/Http/Controllers      one controller per area; Settings/* is admin-only
  app/Models                Eloquent models; Concerns/LogsActivity writes the audit log
  app/Services              the rules: HrRules, StatusBoard, AttendanceBoard, AttendanceRules, LeaveBalances, Reports/*, Import/*
  app/Support               file readers (Xls, Xlsx), ZK machine client, CSV writer
  app/Console/Commands      hr:* artisan commands
  config/hrm.php            every HR value, status list, option set and switch
  routes/web.php            every URL and its permission group
  resources/js/pages        one React page per screen (Inertia::render('x/y') -> pages/x/y.tsx)
  resources/js/components   shared UI; ui/ is shadcn
  tests/Feature             behaviour tests; RouteAccessTest walks every route
  docs/                     this file, deploy guides, reviews
```

## 1. Deployment

```mermaid
flowchart LR
    subgraph USERS["People"]
        HR["HR, admin, viewer<br/>(browser)"]
        STAFF["Staff<br/>(My page, phone or PC)"]
    end
    subgraph RENDER["Render: Docker web service, Singapore, free plan"]
        APP["Laravel 13 + built React<br/>serversideup/php:8.3-fpm-nginx<br/>on start: migrate --force, caches"]
    end
    NEON[("Neon Postgres<br/>DB_URL, sslmode=require")]
    R2[["Cloudflare R2 bucket hrm-files<br/>disk s3: scanned documents"]]
    RESEND["Resend API<br/>(Render free blocks SMTP)"]
    CRON["cron-job.org"]
    ZK["ZKTeco machine in the office<br/>TCP 4370 via port forward"]
    DNS["hrm.bluecascade.org<br/>(custom domain)"]

    HR & STAFF -- HTTPS --> DNS --> APP
    APP -- Eloquent / pdo_pgsql --> NEON
    APP -- S3 API --> R2
    APP -- password links, staff notices, daily digest --> RESEND
    CRON -- "GET /cron/reminders/{token} daily 08:00 PKT" --> APP
    CRON -- "GET /cron/machine/{token} every 5-10 min" --> APP
    APP -- "read-only ZK protocol (ZkClient)" --> ZK
```

- **Build** (`Dockerfile`, three stages): `node:22-slim` runs `npm run build` into `public/build`; `composer:2` installs PHP packages without dev; the runtime image adds `intl`, `pdo_pgsql`, `gd`. Port 8080, upload limit 12 MB. Health check `/up`.
- **On every start** serversideup's automations run `migrate --force`, `storage:link` and the config/route/view caches. A data fix that must reach live goes in a migration (example: `2026_10_23_000400_set_hr_logins_home_company.php`), written through models so it is logged, and only filling what is empty.
- **Deploy** = `git push` to `main` on GitHub; Render auto-deploys. Changes to `render.yaml` env vars need a Blueprint sync. Secrets (`APP_KEY`, `DB_URL`, R2 keys, `RESEND_API_KEY`, `ZK_*`, `CRON_TOKEN`) live only in Render's environment, never in the repo.
- **Free plan facts**: the instance sleeps after 15 idle minutes (first request is slow) and its disk is wiped on restart, so nothing durable may be written to local disk in production: files go to R2, sessions and cache to the database.
- **No scheduler process.** `routes/console.php` schedules `hr:send-reminders`, but Render runs no `schedule:run`; cron-job.org calls `CronController` URLs instead (token compared with `hash_equals`, 404 when wrong, `throttle:10,1`).
- **Queue** is `sync` in production and tests: nothing implements `ShouldQueue`, mail is sent inside the request. A mail failure is `report()`ed and the action still saves.
- **Local**: SQLite `database/database.sqlite`, `php artisan serve`, `npm run dev` or `npm run build`. The machine needs PHP 8.3. `docs/DEPLOY-cPanel.md` describes the older VPS host and is not in use.

## 2. How a request flows

```mermaid
flowchart LR
    subgraph FE["Browser: resources/js"]
        PAGE["pages/**/*.tsx<br/>props from the controller"]
        LAYOUT["layouts: AppLayout (sidebar),<br/>LoginScene (auth/*), PrintLayout (print/*)"]
    end
    subgraph BE["Laravel"]
        ROUTE["routes/web.php<br/>auth / can:self-service /<br/>can:view-hr / can:edit-hr / can:admin"]
        MW["web middleware:<br/>HandleInertiaRequests (shared props)<br/>EnsureUserIsActive<br/>RememberCompany"]
        CTRL["Controller<br/>$request->validate()"]
        RULES["Services: HrRules, StatusBoard,<br/>AttendanceBoard/Rules, LeaveBalances,<br/>ReviewRules, Reports/*"]
        CFG["config/hrm.php"]
        MODEL["Models + LogsActivity"]
    end
    DB[("Postgres / SQLite")]
    PAGE -- "Inertia visit / router.post, put, delete" --> ROUTE --> MW --> CTRL
    CTRL --> RULES --> CFG
    CTRL --> MODEL --> DB
    CTRL -- "Inertia::render(page, props) or back()->with(success)" --> PAGE
```

| Layer | Where | Rule |
|---|---|---|
| Routes | `routes/web.php` | Every write route sits in a permission group. `RouteAccessTest` fails if one doesn't. |
| Controllers | `app/Http/Controllers` | Validate, call a service for anything computed, save through models, return a page or `back()` with a flash message. |
| Rules | `app/Services/*`, `config/hrm.php` | Status logic lives in one place. Controllers and React never compute onboarding, document, probation or attendance status themselves. |
| Models | `app/Models` | Strict mode outside production: eager-load (`with()`), no lazy loading, no unknown attributes. New columns with defaults also need the default in `$attributes`. |
| Shared props | `HandleInertiaRequests` | `auth.user`, `auth.can.{edit,admin,view,self}`, `flash`, `nav` (sidebar badges, HR only), `me.todo` (staff). |
| Pages | `resources/js/pages` | Props shape mirrors the controller; shared types in `resources/js/types/index.ts`. Dates arrive as `Y-m-d`, timestamps as ISO 8601. |

## 3. Who can do what

| Role (`users.role`) | Gets |
|---|---|
| `employee` | My page only, for the employee record linked by `users.employee_id`. |
| `viewer` | Every HR page read-only. Private fields arrive as `null`. |
| `hr` | Everything a viewer sees, plus all edits, approvals, files, letters, reviews, staff logins. |
| `admin` | Everything, plus deletes and Settings (users of every role, structure, checklists, test email). |

- **Gates** (`AppServiceProvider`): `view-hr` (admin, hr, viewer), `edit-hr` (admin, hr), `admin`, `self-service` (a login linked to an employee). Route groups use them as `can:` middleware; hiding a button in React is never the check.
- **My page** (`MeController`) only ever reads `$request->user()->employee_id`, never an id from the URL.
- **Private fields**: `Employee::PRIVATE_FIELDS` (CNIC, date of birth, addresses, emergency contact, bank details) are sent as `null` unless `can('edit-hr')`, and logged as `hidden` in the activity log. Loans, medical requests, meetings, conduct entries and the machine comm key have their own `PRIVATE_FIELDS`. Exports follow the same rule (the employees CSV drops CNIC for viewers).
- **Logins**: no public sign-up. Admins manage every login in Settings > Users; HR creates **staff** logins only (`StaffLoginsController`, `/logins`), and gets 403 on any other role. The person sets their own password from an emailed link (password broker `invites`, 3 days; resets use `users`, 60 minutes). `EnsureUserIsActive` logs out switched-off logins; making an employee `exited` switches their login off (`Employee::booted`).
- **Companies**: `users.company_id` is a login's home company. `RememberCompany` remembers the All / BCC / Emerge choice per login on the pages in its `PAGES` list and opens them on the home company. It narrows what is shown; it is not a restriction.
- **Tests that hold this in place**: `RouteAccessTest` (every write route gated; employee logins get 403 everywhere outside My page; viewers 403 on edit routes; HR 403 on admin routes), `AccessTest`, `SecurityRegressionTest`, `StaffLoginsTest`, `CompanySwitchTest`.
- **Why no row-level security**: only the Laravel app connects to the database, with one credential. Access control is in the app layer above. Anything that ever connects to the database directly (a BI tool, a second service) changes this.

## 4. Data model

46 app tables plus Laravel's own (`users` is shared, `sessions`, `cache`, `jobs`, `password_reset_tokens`, `migrations`). Conventions:

- **Everything links to `employees.id`.** Never join or look up by name; names are matched only by the importers.
- **Who did it**: most tables carry `created_by`, `updated_by`, `decided_by`, `requested_by` etc. pointing at `users`. They are left out of the diagrams below. `User::HISTORY_COLUMNS` must list every one of them (a test checks).
- **Soft deletes** on employees; a deleted employee is archived, not removed.
- **Audit**: every model with `LogsActivity` writes created / updated (`{field: [old, new]}`) / deleted to `activity_log`.
- **Settings** that HR can change at runtime (probation days, leave weights) live in `app_settings` as key/value rows; everything else is in `config/hrm.php`.
- **Checklist items are data**: a new document type or onboarding step is a row in `checklist_items`, not a migration. Keys in `HrRules::REQUIRED_KEYS` must never be removed.

### 4.1 People and organisation

```mermaid
erDiagram
    companies {
        int id PK
        string name
        string code
    }
    departments {
        int id PK
        int company_id FK
        string name
        int head_employee_id FK
    }
    teams {
        int id PK
        int department_id FK
        string name
    }
    employees {
        int id PK
        int company_id FK
        string employee_code
        string full_name
        string email
        int department_id FK
        int team_id FK
        int manager_id FK
        date start_date
        string status
        date exit_date
        string hr_portal_id
    }
    categories {
        int id PK
        string name
    }
    employee_category {
        int employee_id FK
        int category_id FK
    }
    users {
        int id PK
        string name
        string email
        datetime email_verified_at
        string role
        bool is_active
        int employee_id FK
        datetime last_login_at
        int company_id FK
    }
    employment_history {
        int id PK
        int employee_id FK
        date effective_date
    }
    employees |o--o{ departments : "head_employee_id"
    companies ||--o{ departments : "company_id"
    departments ||--o{ teams : "department_id"
    employees |o--o{ employees : "secondary_manager_id"
    departments |o--o{ employees : "secondary_department_id"
    employees |o--o{ employees : "manager_id"
    teams ||--o{ employees : "team_id"
    departments ||--o{ employees : "department_id"
    companies ||--o{ employees : "company_id"
    categories ||--o{ employee_category : "category_id"
    employees ||--o{ employee_category : "employee_id"
    companies ||--o{ users : "company_id"
    employees |o--o| users : "employee_id"
    employees ||--o{ employment_history : "employee_id"
```

`employees` has 55 columns: identity and contact, job (company, department, team, role, manager, secondary manager and department, employment type, work mode), the private fields, attendance links (`attendance_machine_id`, `hr_portal_id`, `attendance_device_id`), status (`active`, `review`, `offboarding`, `exited`) and import provenance (`legacy`, `source_ref`). Job changes write `employment_history` automatically (`JobHistory::record`).

### 4.2 Attendance

```mermaid
erDiagram
    attendance_devices {
        int id PK
        string name
        bool is_active
        datetime info_read_at
        string code
    }
    attendance_imports {
        int id PK
        string source
        date date_from
        date date_to
    }
    attendance_punches {
        int id PK
        string source
        string device_user_id
        datetime punched_at
        int attendance_import_id FK
        date work_date
        int attendance_device_id FK
    }
    machine_syncs {
        int id PK
        int user_id FK
        datetime started_at
        datetime finished_at
        int attendance_import_id FK
        int attendance_device_id FK
    }
    attendance_adjustments {
        int id PK
        int employee_id FK
        date date
    }
    attendance_requests {
        int id PK
        int employee_id FK
        date date
        string status
        datetime decided_at
    }
    work_schedules {
        int id PK
        string name
        string type
        bool is_default
    }
    employee_work_schedules {
        int id PK
        int employee_id FK
        int work_schedule_id FK
    }
    office_networks {
        int id PK
        string name
    }
    employees {
        int id PK
        int company_id FK
        string employee_code
        string full_name
    }
    attendance_devices ||--o{ attendance_punches : "attendance_device_id"
    attendance_imports ||--o{ attendance_punches : "attendance_import_id"
    attendance_devices ||--o{ machine_syncs : "attendance_device_id"
    attendance_imports ||--o{ machine_syncs : "attendance_import_id"
    employees ||--o{ attendance_adjustments : "employee_id"
    employees ||--o{ attendance_requests : "employee_id"
    work_schedules ||--o{ employee_work_schedules : "work_schedule_id"
    employees ||--o{ employee_work_schedules : "employee_id"
    attendance_devices ||--o{ employees : "attendance_device_id"
    employees ||..o{ attendance_punches : "device_user_id, no FK"
```

`attendance_punches` has **no foreign key to employees**. A punch is `(source, device_user_id, punched_at)`, unique, and is matched to a person when the day is computed: `machine` punches by `employees.attendance_machine_id`, `hrmy` by `employees.hr_portal_id`, `app` by `employees.id`. Linking a number to a person (Attendance > Links) therefore re-attributes all of that number's past punches at once.

### 4.3 Leave and holidays

```mermaid
erDiagram
    leave_types {
        int id PK
        string name
        bool is_paid
        bool is_active
    }
    leave_records {
        int id PK
        int employee_id FK
        int leave_type_id FK
        date start_date
        date end_date
        string part
        decimal days
        string source
    }
    leave_requests {
        int id PK
        int employee_id FK
        int leave_type_id FK
        date start_date
        date end_date
        string part
        decimal days
        string status
        datetime decided_at
        int leave_record_id FK
    }
    leave_adjustments {
        int id PK
        int employee_id FK
        int leave_type_id FK
        decimal days
        string source
    }
    holidays {
        int id PK
        date date
        string name
        int company_id FK
    }
    employees {
        int id PK
        int company_id FK
        string employee_code
        string full_name
    }
    companies {
        int id PK
        string name
    }
    employees ||--o{ leave_records : "employee_id"
    leave_types ||--o{ leave_records : "leave_type_id"
    employees ||--o{ leave_requests : "employee_id"
    leave_types ||--o{ leave_requests : "leave_type_id"
    leave_records ||--o{ leave_requests : "leave_record_id"
    leave_types ||--o{ leave_adjustments : "leave_type_id"
    employees ||--o{ leave_adjustments : "employee_id"
    companies ||--o{ holidays : "company_id"
    companies ||--o{ employees : "company_id"
```

### 4.4 Staff requests

```mermaid
erDiagram
    wfh_requests {
        int id PK
        int employee_id FK
        date start_date
        date end_date
        string status
        datetime decided_at
    }
    loans {
        int id PK
        int employee_id FK
        decimal amount
        string status
        datetime decided_at
        datetime repaid_at
    }
    loan_repayments {
        int id PK
        int loan_id FK
        decimal amount
    }
    medical_requests {
        int id PK
        int employee_id FK
        string kind
        date treatment_date
        decimal amount
        string status
        datetime decided_at
    }
    attendance_requests {
        int id PK
        int employee_id FK
        date date
        string status
        datetime decided_at
    }
    employees {
        int id PK
        int company_id FK
        string employee_code
        string full_name
    }
    employees ||--o{ wfh_requests : "employee_id"
    employees ||--o{ loans : "employee_id"
    loans ||--o{ loan_repayments : "loan_id"
    employees ||--o{ medical_requests : "employee_id"
    employees ||--o{ attendance_requests : "employee_id"
```

### 4.5 Onboarding, documents, probation, offboarding

```mermaid
erDiagram
    checklist_items {
        int id PK
        string key
        bool is_active
    }
    checklist_entries {
        int id PK
        int employee_id FK
        int checklist_item_id FK
        string status
    }
    employee_files {
        int id PK
        int employee_id FK
        int checklist_item_id FK
    }
    onboardings {
        int id PK
        int employee_id FK
        date probation_end_date
        datetime closed_at
    }
    probation_plans {
        int id PK
        int employee_id FK
        date plan_date
    }
    probation_plan_items {
        int id PK
        int probation_plan_id FK
        string title
        date due_date
        string status
    }
    probation_checkins {
        int id PK
        int probation_plan_id FK
        string kind
    }
    offboardings {
        int id PK
        int employee_id FK
        string type
        date notice_date
        string status
        datetime completed_at
    }
    employees {
        int id PK
        int company_id FK
        string employee_code
        string full_name
    }
    checklist_items ||--o{ checklist_entries : "checklist_item_id"
    employees ||--o{ checklist_entries : "employee_id"
    checklist_items ||--o{ employee_files : "checklist_item_id"
    employees ||--o{ employee_files : "employee_id"
    employees |o--o| onboardings : "employee_id"
    employees |o--o| probation_plans : "employee_id"
    probation_plans ||--o{ probation_plan_items : "probation_plan_id"
    probation_plans ||--o{ probation_checkins : "probation_plan_id"
    employees ||--o{ offboardings : "employee_id"
```

### 4.6 Reviews and feedback

```mermaid
erDiagram
    review_cycles {
        int id PK
        string name
        string status
    }
    reviews {
        int id PK
        int review_cycle_id FK
        int employee_id FK
        string status
        datetime self_submitted_at
        datetime manager_submitted_at
        datetime acknowledged_at
    }
    conduct_entries {
        int id PK
        int employee_id FK
        string type
        string title
        int issued_letter_id FK
        datetime acknowledged_at
    }
    meetings {
        int id PK
        int employee_id FK
        string type
        datetime acknowledged_at
    }
    issued_letters {
        int id PK
        int employee_id FK
        int letter_template_id FK
    }
    employees {
        int id PK
        int company_id FK
        string employee_code
        string full_name
    }
    employees ||--o{ reviews : "employee_id"
    review_cycles ||--o{ reviews : "review_cycle_id"
    issued_letters ||--o{ conduct_entries : "issued_letter_id"
    employees ||--o{ conduct_entries : "employee_id"
    employees ||--o{ meetings : "employee_id"
    employees ||--o{ issued_letters : "employee_id"
```

### 4.7 Letters, assets, controlled documents

```mermaid
erDiagram
    letter_templates {
        int id PK
        string name
        bool is_active
    }
    issued_letters {
        int id PK
        int employee_id FK
        int letter_template_id FK
    }
    controlled_documents {
        int id PK
        string name
        string status
    }
    asset_types {
        int id PK
        string name
        bool is_active
    }
    assets {
        int id PK
        string type
        string name
        string status
        date purchase_date
        int company_id FK
    }
    asset_assignments {
        int id PK
        int asset_id FK
        int employee_id FK
    }
    companies {
        int id PK
        string name
    }
    employees {
        int id PK
        int company_id FK
        string employee_code
        string full_name
    }
    letter_templates ||--o{ issued_letters : "letter_template_id"
    employees ||--o{ issued_letters : "employee_id"
    companies ||--o{ assets : "company_id"
    employees ||--o{ asset_assignments : "employee_id"
    assets ||--o{ asset_assignments : "asset_id"
    companies ||--o{ employees : "company_id"
```

### 4.8 Logins and audit

```mermaid
erDiagram
    activity_log {
        int id PK
        int user_id FK
        int employee_id FK
        int subject_id
    }
    users {
        int id PK
        string name
        string email
        datetime email_verified_at
        string role
        bool is_active
        int employee_id FK
        datetime last_login_at
        int company_id FK
    }
    employees {
        int id PK
        int company_id FK
        string employee_code
        string full_name
        string email
        int department_id FK
        int team_id FK
        int manager_id FK
        date start_date
        string status
        date exit_date
        string hr_portal_id
    }
    app_settings {
        string key
    }
    employees ||--o{ activity_log : "employee_id"
    users ||--o{ activity_log : "user_id"
    employees |o--o| users : "employee_id"
```

## 5. Attendance

The most involved subsystem. Three sources write punches; one engine turns punches into a status per person per day; every screen and report reads that engine.

```mermaid
flowchart LR
    ZK["ZKTeco machine"] -- "ZkClient (TCP 4370)" --> SYNC["MachineSyncer<br/>cron URL, hr:sync-machine,<br/>Sync button"]
    FILE[".xls / .xlsx<br/>machine Full day log,<br/>HR.my exports"] --> UP["AttendanceUploadController<br/>check > confirm"] --> IMP["AttendanceFileImporter"]
    PRESS["My page clock button"] --> CLOCK["AttendanceClock::press<br/>office_networks decide office / remote"]
    SYNC & IMP --> P[("attendance_punches<br/>insertOrIgnore")]
    CLOCK --> P
    SYNC --> MS[("machine_syncs,<br/>attendance_imports")]
    IMP --> AI[("attendance_imports")]
    REQ["Staff: fix a day<br/>attendance_requests"] -- "HR approves" --> ADJ[("attendance_adjustments")]
    EDIT["HR edits a day<br/>PUT /attendance/{e}/{date}"] --> ADJ
    P & ADJ --> BOARD["AttendanceBoard<br/>loads punches, adjustments, schedules,<br/>holidays, leave, WFH in bulk"]
    SCHED[("work_schedules +<br/>employee_work_schedules")] --> BOARD
    BOARD --> RULES["AttendanceRules::day<br/>sessions, late, early, overtime, status"]
    RULES --> OUT["Attendance page, profile, My page,<br/>Reports, Home, Overview, leave deductions"]
```

- **Work date**: remote punches (HR.my, app) before `hrm.attendance.day_starts_at` (03:00) belong to the previous day; an app check-out takes its check-in's date. Machine punches keep their calendar date. Times after midnight show past 24:00 on the board ("25:30") and as "01:30 (24 Oct)" in the sign-in sheet.
- **Sessions**: machine punches in a row form one block (first to last); HR.my and app punches pair In/Out. Double taps within 5 minutes are one punch. A session wholly inside another adds no time.
- **Schedule**: the latest `employee_work_schedules` row on or before the date, else the `is_default` schedule. Fixed schedules give late (after start + grace), left early, half day, absent after the cut-off and overtime; flexible schedules give "short". Missing check-out is either filled with the end time (`missing_checkout = auto`) or flagged.
- **Statuses** and their colours are in `config/hrm.php` `attendance.statuses` (Present, Late, Half day, Absent, Missing punch, On leave, Holiday, Day off, Worked day off, At work, Not in yet, Not linked, No data ...). "No data" means no punches from that source at all that day, so absence would be a guess.
- **HR corrections** never edit punches: `attendance_adjustments` (one per person per day) override check-in/out or the sessions, or mark a weekly day off, always with a note.
- **Machine sync** takes a cache lock per device, reads back `lookback_days` before the newest stored punch, records every attempt in `machine_syncs` (pruned after 30 days) and flags overdue devices in the daily digest.

## 6. Workflows

Every staff request follows the same pattern: the person creates it on My page (`can:self-service`) and may cancel while `pending`; HR approves or rejects (`can:edit-hr`, a reject needs a note); the decision emails the person through `StaffMail` (`StaffNotice`, sent from their company's HR address, linking back to `/me#section`). `RequestsInboxController::QUEUES` gathers the five queues on `/requests` and feeds the sidebar badges.

```mermaid
stateDiagram-v2
    direction LR
    [*] --> pending: staff asks
    pending --> cancelled: staff cancels
    pending --> rejected: HR rejects (note required)
    pending --> approved: HR approves
    approved --> [*]
    note right of approved
        leave: creates a leave_record (refuses clashes)
        attendance fix: writes attendance_adjustments
        WFH: the days count as remote work (max 31)
        loan: becomes active, then repaid when repayments reach the amount
        medical: approved amount <= asked, then paid
    end note
```

| Area | Controllers | Tables | Notes |
|---|---|---|---|
| Onboarding | `OnboardingController`, `ChecklistEntryController`, `DocumentChecklistController` | `onboardings`, `checklist_items`, `checklist_entries` | Grid cells save one at a time (optimistic). Statuses are computed by `HrRules` / `StatusBoard` (section 7). Closing removes a record from the tracker and reminders. |
| Probation | `ProbationPlanController` | `probation_plans`, `probation_plan_items`, `probation_checkins` | End = start + `probation_days` (`app_settings`). The 90-day plan is behind `HRM_PROBATION_PLAN`. Extend needs a later end date. |
| Files | `EmployeeFileController` | `employee_files` | PDF/JPG/PNG up to 10 MB on `hrm.uploads.disk` (R2 in production) under `employee-files/{id}/`. Served only through the app, HR and admin only. `HRM_FILES_ENABLED` switches uploads off. |
| Offboarding | `OffboardingController` | `offboardings` | Complete needs every clearance item done and no assets still out; sets `exited`, which switches the login off. Cancel returns the person to active. |
| Leave | `MeController`, `LeaveRequestController`, `LeaveController` | `leave_types`, `leave_records`, `leave_requests`, `leave_adjustments`, `holidays` | Balances in `LeaveBalances`: pro-rated allowance, records + adjustments used, plus deductions from attendance (absences, late/early) taken from Casual then Annual. Holidays are per company or common (`company_id` null). |
| Requests | `AttendanceRequestController`, `WfhRequestController`, `LoanController`, `MedicalRequestController`, `RequestsInboxController` | see 4.4 | The pattern above. |
| Reviews | `ReviewController`, `MeController`, `FeedbackController` | `review_cycles`, `reviews`, `conduct_entries`, `meetings` | Cycle draft > open > closed; review self_review > manager_review > completed. HR and admin only, even for reading. Staff acknowledge reviews, warnings and shared meeting notes on My page. |
| Letters | `LetterController` | `letter_templates`, `issued_letters` | `LetterRenderer` fills 14 `{{fields}}`; reference `CODE/HR/YYYY/NNN`. Issuing can also create a conduct entry and tick an offboarding item. Print view `print/letter`. |
| Assets | `AssetController` | `assets`, `asset_types`, `asset_assignments` | An asset is with someone while its assignment has no `returned_on`. Status changes only through give / take back. |
| Controlled documents | `ControlledDocumentController` | `controlled_documents` | Numbered `BCC-0001`; Draft, Active, Obsolete. |
| Staff logins | `StaffLoginsController`, `Settings\UserController`, `Settings\StaffLoginController` | `users` | See section 3. |

## 7. Onboarding rules and the HR workbook import

_Unchanged since the first version of this document; still how the code works._

### 7.1 Onboarding and probation states

```mermaid
stateDiagram-v2
    direction LR
    state "Employee.status" as EMP {
        [*] --> active : Add employee or import
        active --> offboarding : Start offboarding
        offboarding --> active : Cancel offboarding
        offboarding --> exited : Mark as exited [every clearance item Done or Not Required]
        exited --> [*]
    }
    state "Onboarding.probation_result" as PROB {
        [*] --> NotTracked : imported blank
        [*] --> Pending : new hire, end = start + 90 days
        Pending --> Pass
        Pending --> Extend : set a later end date (required)
        Extend --> Pass
        Extend --> Fail
        Pending --> Fail
        note right of Pending : "Overdue" flag when today > end date and result is Pending or Extend
    }
```

### 7.2 Computed statuses (the sheet's formulas)

Computed on every request from the checklist values, never stored, so they can't go stale. Source: `app/Services/HrRules.php`.

```mermaid
flowchart TB
    subgraph ORI["Orientation (sheet column U)"]
        O1{"Sessions 1, 2, 3 all Done?"} -- yes --> OC["Complete"]
        O1 -- no --> O2{"Any session Done?"}
        O2 -- yes --> OP["In Progress"]
        O2 -- no --> ON["Not Started"]
    end
    subgraph DOC["Documents (sheet column W)"]
        D1{"Every active document is Yes, Not Required,<br/>Old employee or not available?"} -- yes --> DC["Complete"]
        D1 -- "no: any No, Pending or blank" --> DP["Pending"]
    end
    subgraph ONB["Onboarding status (sheet column AE)"]
        N1{"Orientation Complete<br/>AND NDA, NCA, Policy done<br/>AND 30, 60, 90-day = Yes<br/>AND probation = Pass?"} -- yes --> NC["Onboarding Complete"]
        N1 -- no --> NP["Onboarding In Progress"]
    end
    OC -.-> N1
```

### 7.3 Sequence: HR changes one cell

```mermaid
sequenceDiagram
    actor HR as HR user
    participant UI as StatusPicker (React)
    participant RT as routes/web.php
    participant C as ChecklistEntryController
    participant M as ChecklistEntry model
    participant LOG as LogsActivity trait
    participant DB as Postgres

    HR->>UI: click a cell, or focus it and type Y / N / D
    UI->>UI: show the new value at once (optimistic)
    UI->>RT: PUT /checklist/{employee}/{item} {status}
    RT->>RT: auth, then can:edit-hr (viewer gets 403)
    RT->>C: update(employee, item)
    C->>C: validate status is in the item's option set
    C->>M: firstOrNew, skip if unchanged, save
    M->>DB: INSERT or UPDATE checklist_entries
    M->>LOG: updated event
    LOG->>DB: INSERT activity_log (user, employee, field, old, new)
    C-->>UI: redirect back, Inertia partial reload (only rows)
    UI->>UI: server value replaces the optimistic one, statuses recomputed by StatusBoard
```

### 7.4 Import pipeline (`php artisan hr:import`)

```mermaid
flowchart TD
    X["HR_Onboarding.xlsx"] --> R["XlsxReader<br/>unzip + read XML, cached formula values, dates"]
    R --> C["BCC + Emerge department sheets<br/>companies, departments, teams,<br/>employees with codes BCC-001 / Emerge-001"]
    C --> IDX["NameMatcher index"]
    R --> T["Onboarding_Tracker rows"]
    R --> D["Onboarding_Document_Checklist rows"]
    T --> MATCH{"Match the typed name"}
    D --> MATCH
    IDX --> MATCH
    MATCH -- "exact / normalized / spelling<br/>or aliases.csv" --> LINK["Link row to employee"]
    MATCH -- "fuzzy / subset / bracket hint" --> FLAG["Link + needs_review flag"]
    MATCH -- "two or more candidates" --> SKIP["Row skipped, reported as ambiguous"]
    MATCH -- "no candidate" --> NEW["Create UNASSIGNED-### + needs_review"]
    LINK --> VALS["Onboarding row + checklist values<br/>values mapped case-insensitively"]
    FLAG --> VALS
    NEW --> VALS
    VALS --> TWICE{"Fact typed in both sheets?<br/>NDA, NCA, Policy, CNIC, Appointment letter"}
    TWICE -- "checklist has a value" --> KEEP["Keep checklist value<br/>report conflict if done / not done differs"]
    TWICE -- "checklist blank" --> FILL["Fill from tracker"]
    KEEP --> SAVE[("Upsert checklist_entries<br/>one transaction")]
    FILL --> SAVE
    SAVE --> DOCS["Controlled documents + Templates sheet"]
    DOCS --> REP["import-report-*.csv<br/>one row per decision"]
```

## 8. Timed jobs and email

| What | Trigger | Code |
|---|---|---|
| Daily digest to HR and admin (what is due: probation, check-ins, documents, offboarding, overdue machine) | cron-job.org `GET /cron/reminders/{token}` once a day (guarded by `Cache::add`) | `hr:send-reminders`, `app/Services/Reminders.php`, `app/Mail/RemindersDigest.php`. One digest per home company; an empty digest sends nothing. Sent from the default sender. |
| Machine sync | cron-job.org `GET /cron/machine/{token}` every 5-10 minutes | `MachineSyncer::runAll` (devices with `auto_sync`) |
| Staff notices (decisions, letters, warnings) | inline, in the request | `StaffMail` → `StaffNotice`, from `Company::mailFromFor` (`companies.hr_email`, else the default sender) |
| Password and welcome links | inline | `SetPasswordNotification`; the link uses the host the sender is browsing on (`url()`), so send from `hrm.bluecascade.org` |

Artisan commands (`app/Console/Commands`): `hr:import` (one-time seed from the HR workbook), `hr:copy-data` (copy a whole database to another connection; used once to move to Neon), `hr:create-admin`, `hr:send-reminders --dry-run`, `hr:sync-machine {device?}`, `hr:redate-punches --apply`.

## 9. Reports, imports and exports

- **Reports** (`app/Services/Reports`): `ReportCatalog` lists them, each a method returning a `ReportResult` (columns, rows, summary, notes). The screen and "Download for Excel" read the same result, so they never disagree. Filters: month, range, year, date, day, week, company, department, team, category, manager. A new report is one catalog entry and one method. Attendance reports go through `AttendanceBoard`. Private reports (`'private' => true`) are HR and admin only.
- **CSV exports** (`ExportController`): employees, onboarding, documents, with the list's current filters. CNIC only for HR and admin.
- **Spreadsheet imports** (`SpreadsheetImportController::KINDS`: details, assets, links, leave-sheet): download a template, upload, preview the plan, apply (the plan is recomputed before saving) or discard. HR only.
- **Attendance files** are covered in section 5. **The HR workbook seed** is section 7.

## 10. Frontend

- `resources/js/app.tsx` resolves pages with `import.meta.glob('./pages/**/*.tsx')` and picks the layout by name: `auth/*` → `LoginScene`, `print/*` → `PrintLayout`, the rest → `AppLayout` (sidebar from `components/app-sidebar.tsx`).
- Shared building blocks: `ChecklistGrid` and `StatusPicker` (the onboarding and document grids), `StatusPill` (word plus colour, never colour alone), `CompanySwitch`, `FilterBar`, `EmployeeSearch`, `ClockCard`, `DecideDialog`, `FlashToaster`; shadcn primitives in `components/ui`.
- Design tokens are in `resources/css/app.css` `@theme`: brand navy `#00435C` for actions, colour only for status, every text pair at 4.5:1. Fonts: Atkinson Hyperlegible Next (body, numbers), Jost (titles).
- UI copy: sentence case, plain words HR staff use, buttons say what they do ("Save plan", not "Submit").

## 11. Working on it

| To add | Do |
|---|---|
| A page | Controller method + route in the right permission group + `resources/js/pages/<area>/<name>.tsx`; keep `types/index.ts` in step. |
| A write route | Inside a `can:edit-hr`, `can:admin` or `can:self-service` group, with `$request->validate()`. `RouteAccessTest` enforces it. |
| A table | Migration, model with `LogsActivity`, defaults mirrored in `$attributes`, any `*_by` column added to `User::HISTORY_COLUMNS`, and a section 4 ERD update. |
| A status or option | `config/hrm.php`; the formula in `HrRules` / `AttendanceRules`, never in a controller or React. |
| A checklist item | A row in Settings > Checklists (or `ChecklistItemSeeder` for defaults), not a migration. |
| A report | `ReportCatalog` entry + method in `app/Services/Reports`. |
| A data fix on live | A migration through models (logged), filling only what is empty. |

**Definition of done**: `php vendor/bin/phpunit` passes (also `-c phpunit.pgsql.xml` against a local Postgres for anything database-specific), `npx tsc --noEmit` is clean, `npm run build` works, and the changed screen has been opened in a browser at desktop and phone width. **No real HR data** in the repo, tests, fixtures, screenshots or logs: tests build fake workbooks with `tests/Support/XlsxBuilder.php`.

## 12. Security notes

- HTTPS only, secure session cookies, `APP_DEBUG=false` in production, sessions in the database.
- Login rate limit: 5 tries per minute per email and IP. Password links expire (3 days for invites, 60 minutes for resets).
- Employees are soft-deleted; every change is in `activity_log` with private values masked.
- Files: private disk or R2 bucket, never public URLs; served by `EmployeeFileController` after the permission check.
- Strict Eloquent outside production; Inertia DevTools recording off.
- Open items (owner's list, 2026-10-23): rotate the Neon password, keep an offline copy of `APP_KEY`, replace the admin login copied from the local machine, two-step login for admin and HR, encrypting CNIC and bank account columns, own database backups beyond Neon's short restore window.
