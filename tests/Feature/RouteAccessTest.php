<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AttendanceRequest;
use App\Models\ChecklistItem;
use App\Models\ControlledDocument;
use App\Models\EmployeeFile;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmploymentHistory;
use App\Models\Holiday;
use App\Models\LeaveRecord;
use App\Models\LeaveType;
use App\Models\Offboarding;
use App\Models\Review;
use App\Models\ReviewCycle;
use App\Models\Team;
use App\Models\WorkSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\RedirectController;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Walks every registered route, so a new write route outside a gated group fails here. */
class RouteAccessTest extends TestCase
{
    use RefreshDatabase;

    /** Write routes any logged-in (or guest) user may call. Add to this list only on purpose. */
    private const OPEN_WRITE_ROUTES = [
        'POST login',
        'POST logout',
        'POST forgot-password',
        'POST reset-password',
        'PUT account',
    ];

    public function test_every_write_route_is_gated_or_explicitly_allowed(): void
    {
        $ungated = [];
        foreach (Route::getRoutes() as $route) {
            // Route::redirect('/', '/dashboard') answers every verb but never writes.
            if (ltrim(strtok($route->getActionName(), '@'), '\\') === RedirectController::class) {
                continue;
            }
            $middleware = $route->gatherMiddleware();
            // can:self-service writes only ever touch the login's own employee (MeController).
            if (array_intersect(['can:edit-hr', 'can:admin', 'can:self-service'], $middleware)) {
                continue;
            }
            foreach (array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS', 'QUERY']) as $method) {
                $key = $method.' '.$route->uri();
                if (! in_array($key, self::OPEN_WRITE_ROUTES, true)) {
                    $ungated[] = $key;
                }
            }
        }
        $this->assertSame([], $ungated, 'Write routes must sit in a can:edit-hr, can:admin or can:self-service group.');
    }

    public function test_every_route_outside_my_page_is_closed_to_employee_logins(): void
    {
        $routes = $this->gatedRoutes('view-hr');
        $employee = $this->user('employee', ['employee_id' => Employee::first()->id]);
        $bad = [];
        foreach ($routes as [$method, $uri]) {
            $uri = preg_replace('#^/export/\d+$#', '/export/employees', $uri);   // {type} is a leave type id elsewhere
            $status = $this->actingAs($employee)->call($method, $uri, [])->status();
            if ($status !== 403) {
                $bad[] = "$method $uri => $status";
            }
        }
        $this->assertSame([], $bad);
        $this->assertGreaterThan(60, count($routes), 'every HR route sits behind can:view-hr');

        // The only routes without can:view-hr are login/logout, the home redirect, own account and My page.
        $open = [];
        foreach (Route::getRoutes() as $route) {
            if (! in_array('can:view-hr', $route->gatherMiddleware(), true)) {
                $open[] = $route->uri();
            }
        }
        $expected = ['/', 'account', 'cron/reminders/{token}', 'cron/machine/{token}', 'login', 'logout', 'forgot-password', 'reset-password', 'reset-password/{token}', 'me', 'me/contact', 'me/requests', 'me/requests/{correction}',
            'me/reviews/{review}', 'me/clock', 'me/reviews/{review}/acknowledge', 'me/photo', 'me/leave-requests', 'me/leave-requests/{leaveRequest}', 'me/leave-requests/{leaveRequest}/file', 'me/letters/{letter}', 'employees/{employee}/photo', 'storage/{path}', 'up'];
        $this->assertSame([], array_values(array_diff(array_unique($open), $expected)));
    }

    public function test_viewer_gets_403_on_every_edit_hr_route(): void
    {
        $routes = $this->gatedRoutes('edit-hr');
        $viewer = $this->user('viewer');
        $bad = [];
        foreach ($routes as [$method, $uri]) {
            $status = $this->actingAs($viewer)->call($method, $uri, [])->status();
            if ($status !== 403) {
                $bad[] = "$method $uri => $status";
            }
        }
        $this->assertSame([], $bad);
        $this->assertGreaterThan(15, count($routes));
    }

