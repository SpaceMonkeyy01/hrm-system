<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ChecklistEntry;
use App\Models\ChecklistItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Who can see and change what. */
class AccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/employees')->assertRedirect('/login');
        $this->put('/checklist/1/1', ['status' => 'Yes'])->assertRedirect('/login');
    }

    public function test_login_works_only_for_active_users(): void
    {
        $this->user('hr', ['email' => 'hr@test.local', 'password' => 'secret-pass']);
        $this->user('hr', ['email' => 'off@test.local', 'password' => 'secret-pass', 'is_active' => false]);

        $this->post('/login', ['email' => 'off@test.local', 'password' => 'secret-pass'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post('/login', ['email' => 'HR@test.local', 'password' => 'secret-pass'])->assertRedirect('/dashboard');
        $this->assertAuthenticated();
    }

    public function test_switched_off_user_is_logged_out_on_next_request(): void
    {
        $user = $this->user('hr');
        $this->actingAs($user)->get('/dashboard')->assertOk();
        $user->update(['is_active' => false]);
        $this->get('/dashboard')->assertRedirect('/login')->assertSessionHas('error');
        $this->assertGuest();
    }

    public function test_viewer_can_read_but_not_change(): void
    {
        [$company] = $this->baseData();
        $e = $this->employee($company);
        $item = ChecklistItem::where('key', 'nda_signed')->first();
        $viewer = $this->user('viewer');

        foreach (['/dashboard', '/employees', '/onboarding', '/documents', '/offboarding', '/controlled-documents', "/employees/{$e->id}"] as $url) {
            $this->actingAs($viewer)->get($url)->assertOk();
        }
        $this->actingAs($viewer)->get('/employees')->assertInertia(fn (AssertableInertia $p) => $p->component('employees/index')->where('auth.can.edit', false));
        $this->actingAs($viewer)->put("/checklist/{$e->id}/{$item->id}", ['status' => 'Yes'])->assertForbidden();
        $this->actingAs($viewer)->post('/employees', ['company_id' => $company->id, 'full_name' => 'X'])->assertForbidden();
        $this->actingAs($viewer)->get('/settings/users')->assertForbidden();
        $this->assertSame(0, ChecklistEntry::count());
    }

    public function test_hr_can_change_records_but_not_settings(): void
    {
        [$company] = $this->baseData();
        $e = $this->employee($company);
        $item = ChecklistItem::where('key', 'nda_signed')->first();
        $hr = $this->user('hr');

        $this->actingAs($hr)->put("/checklist/{$e->id}/{$item->id}", ['status' => 'Yes'])->assertRedirect();
        $this->assertSame('Yes', ChecklistEntry::where('employee_id', $e->id)->value('status'));
        $log = ActivityLog::where('subject_type', 'ChecklistEntry')->latest('id')->first();
        $this->assertSame($hr->id, $log->user_id);
        $this->assertSame($e->id, $log->employee_id);
        $this->assertSame('NDA signed', $log->description);

        // invalid value for this item is rejected
        $this->actingAs($hr)->put("/checklist/{$e->id}/{$item->id}", ['status' => 'Done'])->assertSessionHasErrors('status');

        $this->actingAs($hr)->get('/settings/users')->assertForbidden();
        $this->actingAs($hr)->delete("/employees/{$e->id}")->assertForbidden();
    }

    public function test_admin_cannot_lock_themselves_out(): void
    {
        $admin = $this->user('admin');
        $this->actingAs($admin)->put("/settings/users/{$admin->id}", ['name' => 'A', 'role' => 'viewer', 'is_active' => true])->assertSessionHasErrors('role');
        $this->assertSame('admin', $admin->fresh()->role);
    }

    public function test_required_checklist_items_cannot_be_switched_off(): void
    {
        $this->baseData();
        $admin = $this->user('admin');
        $nda = ChecklistItem::where('key', 'nda_signed')->first();
        $this->actingAs($admin)->put("/settings/checklists/{$nda->id}", ['label' => 'NDA', 'description' => null, 'is_active' => false, 'sort_order' => 1])
            ->assertSessionHasErrors('is_active');
        $this->assertTrue($nda->fresh()->is_active);
    }
}
