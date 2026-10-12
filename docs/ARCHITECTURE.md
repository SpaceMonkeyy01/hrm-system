# HRM System: architecture

Every diagram below is Mermaid source (renders on GitHub, GitLab and in VS Code). A PNG and SVG of each sits next to its source in `docs/diagrams/`.

## 1. Deployment

One Laravel app on the existing cPanel VPS, next to the current Laravel app. Free components only: Apache and PHP-FPM from EasyApache, MySQL from cPanel, SSL from AutoSSL. Node is only needed on a developer machine to build the frontend; the server serves the built files from `public/build`. The `schedule:run` cron is required (no longer optional): it sends the daily reminder email, using the mail settings in `.env`.

```mermaid
flowchart TB
    subgraph CLIENT["«device» HR staff computer or phone"]
        BROWSER["«artifact» React 19 + Inertia 3 pages<br/>public/build (built once with npm run build)"]
    end

    subgraph VPS["«node» Company VPS: GoDaddy, AlmaLinux 9, cPanel/WHM"]
        subgraph WEB["«execution environment» Apache + PHP-FPM 8.3 (EasyApache / MultiPHP)"]
            APP["«artifact» Laravel 13 app<br/>document root = hrm/public<br/>subdomain e.g. hrm.yourdomain.com"]
        end
        DB[("«database» MySQL<br/>cpaneluser_hrm")]
        FILES[["«file system» storage/<br/>logs, import reports, sessions"]]
        CRON["«cron» php artisan schedule:run<br/>every minute (required: daily reminder email)"]
    end

    XLSX[/"HR_Onboarding.xlsx<br/>one-time import"/]

    BROWSER -- "HTTPS (cPanel AutoSSL, free)<br/>Inertia requests: JSON page props" --> APP
    APP -- "PDO / Eloquent" --> DB
    APP --> FILES
    CRON -- "hr:send-reminders daily 08:00" --> APP
    APP -. "SMTP: reminder email to HR and admin" .-> MAIL[("«service» Mail server<br/>cPanel email account")]
    XLSX -- "php artisan hr:import --fresh" --> APP
```

## 2. Components

Requests flow left to right. Controllers never compute a status themselves: they call `HrRules` (single-person rules) or `StatusBoard` (the same rules for many people at once, one query). The values and rules live in `config/hrm.php`.

```mermaid
flowchart LR
    subgraph FE["Browser: resources/js"]
        PAGES["pages/*.tsx<br/>dashboard, employees, onboarding,<br/>documents, plan, offboarding,<br/>controlled-documents, settings, account"]
        GRID["ChecklistGrid<br/>StatusPicker (optimistic save)"]
        PAGES --> GRID
    end

    subgraph BE["Laravel: app/"]
        ROUTES["routes/web.php<br/>auth / can:edit-hr / can:admin"]
        MW["Middleware<br/>HandleInertiaRequests<br/>EnsureUserIsActive"]
        CTRL["Http/Controllers<br/>Dashboard, Employee, Onboarding,<br/>DocumentChecklist, ChecklistEntry,<br/>ProbationPlan, Offboarding,<br/>ControlledDocument, Export, Settings/*"]
        SVC["Services<br/>HrRules = sheet formulas<br/>StatusBoard = statuses for many people<br/>Reminders = what is due today"]
        CFG["config/hrm.php<br/>status values, done rules,<br/>probation days, dropdown lists"]
        MODELS["Models<br/>+ LogsActivity trait"]
        IMPORT["Import (hr:import)<br/>XlsxReader > NameMatcher ><br/>HrWorkbookImporter"]
    end

    DB[("MySQL / SQLite")]

    PAGES -- "Inertia visit, router.put / post" --> ROUTES
    ROUTES --> MW --> CTRL
    CTRL --> SVC --> CFG
    CTRL --> MODELS --> DB
    IMPORT --> MODELS
    IMPORT --> SVC
    CTRL -- "Inertia::render(page, props)" --> PAGES
```

