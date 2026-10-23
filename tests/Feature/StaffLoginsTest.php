<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Notifications\SetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** HR's Staff logins page and the profile's Login card: staff logins only; other roles stay with admins. */
class StaffLoginsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->company] = $this->baseData();
        Notification::fake();
    }

    public function test_hr_creates_a_staff_login_from_the_work_email_and_it_gets_the_welcome_link(): void
    {
        $e = $this->employee($this->company, ['email' => 'Sara.Q@Example.com']);

        $this->actingAs($this->user('hr'))->post("/employees/{$e->id}/login")->assertSessionHas('success');

        $login = User::where('employee_id', $e->id)->sole();
        $this->assertSame(['sara.q@example.com', 'employee', true], [$login->email, $login->role, $login->is_active]);
        Notification::assertSentTo($login, SetPasswordNotification::class, fn ($n) => $n->invite);
    }

    public function test_no_login_without_a_work_email_twice_for_one_person_on_a_used_email_or_for_a_leaver(): void
    {
        $hr = $this->user('hr');
        $noEmail = $this->employee($this->company, ['email' => null]);
        $has = $this->employee($this->company, ['email' => 'has@example.com']);
        $this->user('employee', ['employee_id' => $has->id, 'email' => 'has@example.com']);
        $this->user('viewer', ['email' => 'shared@example.com']);
        $shared = $this->employee($this->company, ['email' => 'shared@example.com']);
        $left = $this->employee($this->company, ['email' => 'left@example.com', 'status' => 'exited']);

        foreach ([$noEmail, $has, $shared, $left] as $e) {
            $this->actingAs($hr)->post("/employees/{$e->id}/login")->assertSessionHasErrors('login');
        }
        $this->assertSame(3, User::count());   // HR, the existing staff login, the viewer
        Notification::assertNothingSent();
    }

    public function test_hr_sends_links_and_switches_staff_logins_but_not_other_roles(): void
    {
        $hr = $this->user('hr');
        $staff = $this->user('employee', ['employee_id' => $this->employee($this->company)->id]);
        $admin = $this->user('admin');

        $this->actingAs($hr)->post("/logins/{$staff->id}/send-link")->assertSessionHas('success');
        $this->actingAs($hr)->put("/logins/{$staff->id}/active", ['is_active' => false])->assertSessionHas('success');
        $this->assertFalse($staff->fresh()->is_active);
        $this->actingAs($hr)->post("/logins/{$staff->id}/send-link")->assertSessionHasErrors('user');
        $this->actingAs($hr)->put("/logins/{$staff->id}/active", ['is_active' => true]);
        $this->assertTrue($staff->fresh()->is_active);

        $this->actingAs($hr)->post("/logins/{$admin->id}/send-link")->assertForbidden();
        $this->actingAs($hr)->put("/logins/{$admin->id}/active", ['is_active' => false])->assertForbidden();
        $this->assertTrue($admin->fresh()->is_active);
        Notification::assertSentToTimes($staff, SetPasswordNotification::class, 1);
    }

    public function test_hr_creates_logins_for_everyone_ready_at_once(): void
    {
        $a = $this->employee($this->company, ['email' => 'a@example.com']);
        $b = $this->employee($this->company, ['email' => 'b@example.com']);
        $this->employee($this->company, ['email' => null]);

        $this->actingAs($this->user('hr'))->post('/logins', ['send' => true])->assertSessionHas('success');

        $this->assertEqualsCanonicalizing([$a->id, $b->id], User::where('role', 'employee')->pluck('employee_id')->all());
        Notification::assertCount(2);
    }

    public function test_viewers_cant_open_or_use_any_of_it(): void
    {
        $viewer = $this->user('viewer');
        $e = $this->employee($this->company, ['email' => 'v@example.com']);
        $staff = $this->user('employee', ['employee_id' => $this->employee($this->company)->id]);

        $this->actingAs($viewer)->get('/logins')->assertForbidden();
        $this->actingAs($viewer)->post('/logins')->assertForbidden();
        $this->actingAs($viewer)->post("/employees/{$e->id}/login")->assertForbidden();
        $this->actingAs($viewer)->post("/logins/{$staff->id}/send-link")->assertForbidden();
        $this->actingAs($viewer)->put("/logins/{$staff->id}/active", ['is_active' => false])->assertForbidden();
    }

    public function test_the_page_lists_logins_and_who_has_none_and_the_profile_shows_the_login_to_hr_only(): void
    {
        $with = $this->employee($this->company, ['email' => 'with@example.com']);
        $login = $this->user('employee', ['employee_id' => $with->id, 'email' => 'with@example.com']);
        $none = $this->employee($this->company, ['email' => null]);
        $hr = $this->user('hr');

        $this->actingAs($hr)->get('/logins')->assertInertia(fn (AssertableInertia $p) => $p->component('logins/index')
            ->where('logins.0.email', 'with@example.com')
            ->where('without', fn ($w) => collect($w)->pluck('problem', 'id')->all() === [$none->id => 'no_email']));

        $this->actingAs($hr)->get("/employees/{$with->id}")->assertInertia(fn (AssertableInertia $p) => $p->where('login.id', $login->id)->where('login.is_active', true));
        $this->actingAs($hr)->get("/employees/{$none->id}")->assertInertia(fn (AssertableInertia $p) => $p->where('login', null));
        $this->actingAs($this->user('viewer'))->get("/employees/{$with->id}")->assertInertia(fn (AssertableInertia $p) => $p->where('login', null));
    }
}
