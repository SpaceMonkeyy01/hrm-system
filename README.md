# HRM System

Web app that replaces the HR Google Sheet (`HR_Onboarding.xlsx`) for BCC and Emerge: one employee master with unique codes, the onboarding tracker, the document checklist, 90-day probation plans, offboarding with clearance, a controlled-document register, and a dashboard whose numbers are correct.

Laravel 13 + Inertia 3 + React 19 + TypeScript + Tailwind 4. MySQL in production, SQLite locally. Every component is free and open source.

## What's inside

| Screen | Replaces | What it does |
|---|---|---|
| Dashboard | Dashboard tab | Needs attention: today's reminders (probation, 30/60/90-day check-ins, missing documents, offboarding clearance), also emailed to HR every morning. Lists to check (probation overdue or ending, unsigned NDA/NCA/policy, missing documents, records to check), per-document compliance bars, onboarding and probation counts, headcount, recent changes. Every number links to the list behind it. |
| Employees | BCC / Emerge department sheets, HR_Database | One record per person with a unique code (`BCC-001`, `Emerge-001`). Search, filters, profile with tabs, full change history. |
| Onboarding | Onboarding_Tracker, Employee_Checklist | Spreadsheet-style grid: click a status or type its first letter, tick rows to update many at once. Orientation, documents and onboarding status are computed live. |
| Documents | Onboarding_Document_Checklist | Same grid for the 13 documents, with "owed" counts per document and a Drive folder link per person. |
| 90-day plan | 90_Day_Plan_Template | Goals, behaviour, attendance, deliverables, 30/60/90-day check-ins, final evaluation, sign-off. Dating a check-in ticks it on the onboarding checklist. |
| Offboarding | Offboarding_Tracker, Exit_Interview_Template | Type, dates, reason, exit interview notes and a 9-item clearance checklist. Completing it marks the person as exited. |
| Controlled documents | Controlled_Documents, Templates | Numbered register (`BCC-0001`) with type, status, dates and a link to the file. |
| Settings (admin) | | Logins and roles (admin, HR, viewer), departments and teams, checklist items: add a new document type without a developer. |
| CSV export | | Employees, onboarding and documents, same numbers as the screens. |

## Run it locally

Needs PHP 8.3+, Composer and Node 20+.

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan db:seed --class=ChecklistItemSeeder
php artisan hr:import path/to/HR_Onboarding.xlsx      # optional: load the sheet
php artisan hr:create-admin you@company.com --name="Your Name"
npm install
npm run build          # or: npm run dev (hot reload)
php artisan serve      # http://127.0.0.1:8000
```

## Commands

| Command | What it does |
|---|---|
| `php artisan hr:import <file.xlsx> [--fresh] [--aliases=file.csv]` | Imports the workbook and writes a decision report. See `docs/DATA-MIGRATION.md`. |
| `php artisan hr:create-admin <email> [--name=] [--password=]` | Creates an admin login, or resets an existing one to admin. |
| `php artisan hr:send-reminders [--dry-run]` | Emails today's reminders (probation, check-ins, documents, offboarding) to active HR and admin logins, one email each. Runs daily at 08:00 via `schedule:run`. `--dry-run` prints them instead. |
| `composer test` | PHPUnit: tests covering the rules, reminders, the importer, permissions and the main flows. |
| `npm run types:check` | TypeScript check. |
| `npm run build` | Builds the frontend into `public/build`. |

## Where things are

```
app/Services/HrRules.php            the sheet's formulas as code (orientation, documents, onboarding, probation)
config/hrm.php                      every status value and whether it counts as done
app/Services/StatusBoard.php        the same rules for many people in one query
app/Services/Reminders.php          what is due today (dashboard + daily email)
app/Services/Import/                importer + name matcher
app/Support/Xlsx/XlsxReader.php     dependency-free .xlsx reader
app/Models/Concerns/LogsActivity.php  audit log for every change
app/Http/Controllers/               one controller per screen
routes/web.php                      every URL and its permission
resources/js/pages/                 one React file per screen
resources/js/components/checklist-grid.tsx   the editable grid
database/migrations/                the schema
tests/                              unit + feature tests (fake data only)
docs/                               architecture (UML), deployment, data migration
```

## Docs

- `docs/ARCHITECTURE.md`: deployment, components, data model, states, computed statuses, edit sequence, import pipeline (UML, Mermaid)
- `docs/DEPLOY-cPanel.md`: step-by-step for the cPanel VPS
- `docs/DATA-MIGRATION.md`: how sheet columns map to the app, name matching, the bugs found in the sheet, sheet vs app numbers

## Tested versions

laravel/framework 13.35.0, inertiajs/inertia-laravel 3.5.1, @inertiajs/react 3.8, React 19.2, Vite 8.3, Tailwind 4, PHPUnit 12.5.38, PHP 8.3.6.

## Data protection

The repository holds no employee data. Keep it that way: the import file, import reports and the SQLite database are git-ignored, and tests build their own fake workbook (`tests/Support/XlsxBuilder.php`).