    public function test_hr_and_viewer_get_403_on_every_admin_route(): void
    {
        $routes = $this->gatedRoutes('admin');
        $bad = [];
        foreach (['hr', 'viewer'] as $role) {
            $user = $this->user($role);
            foreach ($routes as [$method, $uri]) {
                $status = $this->actingAs($user)->call($method, $uri, [])->status();
                if ($status !== 403) {
                    $bad[] = "$role $method $uri => $status";
                }
            }
        }
        $this->assertSame([], $bad);
        $this->assertGreaterThan(10, count($routes));
    }

    /** Every route behind can:$gate, with real ids bound. */
    private function gatedRoutes(string $gate): array
    {
        if (! Employee::exists()) {
            [$company, $dept] = $this->baseData();
            $this->employee($company);
        }
        $e = Employee::first();
        $dept ??= Department::first();
        $ids = [
            'employee' => $e->id,
            'item' => ChecklistItem::where('is_active', true)->first()->id,
            'offboarding' => Offboarding::create(['employee_id' => $e->id, 'type' => 'Other'])->id,
            'document' => ControlledDocument::create(['doc_number' => 'BCC-0001', 'name' => 'Test policy', 'doc_type' => 'Policy', 'status' => 'Active'])->id,
            'user' => $this->user('viewer')->id,
            'department' => $dept->id,
            'team' => Team::create(['department_id' => $dept->id, 'name' => 'Test team'])->id,
            'file' => EmployeeFile::create(['employee_id' => $e->id, 'original_name' => 'x.pdf', 'path' => 'employee-files/none.pdf', 'mime' => 'application/pdf', 'size' => 1])->id,
            'schedule' => WorkSchedule::create(['name' => 'Test schedule', 'start_time' => '10:00', 'end_time' => '18:00', 'working_days' => [1, 2, 3, 4, 5]])->id,
            'date' => '2026-05-13',
            'record' => LeaveRecord::create(['employee_id' => $e->id, 'leave_type_id' => LeaveType::first()->id, 'start_date' => '2026-05-13', 'end_date' => '2026-05-13'])->id,
            'holiday' => Holiday::create(['date' => '2026-08-14', 'name' => 'Test holiday'])->id,
            'type' => LeaveType::first()->id,
            'history' => EmploymentHistory::create(['employee_id' => $e->id, 'effective_date' => '2026-01-01', 'change_type' => 'Other'])->id,
            'correction' => AttendanceRequest::create(['employee_id' => $e->id, 'date' => '2026-05-13', 'check_in' => '09:00', 'reason' => 'Test'])->id,
            'report' => 'headcount',
            'kind' => 'details',
            'template' => \App\Models\LetterTemplate::first()->id,
            'letter' => \App\Models\IssuedLetter::create(['employee_id' => $e->id, 'reference' => 'T/HR/1', 'body' => 'x', 'issued_on' => '2026-05-13'])->id,
            'asset' => Asset::create(['asset_tag' => 'T-1', 'type' => 'Laptop', 'name' => 'Test laptop'])->id,
            'cycle' => $cycle = ReviewCycle::create(['name' => 'Test cycle', 'period_start' => '2026-01-01', 'period_end' => '2026-06-30', 'criteria' => config('hrm.reviews.default_criteria')])->id,
            'review' => Review::create(['review_cycle_id' => $cycle, 'employee_id' => $e->id])->id,
            'network' => \App\Models\OfficeNetwork::create(['name' => 'Test line', 'address' => '198.51.100.1'])->id,
            'device' => \App\Models\AttendanceDevice::create(['name' => 'Test machine', 'host' => '203.0.113.10'])->id,
            'category' => \App\Models\Category::create(['name' => 'Test category'])->id,
            'leaveRequest' => \App\Models\LeaveRequest::create(['employee_id' => $e->id, 'leave_type_id' => LeaveType::first()->id, 'start_date' => '2026-05-13', 'end_date' => '2026-05-13'])->id,
        ];
        $out = [];
        foreach (Route::getRoutes() as $route) {
            if (! in_array("can:$gate", $route->gatherMiddleware(), true)) {
                continue;
            }
            $uri = preg_replace_callback('/\{(\w+)\}/', fn ($m) => $ids[$m[1]], $route->uri());
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $out[] = [$method, '/'.$uri];
            }
        }

        return $out;
    }
}