| Layer | Folder | What it does |
|---|---|---|
| Pages | `resources/js/pages` | One React file per screen. Props come from the controller's `Inertia::render()`. |
| Shared UI | `resources/js/components` | `ChecklistGrid`, `StatusPicker`, `StatusPill`, forms, shadcn/ui primitives. |
| Routes | `routes/web.php` | Every URL. Permissions are route middleware: `auth`, `can:edit-hr`, `can:admin`. |
| Controllers | `app/Http/Controllers` | Validate input, call rules, save models, return a page or redirect back. |
| Rules | `app/Services/HrRules.php`, `config/hrm.php` | The sheet's formulas as code. |
| Status board | `app/Services/StatusBoard.php` | Loads checklist values for many employees in one query and applies `HrRules`. |
| Reminders | `app/Services/Reminders.php`, `config/hrm.php` `reminders` | What HR has to act on today (probation, check-ins, documents, offboarding), computed live from `StatusBoard` and `HrRules`, never stored. Feeds the dashboard's "Needs attention" card and the daily email (`hr:send-reminders`, `app/Mail/RemindersDigest.php`). |
| Home and Overview | `DashboardController` (Home, `/dashboard`), `OverviewController` (`/overview`), `app/Services/HrSnapshot.php`, `app/Services/PeopleStats.php` | Home is what needs doing today; Overview is company-wide charts. Both read onboarding and document numbers from `HrSnapshot`, and headcount, joiners, leavers and turnover from `PeopleStats`. |
| Reports | `app/Services/Reports` (`ReportCatalog` lists them), `ReportController`, `resources/js/pages/reports` | Each report returns a `ReportResult` (columns, rows, summary, notes). The screen and the CSV download read the same result. Attendance reports go through `AttendanceBoard`, so they match the Attendance page. A new report is one catalog entry plus one method. |
| Models | `app/Models` | Eloquent models. `LogsActivity` writes every create, update and delete to `activity_log`. |
| Import | `app/Services/Import`, `app/Support/Xlsx` | `php artisan hr:import`: reads the workbook, matches names, writes data and a report. |

## 3. Data model

The fix for the spreadsheet's main problem: one `employees` row per person with a unique `employee_code`, and every other record points at `employees.id`. The sheet joined tabs on the typed name, which broke on every typo.

