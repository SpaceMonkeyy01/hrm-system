<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmploymentHistory;
use App\Models\User;
use App\Notifications\SetPasswordNotification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Fill in details, asset list upload, the code report and staff logins. Fake people only. */
class DataToolsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Employee $a;

    private Employee $b;

    private Employee $boss;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        [$this->company] = $this->baseData();
        $this->travelTo(CarbonImmutable::parse('2026-05-20 10:00'));
        $this->a = $this->employee($this->company, ['full_name' => 'Fake Alpha', 'role_title' => 'Designer']);
        $this->b = $this->employee($this->company, ['full_name' => 'Fake Bravo', 'attendance_machine_id' => '7']);
        $this->boss = $this->employee($this->company, ['full_name' => 'Fake Boss']);
    }

    private function csv(array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'csv');
        $fh = fopen($path, 'w');
        foreach ($rows as $r) {
            fputcsv($fh, $r, escape: '');
        }
        fclose($fh);

        return new UploadedFile($path, 'filled.csv', 'text/csv', null, true);
    }

    private function details(array $byPerson): UploadedFile
    {
        $header = ['System ID', 'Code', 'Name', 'Company', 'Department', 'Work email', 'Phone', 'Personal email', 'CNIC', 'CNIC expiry', 'Date of birth', 'Role', 'Manager code', 'Machine no.', 'HR.my ID'];
        $rows = [$header];
        foreach ($byPerson as [$e, $cells]) {
            $row = array_fill_keys($header, '');
            $row = array_merge($row, ['System ID' => $e->system_id, 'Code' => $e->employee_code, 'Name' => $e->full_name], $cells);
            $rows[] = array_values($row);
        }

        return $this->csv($rows);
    }

    public function test_fill_in_details_previews_then_saves_only_good_filled_cells(): void
    {
        $hr = $this->user('hr');
        $this->actingAs($hr)->get('/imports/details/template')->assertOk();

        $file = $this->details([
            [$this->a, ['Work email' => 'Alpha@Fake.Test', 'Phone' => '3001234567', 'CNIC' => '3520212345671', 'CNIC expiry' => '31/05/2027',
                'Manager code' => $this->boss->employee_code, 'Role' => 'Senior Designer']],
            [$this->b, ['CNIC' => '123', 'Machine no.' => '9']],
            [$this->boss, ['Machine no.' => '7']],    // 7 is Bravo's
        ]);
        $this->actingAs($hr)->post('/imports/details', ['file' => $file])->assertRedirect('/imports/details');

        $this->actingAs($hr)->get('/imports/details')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->component('imports/show')
            ->where('plan.rows', 3)
            ->where('plan.changes', fn ($c) => collect($c)->pluck('label')->sort()->values()->all() === ['CNIC', 'CNIC expiry', 'Machine no.', 'Manager code', 'Phone', 'Role', 'Work email'])
            ->where('plan.problems', fn ($p) => count($p) === 2)
            ->missing('plan.changes.0.value'));
        $this->assertNull($this->a->fresh()->email, 'nothing saved before Save');

        $this->actingAs($hr)->post('/imports/details/apply')->assertSessionHas('success', 'Saved 7 details for 2 people.');
        $a = $this->a->fresh();
        $this->assertSame(['alpha@fake.test', '03001234567', '35202-1234567-1', '2027-05-31', $this->boss->id, 'Senior Designer'],
            [$a->email, $a->phone, $a->cnic, $a->cnic_expiry->toDateString(), $a->manager_id, $a->role_title]);
        $this->assertSame('9', $this->b->fresh()->attendance_machine_id);
        $this->assertNull($this->b->fresh()->cnic);
        $this->assertNull($this->boss->fresh()->attendance_machine_id);

        // Replacing the role is a job change; filling the empty manager is not.
        $h = EmploymentHistory::sole();
        $this->assertSame(['Role change', ['Role' => ['Designer', 'Senior Designer']]], [$h->change_type, $h->changes]);

        // A second file with empty cells clears nothing.
        $this->actingAs($hr)->post('/imports/details', ['file' => $this->details([[$this->a, ['Role' => '']]])]);
        $this->actingAs($hr)->post('/imports/details/apply');
        $this->assertSame('Senior Designer', $this->a->fresh()->role_title);
    }

    public function test_a_file_without_the_key_column_is_refused_nicely(): void
    {
        $hr = $this->user('hr');
        $this->actingAs($hr)->post('/imports/details', ['file' => $this->csv([['Name', 'Email'], ['X', 'x@y.z']])]);
        $this->actingAs($hr)->get('/imports/details')->assertInertia(fn (AssertableInertia $p) => $p->where('plan', null)
            ->where('flash.error', fn ($m) => str_contains($m, 'no "System ID" column')));
    }

    public function test_asset_list_adds_new_tags_and_who_has_them(): void
    {
        Asset::create(['asset_tag' => 'LAP-OLD', 'type' => 'Laptop', 'name' => 'Fake Old']);
        $hr = $this->user('hr');
        $this->actingAs($hr)->post('/imports/assets', ['file' => $this->csv([
            ['Tag', 'Type', 'Make and model', 'Serial number', 'Bought on', 'Given to', 'Given on', 'Notes'],
            ['LAP-1', 'laptop', 'Fake Laptop', 'S1', '2025-01-15', $this->a->employee_code, '01/02/2026', ''],
            ['SIM-1', 'SIM card', 'Fake SIM', '', '', '', '', ''],
            ['LAP-OLD', 'Laptop', 'Fake Old', '', '', '', '', ''],
            ['PH-1', 'Phone', 'Fake Phone', '', '', 'NOPE-1', '', ''],
        ])]);
        $this->actingAs($hr)->get('/imports/assets')->assertInertia(fn (AssertableInertia $p) => $p->has('plan.changes', 2)->has('plan.problems', 2));
        $this->actingAs($hr)->post('/imports/assets/apply')->assertSessionHas('success', 'Added 2 assets, 1 given to someone.');

        $this->assertSame(['assigned', 'Laptop'], [Asset::where('asset_tag', 'LAP-1')->value('status'), Asset::where('asset_tag', 'LAP-1')->value('type')]);
        $this->assertSame('in_stock', Asset::where('asset_tag', 'SIM-1')->value('status'));
        $this->assertSame([$this->a->id, '2026-02-01'], [AssetAssignment::sole()->employee_id, AssetAssignment::sole()->assigned_on->toDateString()]);
    }

    public function test_viewers_cannot_use_the_imports(): void
    {
        $this->actingAs($this->user('viewer'))->get('/imports/details')->assertForbidden();
        $this->actingAs($this->user('viewer'))->get('/imports/details/template')->assertForbidden();
    }

    public function test_code_report_shows_where_hrmy_numbering_differs(): void
    {
        $this->a->update(['hr_portal_id' => $this->a->employee_code]);
        $this->b->update(['hr_portal_id' => 'BCC-999']);
        $rows = collect([]);
        $this->actingAs($this->user('viewer'))->get('/reports/codes')->assertInertia(function (AssertableInertia $p) use (&$rows) {
            $rows = collect($p->toArray()['props']['result']['rows'])->keyBy('employee.name');
        });
        $this->assertSame(['Same', 'Different', 'Not linked'], [$rows['Fake Alpha']['match'], $rows['Fake Bravo']['match'], $rows['Fake Boss']['match']]);
    }

    public function test_staff_logins_are_created_for_people_with_a_work_email_and_invited_to_choose_a_password(): void
    {
        Notification::fake();
        $this->a->update(['email' => 'alpha@fake.test']);
        $this->b->update(['email' => 'taken@fake.test']);
        $this->user('viewer', ['email' => 'taken@fake.test']);
        $admin = $this->user('admin');

        $this->actingAs($admin)->get('/settings/users')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('staffLogins', ['ready' => 1, 'noEmail' => 1, 'emailTaken' => 1]));
        $this->actingAs($this->user('hr'))->post('/settings/users/staff-logins')->assertForbidden();
        $this->actingAs($admin)->post('/settings/users/staff-logins', ['send' => true])->assertSessionHas('success');

        $login = User::where('email', 'alpha@fake.test')->sole();
        $this->assertSame(['employee', $this->a->id], [$login->role, $login->employee_id]);
        $token = null;
        Notification::assertSentTo($login, SetPasswordNotification::class, function ($n) use (&$token) {
            $token = $n->token;

            return $n->invite;
        });
        $this->actingAs($admin)->post('/settings/users/staff-logins')->assertSessionHas('error');   // nobody left

        // The link sets the password; the old random one never worked for anyone.
        auth()->logout();
        $this->post('/reset-password', ['token' => 'wrong', 'email' => 'alpha@fake.test', 'password' => 'new-pass-123', 'password_confirmation' => 'new-pass-123', 'invite' => true])
            ->assertSessionHasErrors('email');
        $this->post('/reset-password', ['token' => $token, 'email' => 'alpha@fake.test', 'password' => 'new-pass-123', 'password_confirmation' => 'new-pass-123', 'invite' => true])
            ->assertRedirect('/login');
        $this->assertTrue(Hash::check('new-pass-123', $login->fresh()->password));
        $this->post('/login', ['email' => 'alpha@fake.test', 'password' => 'new-pass-123'])->assertRedirect('/me');
    }

    public function test_adding_one_login_can_send_a_welcome_email_instead_of_a_password(): void
    {
        Notification::fake();
        $admin = $this->user('admin');

        $this->actingAs($admin)->post('/settings/users', ['name' => 'Fake Alpha', 'email' => 'alpha@fake.test', 'role' => 'employee', 'employee_id' => $this->a->id, 'send_invite' => true])
            ->assertSessionHasNoErrors()->assertSessionHas('success');
        $login = User::where('email', 'alpha@fake.test')->sole();
        $token = null;
        Notification::assertSentTo($login, SetPasswordNotification::class, function ($n) use (&$token) {
            $token = $n->token;

            return $n->invite;
        });
        $this->assertStringContainsString('attendance, leave and reviews', implode(' ', (new SetPasswordNotification('t', invite: true))->toMail($login)->introLines));

        auth()->logout();
        $this->post('/reset-password', ['token' => $token, 'email' => 'alpha@fake.test', 'password' => 'new-pass-123', 'password_confirmation' => 'new-pass-123', 'invite' => true])
            ->assertRedirect('/login');
        $this->post('/login', ['email' => 'alpha@fake.test', 'password' => 'new-pass-123'])->assertRedirect('/me');

        // Without the email, HR still has to give a password.
        $this->actingAs($admin)->post('/settings/users', ['name' => 'B', 'email' => 'b@fake.test', 'role' => 'hr', 'send_invite' => false])
            ->assertSessionHasErrors('password');
        Notification::assertCount(1);
    }

    public function test_a_failed_welcome_email_still_keeps_the_login_and_says_so(): void
    {
        Notification::shouldReceive('send')->andThrow(new \RuntimeException('SMTP down'));
        $this->actingAs($this->user('admin'))->post('/settings/users', ['name' => 'B', 'email' => 'b@fake.test', 'role' => 'hr', 'send_invite' => true])
            ->assertSessionHas('error');
        $this->assertTrue(User::where('email', 'b@fake.test')->exists());
    }

    public function test_forgot_password_never_says_who_has_a_login(): void
    {
        Notification::fake();
        $u = $this->user('hr', ['email' => 'real@fake.test']);
        $msg = 'If that email has a login, a link to choose a new password is on its way.';
        $this->post('/forgot-password', ['email' => 'real@fake.test'])->assertSessionHas('success', $msg);
        $this->post('/forgot-password', ['email' => 'nobody@fake.test'])->assertSessionHas('success', $msg);
        Notification::assertSentTo($u, SetPasswordNotification::class, fn ($n) => ! $n->invite);
        Notification::assertCount(1);
    }
}
