<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Onboarding;
use App\Services\Reminders;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Closing out old onboarding records: kept on file, off the tracker, Home counts and reminders. Fake people only. */
class OnboardingCloseTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->company] = $this->baseData();
        $this->travelTo(CarbonImmutable::parse('2026-10-16 10:00'));
    }

    private function hire(string $name, ?string $start): Employee
    {
        $e = $this->employee($this->company, ['full_name' => $name, 'start_date' => $start]);
        Onboarding::startFor($e);

        return $e;
    }

    private function trackerNames(string $q = ''): array
    {
        $names = [];
        $this->actingAs($this->user('viewer'))->get("/onboarding$q")->assertInertia(function (AssertableInertia $p) use (&$names) {
            $names = collect($p->toArray()['props']['rows'])->pluck('name')->sort()->values()->all();
        });

        return $names;
    }

    public function test_close_out_by_start_date_keeps_new_joiners(): void
    {
        $this->hire('Old Timer', '2019-03-01');
        $this->hire('No Date', null);
        $new = $this->hire('New Joiner', '2026-09-20');
        $hr = $this->user('hr');

        $this->actingAs($hr)->get('/onboarding')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('closeCandidates', ['before' => '2026-04-19', 'dated' => 1, 'undated' => 1]));
        $this->actingAs($this->user('viewer'))->post('/onboarding/close', ['started_before' => '2026-04-19'])->assertForbidden();

        $this->actingAs($hr)->post('/onboarding/close', ['started_before' => '2026-04-19'])->assertSessionHas('success', 'Closed 1 onboarding record. It is kept on file.');
        $this->assertSame(['New Joiner', 'No Date'], $this->trackerNames());
        $this->actingAs($hr)->post('/onboarding/close', ['started_before' => '2026-04-19', 'include_undated' => true])->assertSessionHas('success');
        $this->assertSame(['New Joiner'], $this->trackerNames());
        $this->assertSame(['No Date', 'Old Timer'], $this->trackerNames('?show=closed'));
        $this->assertSame(3, count($this->trackerNames('?show=all')));
        $this->assertStringContainsString('started before 19 Apr 2026', Onboarding::where('closed_note', '!=', null)->first()->closed_note);
        $this->assertNull($new->onboarding()->first()->closed_at);
    }

    public function test_close_ticked_rows_and_reopen(): void
    {
        $a = $this->hire('Alpha', '2026-09-01');
        $b = $this->hire('Beta', '2026-09-01');
        $hr = $this->user('hr');

        $this->actingAs($hr)->post('/onboarding/close', ['ids' => [$a->id]])->assertSessionHas('success');
        $this->assertSame(['Beta'], $this->trackerNames());
        $this->actingAs($hr)->get("/employees/{$a->id}")->assertInertia(fn (AssertableInertia $p) => $p
            ->where('onboarding.closed_at', '2026-10-16')->where('onboarding.closed_note', 'Closed from the tracker'));

        $this->actingAs($hr)->post("/onboarding/{$a->id}/reopen")->assertSessionHas('success');
        $this->assertSame(['Alpha', 'Beta'], $this->trackerNames());
        $this->assertNotNull($b);
    }

    public function test_closed_records_leave_home_counts_and_reminders(): void
    {
        // Started 40 days ago: the 30-day check-in is overdue while the record is open.
        $e = $this->hire('Recent', '2026-09-06');
        $hasReminder = fn () => collect(Reminders::forDate(CarbonImmutable::today()))->contains(fn ($r) => $r['employee_id'] === $e->id && $r['type'] === 'checkin_missed');
        $this->assertTrue($hasReminder());
        $this->actingAs($this->user('hr'))->get('/dashboard')->assertInertia(fn (AssertableInertia $p) => $p->where('onboarding.total', 1));

        $this->actingAs($this->user('hr'))->post('/onboarding/close', ['ids' => [$e->id]]);

        $this->assertFalse($hasReminder());
        $this->actingAs($this->user('hr'))->get('/dashboard')->assertInertia(fn (AssertableInertia $p) => $p->where('onboarding.total', 0));
    }
}
