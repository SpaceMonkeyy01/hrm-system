<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AttendanceDeviceController;
use App\Http\Controllers\AttendanceRequestController;
use App\Http\Controllers\AttendanceUploadController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\ChecklistEntryController;
use App\Http\Controllers\ClockInController;
use App\Http\Controllers\ControlledDocumentController;
use App\Http\Controllers\CronController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentChecklistController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EmployeeFileController;
use App\Http\Controllers\EmploymentHistoryController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\LeaveController;
use App\Http\Controllers\LetterController;
use App\Http\Controllers\MeController;
use App\Http\Controllers\OffboardingController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\OrgChartController;
use App\Http\Controllers\OverviewController;
use App\Http\Controllers\PhotoController;
use App\Http\Controllers\PunchLogController;
use App\Http\Controllers\ProbationPlanController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SpreadsheetImportController;
use App\Http\Controllers\Settings\ChecklistItemController;
use App\Http\Controllers\Settings\StaffLoginController;
use App\Http\Controllers\Settings\StructureController;
use App\Http\Controllers\Settings\UserController;
use App\Http\Controllers\WorkScheduleController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
| Permissions
|   auth          any active login: own account and My page
|   can:view-hr   admin, hr and viewer can read HR records (employee logins can't)
|   can:self-service  a login linked to an employee can update their own contact details and ask for fixes
|   can:edit-hr   admin + hr can change HR records
|   can:admin     admin only: users, departments/teams, checklist items, deleting employees
*/

// Daily reminder email for hosts without a scheduler (CronController). 404 unless CRON_TOKEN is set and matches.
// Render's generated CRON_TOKEN is base64 and can contain "/", so the token may span several path segments.
Route::get('cron/reminders/{token}', [CronController::class, 'reminders'])->where('token', '.+')->middleware('throttle:10,1')->name('cron.reminders');
Route::get('cron/machine/{token}', [CronController::class, 'machine'])->where('token', '.+')->middleware('throttle:10,1')->name('cron.machine');

Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->name('login.store');
    Route::get('forgot-password', [PasswordController::class, 'request'])->name('password.request');
    Route::post('forgot-password', [PasswordController::class, 'email'])->middleware('throttle:5,1')->name('password.email');
    Route::get('reset-password/{token}', [PasswordController::class, 'edit'])->name('password.reset');
    Route::post('reset-password', [PasswordController::class, 'update'])->middleware('throttle:10,1')->name('password.update');
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');
    Route::get('/', fn (Request $request) => redirect($request->user()->homeUrl()))->name('home');

    // Own account
    Route::get('account', [AccountController::class, 'edit'])->name('account.edit');
    Route::put('account', [AccountController::class, 'update'])->name('account.update');

    // Profile photo: HR-side logins see everyone's, an employee login only their own (PhotoController::show).
    Route::get('employees/{employee}/photo', [PhotoController::class, 'show'])->whereNumber('employee')->name('photo.show');

    // My page: any login linked to an employee sees and updates only their own record.
    // The page itself opens for every login (it explains when the login isn't linked yet); writes need the link.
    Route::get('me', [MeController::class, 'show'])->name('me.show');
    Route::middleware('can:self-service')->group(function () {
        Route::put('me/contact', [MeController::class, 'updateContact'])->name('me.contact');
        Route::post('me/photo', [PhotoController::class, 'updateMine'])->name('me.photo');
        Route::post('me/clock', [MeController::class, 'clock'])->middleware('throttle:20,1')->name('me.clock');
        Route::post('me/requests', [MeController::class, 'storeRequest'])->name('me.requests.store');
        Route::delete('me/requests/{correction}', [MeController::class, 'cancelRequest'])->name('me.requests.cancel');
        Route::put('me/reviews/{review}', [MeController::class, 'saveSelfReview'])->name('me.reviews.save');
        Route::post('me/reviews/{review}/acknowledge', [MeController::class, 'acknowledgeReview'])->name('me.reviews.acknowledge');
    });

    // Everything below is HR records: admin, hr and viewer logins only. Employee logins get 403.
    Route::middleware('can:view-hr')->group(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');
        Route::get('search/employees', [SearchController::class, 'employees'])->middleware('throttle:120,1')->name('search.employees');
        Route::get('overview', OverviewController::class)->name('overview');

        // Reports (app/Services/Reports/ReportCatalog.php). Private reports check edit-hr themselves.
        Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('reports/{report}/export', [ReportController::class, 'export'])->name('reports.export');
        Route::get('reports/{report}', [ReportController::class, 'show'])->name('reports.show');

        // Employees
        Route::get('employees', [EmployeeController::class, 'index'])->name('employees.index');
        Route::middleware('can:edit-hr')->group(function () {
            Route::get('employees/create', [EmployeeController::class, 'create'])->name('employees.create');
            // Spreadsheet imports: download, fill in Excel, upload, preview, save (SpreadsheetImportController::KINDS)
            Route::get('imports/{kind}', [SpreadsheetImportController::class, 'show'])->whereIn('kind', ['details', 'assets'])->name('imports.show');
            Route::get('imports/{kind}/template', [SpreadsheetImportController::class, 'template'])->whereIn('kind', ['details', 'assets'])->name('imports.template');
            Route::post('imports/{kind}', [SpreadsheetImportController::class, 'upload'])->whereIn('kind', ['details', 'assets'])->name('imports.upload');
            Route::post('imports/{kind}/apply', [SpreadsheetImportController::class, 'apply'])->whereIn('kind', ['details', 'assets'])->name('imports.apply');
            Route::delete('imports/{kind}', [SpreadsheetImportController::class, 'discard'])->whereIn('kind', ['details', 'assets'])->name('imports.discard');
            Route::post('employees', [EmployeeController::class, 'store'])->name('employees.store');
            Route::get('employees/{employee}/edit', [EmployeeController::class, 'edit'])->name('employees.edit');
            Route::put('employees/{employee}', [EmployeeController::class, 'update'])->name('employees.update');
            Route::post('employees/{employee}/history', [EmploymentHistoryController::class, 'store'])->name('history.store');
            Route::post('employees/{employee}/photo', [PhotoController::class, 'update'])->name('photo.update');
            // Letters (LetterController): draft, issue, print. HR and admin only: letters carry CNIC numbers.
            Route::get('employees/{employee}/letters/new', [LetterController::class, 'draft'])->name('letters.draft');
            Route::post('employees/{employee}/letters', [LetterController::class, 'issue'])->name('letters.issue');
            Route::get('letters/templates', [LetterController::class, 'templates'])->name('letters.templates');
            Route::post('letters/templates', [LetterController::class, 'storeTemplate'])->name('letters.templates.store');
            Route::put('letters/templates/{template}', [LetterController::class, 'updateTemplate'])->name('letters.templates.update');
            Route::get('letters/{letter}', [LetterController::class, 'show'])->whereNumber('letter')->name('letters.show');
            Route::delete('employees/{employee}/photo', [PhotoController::class, 'destroy'])->name('photo.destroy');
            // Scanned documents: reading them is HR-only too (CNIC copies, contracts).
            Route::post('employees/{employee}/files', [EmployeeFileController::class, 'store'])->name('files.store');
            Route::get('employees/{employee}/files/{file}', [EmployeeFileController::class, 'show'])->name('files.show');
            Route::delete('employees/{employee}/files/{file}', [EmployeeFileController::class, 'destroy'])->name('files.destroy');
        });
        Route::delete('employees/{employee}', [EmployeeController::class, 'destroy'])->middleware('can:admin')->name('employees.destroy');
        Route::delete('employees/{employee}/history/{history}', [EmploymentHistoryController::class, 'destroy'])->middleware('can:admin')->name('history.destroy');
        Route::delete('letters/{letter}', [LetterController::class, 'destroy'])->whereNumber('letter')->middleware('can:admin')->name('letters.destroy');
        Route::get('employees/{employee}', [EmployeeController::class, 'show'])->name('employees.show');

        // Onboarding tracker + document checklist (grids)
        Route::get('onboarding', [OnboardingController::class, 'index'])->name('onboarding.index');
        Route::get('documents', [DocumentChecklistController::class, 'index'])->name('documents.index');
        Route::middleware('can:edit-hr')->group(function () {
            Route::put('onboarding/{employee}', [OnboardingController::class, 'update'])->name('onboarding.update');
            Route::post('onboarding/{employee}/start', [OnboardingController::class, 'start'])->name('onboarding.start');
            Route::put('checklist/{employee}/{item}', [ChecklistEntryController::class, 'update'])->name('checklist.update');
            Route::post('checklist/bulk', [ChecklistEntryController::class, 'bulk'])->name('checklist.bulk');
        });

        // 90-day probation plan
        Route::get('employees/{employee}/plan', [ProbationPlanController::class, 'show'])->name('plan.show');
        Route::middleware('can:edit-hr')->group(function () {
            Route::post('employees/{employee}/plan', [ProbationPlanController::class, 'store'])->name('plan.store');
            Route::put('employees/{employee}/plan', [ProbationPlanController::class, 'update'])->name('plan.update');
            Route::post('employees/{employee}/plan/checkins', [ProbationPlanController::class, 'addCheckin'])->name('plan.checkins.store');
        });

        // Offboarding
        Route::get('offboarding', [OffboardingController::class, 'index'])->name('offboarding.index');
        Route::middleware('can:edit-hr')->group(function () {
            Route::post('offboarding', [OffboardingController::class, 'store'])->name('offboarding.store');
            Route::put('offboarding/{offboarding}', [OffboardingController::class, 'update'])->name('offboarding.update');
            Route::post('offboarding/{offboarding}/complete', [OffboardingController::class, 'complete'])->name('offboarding.complete');
            Route::post('offboarding/{offboarding}/cancel', [OffboardingController::class, 'cancel'])->name('offboarding.cancel');
        });
        Route::get('offboarding/{offboarding}', [OffboardingController::class, 'show'])->name('offboarding.show');

        // Attendance (rules: app/Services/AttendanceRules.php)
        Route::get('attendance', [AttendanceController::class, 'index'])->name('attendance.index');
        Route::get('attendance/schedules', [WorkScheduleController::class, 'index'])->name('schedules.index');
        Route::middleware('can:edit-hr')->group(function () {
            Route::get('attendance/imports', [AttendanceController::class, 'imports'])->name('attendance.imports');
            Route::post('attendance/imports', [AttendanceController::class, 'upload'])->name('attendance.upload');
            Route::post('attendance/imports/check', [AttendanceUploadController::class, 'check'])->name('attendance.check');
            Route::post('attendance/imports/confirm', [AttendanceUploadController::class, 'confirm'])->name('attendance.confirm');
            Route::post('attendance/imports/discard', [AttendanceUploadController::class, 'discard'])->name('attendance.discard');
            Route::post('attendance/machine/sync', [AttendanceUploadController::class, 'syncMachine'])->middleware('throttle:6,1')->name('attendance.machine.sync');
            Route::post('attendance/link', [AttendanceController::class, 'link'])->name('attendance.link');
            Route::get('attendance/requests', [AttendanceRequestController::class, 'index'])->name('attendance.requests');
            // App clock-ins: addresses and locations are personal data, so HR and admin only.
            Route::get('attendance/clock-ins', [ClockInController::class, 'index'])->name('attendance.clock-ins');
            Route::get('attendance/punches', [PunchLogController::class, 'index'])->name('attendance.punches');
            Route::get('attendance/devices', [AttendanceDeviceController::class, 'index'])->name('attendance.devices');
            Route::post('attendance/devices', [AttendanceDeviceController::class, 'store'])->name('attendance.devices.store');
            Route::put('attendance/devices/{device}', [AttendanceDeviceController::class, 'update'])->name('attendance.devices.update');
            Route::delete('attendance/devices/{device}', [AttendanceDeviceController::class, 'destroy'])->name('attendance.devices.destroy');
            Route::post('attendance/devices/{device}/test', [AttendanceDeviceController::class, 'test'])->middleware('throttle:10,1')->name('attendance.devices.test');
            Route::post('attendance/devices/{device}/sync', [AttendanceDeviceController::class, 'sync'])->middleware('throttle:6,1')->name('attendance.devices.sync');
            Route::get('attendance/punches/csv', [PunchLogController::class, 'csv'])->name('attendance.punches.csv');
            Route::post('attendance/networks', [ClockInController::class, 'storeNetwork'])->name('attendance.networks.store');
            Route::delete('attendance/networks/{network}', [ClockInController::class, 'destroyNetwork'])->name('attendance.networks.destroy');
            Route::post('attendance/requests/{correction}/approve', [AttendanceRequestController::class, 'approve'])->name('attendance.requests.approve');
            Route::post('attendance/requests/{correction}/reject', [AttendanceRequestController::class, 'reject'])->name('attendance.requests.reject');
            Route::post('attendance/schedules', [WorkScheduleController::class, 'store'])->name('schedules.store');
            Route::put('attendance/schedules/{schedule}', [WorkScheduleController::class, 'update'])->name('schedules.update');
            Route::delete('attendance/schedules/{schedule}', [WorkScheduleController::class, 'destroy'])->name('schedules.destroy');
            Route::post('attendance/schedules/{schedule}/assign', [WorkScheduleController::class, 'assign'])->name('schedules.assign');
            Route::put('attendance/{employee}/{date}', [AttendanceController::class, 'adjust'])->whereNumber('employee')->name('attendance.adjust');
            Route::delete('attendance/{employee}/{date}', [AttendanceController::class, 'clearAdjustment'])->whereNumber('employee')->name('attendance.clear');
        });

        // Leave and holidays (approvals stay in HR.my; this records the outcome)
        Route::get('leave', [LeaveController::class, 'index'])->name('leave.index');
        Route::middleware('can:edit-hr')->group(function () {
            Route::post('leave', [LeaveController::class, 'store'])->name('leave.store');
            Route::delete('leave/{record}', [LeaveController::class, 'destroy'])->whereNumber('record')->name('leave.destroy');
            Route::post('leave/holidays', [LeaveController::class, 'storeHoliday'])->name('holidays.store');
            Route::delete('leave/holidays/{holiday}', [LeaveController::class, 'destroyHoliday'])->name('holidays.destroy');
            Route::post('leave/types', [LeaveController::class, 'storeType'])->name('leave-types.store');
            Route::put('leave/types/{type}', [LeaveController::class, 'updateType'])->name('leave-types.update');
        });

        // Asset register (status rules: AssetController)
        Route::get('assets', [AssetController::class, 'index'])->name('assets.index');
        Route::middleware('can:edit-hr')->group(function () {
            Route::post('assets', [AssetController::class, 'store'])->name('assets.store');
            Route::put('assets/{asset}', [AssetController::class, 'update'])->name('assets.update');
            Route::post('assets/{asset}/assign', [AssetController::class, 'assign'])->name('assets.assign');
            Route::post('assets/{asset}/return', [AssetController::class, 'return'])->name('assets.return');
        });

        // Performance reviews: HR and admin only, even for reading (ratings are as private as pay). Rules: ReviewRules.
        Route::middleware('can:edit-hr')->group(function () {
            Route::get('reviews', [ReviewController::class, 'index'])->name('reviews.index');
            Route::post('reviews', [ReviewController::class, 'store'])->name('reviews.store');
            Route::get('reviews/{cycle}', [ReviewController::class, 'show'])->name('reviews.show');
            Route::put('reviews/{cycle}', [ReviewController::class, 'update'])->name('reviews.update');
            Route::post('reviews/{cycle}/people', [ReviewController::class, 'addPeople'])->name('reviews.people');
            Route::post('reviews/{cycle}/open', [ReviewController::class, 'open'])->name('reviews.open');
            Route::post('reviews/{cycle}/close', [ReviewController::class, 'close'])->name('reviews.close');
            Route::get('reviews/{cycle}/people/{review}', [ReviewController::class, 'review'])->name('reviews.review');
            Route::put('reviews/{cycle}/people/{review}', [ReviewController::class, 'saveReview'])->name('reviews.save');
            Route::post('reviews/{cycle}/people/{review}/skip-self', [ReviewController::class, 'skipSelf'])->name('reviews.skip-self');
            Route::delete('reviews/{cycle}/people/{review}', [ReviewController::class, 'remove'])->name('reviews.remove');
        });

        // Org chart
        Route::get('org-chart', OrgChartController::class)->name('org-chart');

        // Controlled documents
        Route::get('controlled-documents', [ControlledDocumentController::class, 'index'])->name('controlled-documents.index');
        Route::middleware('can:edit-hr')->group(function () {
            Route::post('controlled-documents', [ControlledDocumentController::class, 'store'])->name('controlled-documents.store');
            Route::put('controlled-documents/{document}', [ControlledDocumentController::class, 'update'])->name('controlled-documents.update');
        });
        Route::delete('controlled-documents/{document}', [ControlledDocumentController::class, 'destroy'])->middleware('can:admin')->name('controlled-documents.destroy');

        // CSV exports (same numbers as the screens)
        Route::get('export/{type}', ExportController::class)->whereIn('type', ['employees', 'onboarding', 'documents'])->name('export');

        // Settings (admin)
        Route::prefix('settings')->name('settings.')->middleware('can:admin')->group(function () {
            Route::get('users', [UserController::class, 'index'])->name('users.index');
            Route::post('users', [UserController::class, 'store'])->name('users.store');
            Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::post('users/staff-logins', [StaffLoginController::class, 'store'])->name('users.staff-logins');

            Route::get('structure', [StructureController::class, 'index'])->name('structure.index');
            Route::post('departments', [StructureController::class, 'storeDepartment'])->name('departments.store');
            Route::put('departments/{department}', [StructureController::class, 'updateDepartment'])->name('departments.update');
            Route::delete('departments/{department}', [StructureController::class, 'destroyDepartment'])->name('departments.destroy');
            Route::post('teams', [StructureController::class, 'storeTeam'])->name('teams.store');
            Route::put('teams/{team}', [StructureController::class, 'updateTeam'])->name('teams.update');
            Route::delete('teams/{team}', [StructureController::class, 'destroyTeam'])->name('teams.destroy');

            Route::get('checklists', [ChecklistItemController::class, 'index'])->name('checklists.index');
            Route::post('checklists', [ChecklistItemController::class, 'store'])->name('checklists.store');
            Route::put('checklists/{item}', [ChecklistItemController::class, 'update'])->name('checklists.update');
        });
    });
});
