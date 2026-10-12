# Moving from the Google Sheet to the app

`php artisan hr:import` moves `HR_Onboarding.xlsx` into the database. It is meant to run once, before go-live. After that the app is the only place HR edits records.

```
php artisan hr:import storage/app/private/import/HR_Onboarding.xlsx          # first run
php artisan hr:import storage/app/private/import/HR_Onboarding.xlsx --fresh  # re-run (wipes HR data, keeps logins)
```

Everything runs in one database transaction: if anything fails, nothing is saved. Every decision the importer makes is written to `storage/app/private/import/import-report-*.csv`, one row per decision.

## What comes from where

| Sheet | Used for | Notes |
|---|---|---|
| BCC Employee teams Departments | Companies, departments, teams, employees `BCC-001...` | The employee master. Codes come from the ID column. |
| Emerge Employee teams Departmen | Employees `Emerge-001...` | Same layout. |
| BCC Employee teams Departments (trailing space) | Not imported | Older copy with a proposed restructure (columns L-N). |
| Onboarding_Tracker | Onboarding rows, onboarding checklist, start date, employment type | Joined to employees by name (see matching). |
| Onboarding_Document_Checklist | Document checklist, Drive folder, HR portal and attendance IDs | Joined by name. |
| Controlled_Documents | Controlled document register | |
| Templates | Controlled documents of type Template, status Draft | No files existed, add links in the app. |
| HR_Database | First and last name, CNIC (only when filled) | Was empty. |
| Offboarding_Tracker | Offboarding cases (only rows with a name) | Had 2 rows with a type and no name; reported as skipped. |
| Info, Dashboard, Employee_Checklist, 90_Day_Plan_Template, Exit_Interview_Template, Offboarding_Document_Checklist | Not imported as data | Their structure became app features: the dashboard, the onboarding checklist items, the 90-day plan form, and the exit interview notes. |
| HR_Offboarding.xlsx (separate file) | Nothing | The file was empty. |

### Column mapping

| Sheet column | App |
|---|---|
| Tracker: Group | `onboardings.cohort` |
| Tracker: Start_Date | `employees.start_date` (only if empty) |
| Tracker: Probation_End_Date | `onboardings.probation_end_date` (if blank: start date + 90 days) |
| Tracker: Employment_Type | `employees.employment_type` |
| Tracker: Email_Created, Desk_Allocation, System_Access_Setup, Contact_Info, HR_ Database | onboarding checklist items |
| Tracker: Orientation_Session_1-3 | onboarding checklist items (Done / Not Started) |
| Tracker: Day30, Day60, Day90 | onboarding checklist items |
| Tracker: Probation_Result, Notes, 90_Day_Plan_Link | `onboardings.probation_result`, `notes`, `plan_url` |
| Tracker: CNIC, NDA_Signed, NCA_Signed, Policy_Acknowledgment, Appointment_Letter_Issuance | merged into the matching document items (see below) |
| Tracker: Document_Checklist, Orientation_Status, Onboarding Status | not imported, computed live (they were formulas) |
| Checklist: CNIC Copy ... Laptop/Asset Issued (13 columns) | document checklist items |
| Checklist: Drive Folder Link, HR Portal ID, Attendance machine, Attendance machine ID | `employees` fields (only if empty) |
| Checklist: Employee_ID, Department, Start_Date, Status | not imported where they were VLOOKUP or status formulas; hand-typed start dates are used if the employee has none |

## Facts that were typed twice

NDA, NCA, policy acknowledgment, CNIC and appointment letter appear in both the tracker and the document checklist, often with different values. In the app each fact exists once, as a document item. The rule:

1. The document checklist value wins.
2. If the document checklist is blank for that person, the tracker value fills it.
3. If both have a value and one means done while the other means not done, the checklist value is kept and the disagreement is written to the report as `conflict`.

On your workbook: 228 conflicts. 76 people each have NDA, NCA and policy marked "No" in the tracker, which look like bulk-filled defaults, while the document checklist says "Yes" (NDA, NCA) or "Not Required" (policy). 16 blank document values were filled from the tracker.

## Name matching

Names typed in the onboarding sheets are matched to the employee master, strictest rule first:

| Level | Example | Result |
|---|---|---|
| alias | listed in `aliases.csv` | linked |
| exact | `sana tariq` = `Sana Tariq` | linked |
| normalized | `M. Danish` = `Muhmmad Danish` = `Muhammad Danish` | linked |
| spelling | `Qadir` = `Qaddir`, `Umme Kulsoom` = `Umm-e-Kulsoom` | linked |
| fuzzy | `Siama` ~ `Saima` (same word count, 1-2 letter typo per word) | linked, flagged "Check record" |
| subset | `Zubair Hassan` ~ `Zubair Hassan Malik` (2+ distinctive words) | linked, flagged |
| hint | `Muhmmad Faisal ( developer )` picks the Faisal in Developers | linked, flagged |
| ambiguous | two people with the same name on the BCC list | row skipped, reported |
| none | not on any department list | new employee `UNASSIGNED-###`, flagged |

On your workbook: 233 exact, 21 normalized, 16 spelling, 21 fuzzy, 13 subset, 2 hint, 2 ambiguous rows, 37 new people (19 only on the document checklist, 18 only on the tracker).

"Muhammad" and "Syed" don't count as distinctive words, so `Muhammad Talha Shah` is never matched to `M. Tariq Shah`.

### Fixing matches before go-live

1. Open the report CSV in Excel and filter the `result` column for `ambiguous`, `bad_alias`, `created`, `conflict`, `duplicate_id`, `invalid_value` and `manager_not_linked`, and the `match_type` column for `fuzzy`, `subset` and `hint`.
2. For any wrong or ambiguous match, add a line to `storage/app/private/import/aliases.csv`:

   ```
   source_name,employee_code
   Bilal Ahmed,BCC-012
   Nida Qadir,BCC-035
   ```

   `source_name` is the name as typed in the sheet (copy the report's `source_name`), `employee_code` is the right person's code. Case and extra spaces don't matter. A name with a bracket hint can have its own line, so two people with the same name can be told apart:

   ```
   Ali Raza (night shift),BCC-002
   Ali Raza (day shift),BCC-001
   ```

   The alias for the full name (with the hint) is tried first, then the alias for the bare name. If the code doesn't exist, the row is reported as `bad_alias` and not imported.
3. Run the import again with `--fresh`.

After go-live, fix the rest in the app: the Employees page has a "Needs a check" filter, and each flagged profile says why it was flagged. Setting a company on an `UNASSIGNED-###` person gives them that company's next code.

## Bugs found in the sheet

| Where | Problem | Effect |
|---|---|---|
| Dashboard rows 32-44 (Missing count, Completion %) | Every row reads column J (CNIC) with a shifting start row instead of its own column | Every document showed 0 missing and 100% complete. Real numbers: emergency contact 121 "No", bank details 64 "No" + 27 "Pending", experience letters 40 "No" |
| Tracker column K (Document_Checklist) | `VLOOKUP(..., 19, 0)` returns column T (Policy Acknowledgment), not W (Status), and its range stops at row 106 | Showed "Not Required" instead of the document status |
| Dashboard B12 (Pending NDA/NCA) | Adds three column counts (Policy + NDA + NCA) | 243 counts people up to 3 times |
| Dashboard B4 vs B24 | Tracker has 152 rows, checklist 193; the two lists were never in sync | Two different "Total Employees" |
| All cross-tab lookups | Joined on the typed name | 53 of 150 tracker names didn't match the department list exactly |
| Dashboard B13 (Upcoming probation) | Counts only an explicit "Pending" result | Blank results (172 people) never show up |
| Onboarding_Document_Checklist B111:B213 | Name dropdowns are typed lists, not linked to the tracker | New hires had to be typed twice |

## Sheet vs app, same data

| Metric | Sheet dashboard | App | Why it differs |
|---|---|---|---|
| Orientation complete | 28 | 28 | |
| Probation passed | 23 | 23 | |
| Fully document-compliant | 17 | 17 | |
| Pending NDA / NCA / policy | 81 / 81 / 81 | 13 / 13 / 25 | The app reads the signed-document checklist, not the tracker's bulk "No" |
| Missing bank details | 0 | 64 No + 27 Pending | Sheet formula read the CNIC column |
| Missing emergency contact | 0 | 121 No | Same formula bug |
| People in onboarding | 152 rows | 195 people | 149 people from the tracker (152 rows minus 2 duplicates and 1 ambiguous) plus 46 who were only on the document checklist |
| Employees | 152 / 193 | 284 | 247 on the department lists plus 37 found only on the onboarding sheets |

Per-document counts differ from the raw sheet by -3 on most columns: one ambiguous row (not imported) and two duplicate rows (same person entered twice, merged into one record).