```mermaid
classDiagram
    direction LR
    class Company {
        +id
        +name  BCC | Emerge
        +code
        +employee_code_prefix  BCC- | Emerge-
        +employee_code_digits
    }
    class Department {
        +id
        +company_id
        +name
    }
    class Team {
        +id
        +department_id
        +name
    }
    class Employee {
        +id
        +employee_code  UNIQUE
        +full_name
        +first_name / last_name
        +cnic
        +email / phone
        +company_id / department_id / team_id
        +manager_id
        +role_title / employment_type
        +start_date
        +status  active | offboarding | exited
        +exit_date
        +hr_portal_id / attendance_machine_id
        +drive_folder_url
        +needs_review / review_note
        +deleted_at  soft delete
    }
    class ChecklistItem {
        +id
        +list  onboarding | document | offboarding
        +key  e.g. nda_signed
        +label
        +option_set  yes_no | session | document | clearance
        +sort_order / is_active
    }
    class ChecklistEntry {
        +id
        +employee_id
        +checklist_item_id
        +status  value from option_set
        +updated_by
    }
    class Onboarding {
        +id
        +employee_id  UNIQUE
        +cohort  Group A/B/C
        +probation_end_date
        +probation_result  Pending | Pass | Extend | Fail
        +notes
    }
    class ProbationPlan {
        +id
        +employee_id  UNIQUE
        +final_performance / behaviour / reliability
        +overall_fit
        +manager_recommendation / hr_comments
        +employee / manager / hr signed name + date
    }
    class ProbationPlanItem {
        +id
        +section  goal | behaviour | attendance | deliverable
        +title / detail / due_date
        +status / evaluation
        +manager_notes / employee_notes
    }
    class ProbationCheckin {
        +id
        +kind  day30 | day60 | day90 | extra
        +held_on / next_follow_up
        +manager_summary / employee_summary
        +action_items / initials
    }
    class Offboarding {
        +id
        +employee_id
        +type  Resignation | Termination | ...
        +notice_date / last_working_day
        +status  in_progress | completed | cancelled
        +reason / exit_interview_notes
    }
    class ControlledDocument {
        +id
        +doc_number  BCC-0001 UNIQUE
        +doc_type / name / status
        +issued_on / obsolete_on
        +link_url
    }
    class User {
        +id
        +email
        +role  admin | hr | viewer
        +is_active
    }
    class ActivityLog {
        +id
        +user_id / employee_id
        +subject_type / subject_id
        +event
        +changes  JSON field: [old, new]
        +created_at
    }
    Company "1" --> "*" Department : has
    Department "1" --> "*" Team : has
    Company "1" --> "*" Employee : employs
    Department "1" --> "*" Employee : in
    Team "1" --> "*" Employee : in
    Employee "0..1" --> "*" Employee : manages
    Employee "1" --> "*" ChecklistEntry : one value per item
    ChecklistItem "1" --> "*" ChecklistEntry : defines the column
    Employee "1" --> "0..1" Onboarding : tracker row
    Employee "1" --> "0..1" ProbationPlan : 90-day plan
    ProbationPlan "1" *-- "*" ProbationPlanItem : rows
    ProbationPlan "1" *-- "*" ProbationCheckin : log
    Employee "1" --> "*" Offboarding : cases
    Employee "1" --> "*" ActivityLog : history
    User "1" --> "*" ActivityLog : made the change
```

Why checklists are rows (`checklist_items` + `checklist_entries`) and not 30 columns:
- HR can add a document type or onboarding step in Settings, with no code change and no migration.
- One grid component serves onboarding, documents and offboarding clearance.
- Every single cell change is logged with who changed it, when, and the old and new value.

## 4. States

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

## 5. Computed statuses (the sheet's formulas)

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

## 6. Sequence: HR changes one cell

```mermaid
sequenceDiagram
    actor HR as HR user
    participant UI as StatusPicker (React)
    participant RT as routes/web.php
    participant C as ChecklistEntryController
    participant M as ChecklistEntry model
    participant LOG as LogsActivity trait
    participant DB as MySQL

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

## 7. Import pipeline (`php artisan hr:import`)

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

## Permissions

| Action | Viewer | HR | Admin |
|---|:-:|:-:|:-:|
| See dashboard, employees, grids, plans, offboarding, documents | yes | yes | yes |
| Export CSV | yes | yes | yes |
| Add or edit employees, change checklist values, bulk update | | yes | yes |
| 90-day plans, offboarding, controlled documents | | yes | yes |
| Delete an employee (soft delete) or a controlled document | | | yes |
| Users, departments and teams, checklist items | | | yes |

Enforced on the server by route middleware (`can:edit-hr`, `can:admin`, gates defined in `AppServiceProvider`). The React pages only hide buttons; a viewer calling the API directly gets 403 (covered by `tests/Feature/AccessTest.php`).

## Security notes

- No public sign-up. Admins create logins; switched-off users are logged out on their next request.
- Login is rate limited (5 tries per minute per email and IP).
- Employees are soft-deleted and every change is in `activity_log`.
- `public/` is the only web-visible folder. The `local` disk does not serve files over HTTP. Import reports (they contain names) stay in `storage/app/private/import`.
- Inertia DevTools recording is switched off in `.env` (`INERTIA_DEVTOOLS_ENABLED=false`) so request data is never written to disk, even if `APP_ENV` is left on `local` by mistake.
- Strict Eloquent mode is on outside production (no lazy loading, no silent attribute typos).
