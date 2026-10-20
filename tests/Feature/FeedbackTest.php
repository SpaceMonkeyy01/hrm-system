<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\ConductEntry;
use App\Models\Employee;
use App\Models\LetterTemplate;
use App\Models\Meeting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Feedback: the conduct record and meeting notes (HR and admin), acknowledged by the person on My page. */
class FeedbackTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Employee $me;

    private User $login;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->company] = $this->baseData();
        $this->travelTo(CarbonImmutable::parse('2026-05-20 09:00'));
        $this->me = $this->employee($this->company, ['full_name' => 'Fake Me']);
        $this->login = $this->user('employee', ['employee_id' => $this->me->id]);
    }

    public function test_hr_records_conduct_and_the_person_acknowledges_it(): void
    {
        $hr = $this->user('hr');
        $url = "/employees/{$this->me->id}/conduct";
        $this->actingAs($this->user('viewer'))->post($url, ['type' => 'appreciation', 'happened_on' => '2026-05-19', 'title' => 'x'])->assertForbidden();
        $this->actingAs($hr)->post($url, ['type' => 'praise', 'happened_on' => '2026-05-19', 'title' => 'x'])->assertSessionHasErrors('type');
        $this->actingAs($hr)->post($url, ['type' => 'appreciation', 'happened_on' => '2026-05-21', 'title' => 'x'])->assertSessionHasErrors('happened_on');
        $this->actingAs($hr)->post($url, ['type' => 'verbal_warning', 'happened_on' => '2026-05-19', 'title' => 'Late to client calls', 'details' => 'Three times this week'])
            ->assertSessionHasNoErrors();
        $entry = ConductEntry::sole();

        // What was said stays out of the activity log; viewers don't get the Feedback tab at all.
        $this->assertSame('hidden', ActivityLog::where('subject_type', 'ConductEntry')->sole()->changes['title'] ?? null);
        $this->actingAs($this->user('viewer'))->get("/employees/{$this->me->id}")->assertInertia(fn (AssertableInertia $p) => $p->where('feedback', null));
        $this->actingAs($hr)->get("/employees/{$this->me->id}")->assertInertia(fn (AssertableInertia $p) => $p->where('feedback.conduct.0.title', 'Late to client calls'));

        $this->actingAs($this->login)->get('/me')->assertInertia(fn (AssertableInertia $p) => $p->where('conduct.0.type_label', 'Verbal warning'));
        $this->actingAs($this->login)->post("/me/conduct/{$entry->id}/acknowledge", ['comment' => 'Traffic, will leave earlier'])->assertSessionHasNoErrors();
        $this->assertNotNull($entry->fresh()->acknowledged_at);
        $this->actingAs($this->login)->post("/me/conduct/{$entry->id}/acknowledge")->assertSessionHasErrors('comment');   // only once

        // HR changing it asks again; the comment stays.
        $this->actingAs($hr)->put("/conduct/{$entry->id}", ['type' => 'written_warning', 'happened_on' => '2026-05-19', 'title' => 'Late to client calls'])->assertSessionHasNoErrors();
        $this->assertSame([null, 'Traffic, will leave earlier'], [$entry->fresh()->acknowledged_at, $entry->fresh()->employee_comment]);

        // Someone else's entry is out of reach.
        $other = ConductEntry::create(['employee_id' => $this->employee($this->company)->id, 'type' => 'appreciation', 'happened_on' => '2026-05-19', 'title' => 'x']);
        $this->actingAs($this->login)->post("/me/conduct/{$other->id}/acknowledge")->assertNotFound();

        $this->actingAs($hr)->delete("/conduct/{$entry->id}")->assertSessionHasNoErrors();
        $this->assertSame(1, ConductEntry::count());
    }

    public function test_meeting_notes_reach_the_person_only_when_shared(): void
    {
        $hr = $this->user('hr');
        $this->actingAs($hr)->post("/employees/{$this->me->id}/meetings", ['type' => 'one_to_one', 'held_on' => '2026-05-19'])->assertSessionHasErrors('notes');
        $this->actingAs($hr)->post("/employees/{$this->me->id}/meetings", ['type' => 'one_to_one', 'held_on' => '2026-05-19', 'notes' => 'Private thoughts'])->assertSessionHasNoErrors();
        $this->actingAs($hr)->post("/employees/{$this->me->id}/meetings", ['type' => 'probation_checkin', 'held_on' => '2026-05-20', 'notes' => 'Going well',
            'actions' => 'Shadow sales calls', 'attendees' => 'Manager, HR', 'shared' => true])->assertSessionHasNoErrors();
        [$private, $shared] = Meeting::orderBy('id')->get()->all();

        $this->actingAs($this->login)->get('/me')->assertInertia(fn (AssertableInertia $p) => $p->has('meetings', 1)->where('meetings.0.notes', 'Going well'));
        $this->actingAs($this->login)->post("/me/meetings/{$private->id}/acknowledge")->assertNotFound();
        $this->actingAs($this->login)->post("/me/meetings/{$shared->id}/acknowledge")->assertSessionHasNoErrors();
        $this->assertNotNull($shared->fresh()->acknowledged_at);

        // Sharing switched on later: still only HR's changes to the notes ask again.
        $this->actingAs($hr)->put("/meetings/{$shared->id}", ['type' => 'probation_checkin', 'held_on' => '2026-05-20', 'notes' => 'Going very well', 'shared' => true]);
        $this->assertNull($shared->fresh()->acknowledged_at);
    }

    public function test_issuing_a_warning_letter_adds_it_to_the_conduct_record(): void
    {
        $template = LetterTemplate::first();
        $template->update(['conduct_type' => 'written_warning']);
        $this->actingAs($this->user('hr'))->post("/employees/{$this->me->id}/letters", ['template_id' => $template->id, 'subject' => 'Warning: attendance', 'body' => 'Text'])
            ->assertSessionHasNoErrors();
        $entry = ConductEntry::sole();
        $this->assertSame(['written_warning', 'Warning: attendance', '2026-05-20'], [$entry->type, $entry->title, $entry->happened_on->toDateString()]);
        $this->assertNotNull($entry->issued_letter_id);
    }
}
