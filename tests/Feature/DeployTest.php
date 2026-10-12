<?php

namespace Tests\Feature;

use App\Mail\RemindersDigest;
use App\Models\Onboarding;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Settings for hosts like Render free: the reminder URL and switching uploads off. */
class DeployTest extends TestCase
{
    use RefreshDatabase;

    public function test_reminder_url_needs_the_token_and_sends_once_a_day(): void
    {
        Mail::fake();
        [$company] = $this->baseData();
        $this->travelTo(CarbonImmutable::parse('2026-05-20 08:00'));
        $e = $this->employee($company, ['start_date' => '2026-02-21']);
        Onboarding::create(['employee_id' => $e->id, 'probation_result' => 'Pending', 'probation_end_date' => '2026-05-22']);
        $this->user('hr');

        $this->get('/cron/reminders/anything')->assertNotFound();   // no CRON_TOKEN set
        config(['hrm.cron_token' => 'fake-long-secret']);
        $this->get('/cron/reminders/wrong')->assertNotFound();
        $this->get('/cron/reminders/fake-long-secret')->assertOk();
        $this->get('/cron/reminders/fake-long-secret')->assertOk()->assertSee('Already sent today.');
        Mail::assertSent(RemindersDigest::class, 1);
    }

    public function test_uploads_can_be_switched_off(): void
    {
        Storage::fake('local');
        [$company] = $this->baseData();
        $e = $this->employee($company);
        config(['hrm.uploads.enabled' => false]);
        $this->actingAs($this->user('hr'))
            ->post("/employees/{$e->id}/files", ['file' => UploadedFile::fake()->create('cnic.pdf', 10, 'application/pdf')])
            ->assertSessionHasErrors('file');
        $this->assertSame([], Storage::disk('local')->allFiles());
    }
}
