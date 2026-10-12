# HRM System: rules for AI agents and developers

Laravel 13 + Inertia 3 + React 19 (TypeScript) + Tailwind 4. Read `docs/ARCHITECTURE.md` first.

## Commands
- Tests: `php vendor/bin/phpunit` (all must pass). On Postgres too: `php vendor/bin/phpunit -c phpunit.pgsql.xml` (a local Postgres on port 55432, database `hrm_test`)
- Types: `npx tsc --noEmit` (must be clean)
- Build: `npm run build`

## Hard rules
1. **Status logic lives in one place.** Values and "counts as done" are in `config/hrm.php`; the formulas are in `app/Services/HrRules.php`; bulk calculation is `app/Services/StatusBoard.php`. Never compute orientation, document, onboarding or probation status in a controller or in React.
2. **Every record links to `employees.id`.** Never join or look up by name. Names are only matched by the importer.
3. **Every write goes through a controller** with `$request->validate()` and a permission middleware in `routes/web.php` (`can:edit-hr` or `can:admin`). Hiding a button in React is not a permission check.
4. **Every HR model uses `LogsActivity`.** Don't write with `DB::table()` or `Model::query()->update()` outside the importer, or the change won't be logged.
5. **No real HR data in the repo, tests, fixtures, screenshots or logs.** Tests build fake workbooks with `tests/Support/XlsxBuilder.php`.
6. **Strict Eloquent is on** outside production: eager-load relations (`with()`), no lazy loading. New DB columns with defaults also need the default in the model's `$attributes`.
7. **Props contract:** controllers send dates as `Y-m-d` strings and timestamps as ISO 8601. Keep `resources/js/types/index.ts` in step with what controllers send.
8. **Checklist items** are data. A new document type or onboarding step is a row added in Settings (or `ChecklistItemSeeder` for defaults), not a migration. Keys in `HrRules::REQUIRED_KEYS` must never be removed.
9. **UI copy:** sentence case, plain words HR staff use, buttons say what they do ("Save plan", not "Submit").

## Definition of done
Tests pass, `tsc` is clean, `npm run build` works, and any changed screen has been opened in a browser.
