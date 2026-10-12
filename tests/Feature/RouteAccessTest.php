<?php

namespace Tests\Feature;

use App\Models\ChecklistItem;
use App\Models\ControlledDocument;
use App\Models\EmployeeFile;
use App\Models\EmploymentHistory;
use App\Models\Holiday;
use App\Models\LeaveRecord;
use App\Models\LeaveType;
use App\Models\Offboarding;
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
            if (in_array('can:edit-hr', $middleware, true) || in_array('can:admin', $middleware, true)) {
                continue;
            }
            foreach (array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS', 'QUERY']) as $method) {
                $key = $method.' '.$route->uri();
                if (! in_array($key, self::OPEN_WRITE_ROUTES, true)) {
                    $ungated[] = $key;
                }
            }
        }
        $this->assertSame([], $ungated, 'Write routes must sit in a can:edit-hr or can:admin group.');
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
        [$company, $dept] = $this->baseData();
        $e = $this->employee($company);
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
