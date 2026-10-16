<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\LeaveRecord;
use App\Models\LeaveType;
use App\Models\User;
use App\Notifications\SetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Settings > Users: delete unused logins, send a password link, last login, switch off leavers. */
class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->company] = $this->baseData();
    }

    public function test_history_columns_cover_every_foreign_key_to_users(): void
    {
        $found = [];
        foreach (Schema::getTables() as $t) {
            foreach (Schema::getForeignKeys($t['name']) as $fk) {
                if ($fk['foreign_table'] === 'users') {
                    $found[] = $t['name'].'.'.$fk['columns'][0];
                }
            }
        }
        $listed = [];
        foreach (User::HISTORY_COLUMNS as $table => $columns) {
            foreach ($columns as $c) {
                $listed[] = "$table.$c";
            }
        }
        $this->assertEqualsCanonicalizing($found, $listed, 'Add the new column to User::HISTORY_COLUMNS.');
    }

    public function test_only_a_login_with_no_history_can_be_deleted(): void
    {
        $admin = $this->user('admin');
        $this->actingAs($admin)->post('/settings/users', ['name' => 'Typo', 'email' => 'tpyo@example.com', 'role' => 'viewer', 'password' => 'Secret-123']);
        $typo = User::where('email', 'tpyo@example.com')->sole();
        $worker = $this->user('hr');
        $type = LeaveType::firstOrCreate(['name' => 'Casual']);
        $this->actingAs($worker);
        LeaveRecord::create(['employee_id' => $this->employee($this->company)->id, 'leave_type_id' => $type->id, 'start_date' => '2026-05-04',
            'end_date' => '2026-05-04', 'part' => 'full', 'days' => 1, 'created_by' => $worker->id]);

        $this->actingAs($admin)->get('/settings/users')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('users', fn ($u) => collect($u)->pluck('has_history', 'email')->only([$typo->email, $worker->email, $admin->email])->all()
                == [$admin->email => true, $worker->email => true, $typo->email => false]));

        $this->actingAs($this->user('hr'))->delete("/settings/users/{$typo->id}")->assertForbidden();
        $this->actingAs($admin)->delete("/settings/users/{$worker->id}")->assertSessionHasErrors('user');
        $this->actingAs($admin)->delete("/settings/users/{$admin->id}")->assertSessionHasErrors('user');
        $this->actingAs($admin)->delete("/settings/users/{$typo->id}")->assertSessionHas('success');
        $this->assertNull(User::find($typo->id));
        $this->assertNotNull(User::find($worker->id));
    }

    public function test_send_link_uses_the_welcome_wording_until_they_have_logged_in(): void
    {
        Notification::fake();
        $admin = $this->user('admin');
        $new = $this->user('viewer');
        $used = $this->user('viewer', ['last_login_at' => now()]);
        $off = $this->user('viewer', ['is_active' => false]);

        $this->actingAs($admin)->post("/settings/users/{$new->id}/send-link")->assertSessionHas('success');
        $this->actingAs($admin)->post("/settings/users/{$used->id}/send-link")->assertSessionHas('success');
        $this->actingAs($admin)->post("/settings/users/{$off->id}/send-link")->assertSessionHasErrors('user');
        $this->actingAs($this->user('hr'))->post("/settings/users/{$new->id}/send-link")->assertForbidden();

        Notification::assertSentTo($new, SetPasswordNotification::class, fn ($n) => $n->invite);
        Notification::assertSentTo($used, SetPasswordNotification::class, fn ($n) => ! $n->invite);
        Notification::assertNothingSentTo($off);
    }

    public function test_logging_in_records_the_time_without_an_activity_entry(): void
    {
        $u = $this->user('hr', ['email' => 'in@example.com', 'password' => 'Secret-123']);
        $this->assertNull($u->last_login_at);
        $before = \App\Models\ActivityLog::count();

        $this->post('/login', ['email' => 'in@example.com', 'password' => 'Secret-123'])->assertRedirect();

        $this->assertNotNull($u->fresh()->last_login_at);
        $this->assertSame($before, \App\Models\ActivityLog::count());
    }

    public function test_a_leavers_login_is_switched_off(): void
    {
        $leaving = $this->employee($this->company);
        $deleted = $this->employee($this->company);
        $staying = $this->employee($this->company);
        $a = $this->user('employee', ['employee_id' => $leaving->id]);
        $b = $this->user('employee', ['employee_id' => $deleted->id]);
        $c = $this->user('employee', ['employee_id' => $staying->id]);

        $leaving->update(['status' => 'exited']);
        $deleted->delete();
        $staying->update(['role_title' => 'Lead']);

        $this->assertSame([false, false, true], [$a->fresh()->is_active, $b->fresh()->is_active, $c->fresh()->is_active]);
    }
}
