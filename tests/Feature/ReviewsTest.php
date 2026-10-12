<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Review;
use App\Models\ReviewCycle;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Performance reviews: cycles, self-review on My page, HR's review, acknowledging. Fake people only. */
class ReviewsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $hr;

    private Employee $withLogin;

    private Employee $noLogin;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->company] = $this->baseData();
        $this->travelTo(CarbonImmutable::parse('2026-07-10 10:00'));
        $this->hr = $this->user('hr');
        $this->withLogin = $this->employee($this->company, ['full_name' => 'Fake Staff', 'start_date' => '2025-01-01']);
        $this->noLogin = $this->employee($this->company, ['full_name' => 'Fake Offline', 'start_date' => '2025-01-01']);
        $this->employee($this->company, ['full_name' => 'Fake Late Joiner', 'start_date' => '2026-07-05']);
        $this->employee($this->company, ['full_name' => 'Fake Gone', 'start_date' => '2024-01-01', 'status' => 'exited']);
        $this->staff = $this->user('employee', ['employee_id' => $this->withLogin->id]);
    }

    private function cycle(): ReviewCycle
    {
        $this->actingAs($this->hr)->post('/reviews', ['name' => '2026 mid-year', 'period_start' => '2026-01-01', 'period_end' => '2026-06-30', 'self_due' => '2026-07-20'])
            ->assertSessionHasNoErrors();

        return ReviewCycle::sole();
    }

    private function ratings(ReviewCycle $c, int $value): array
    {
        return collect($c->criteria)->mapWithKeys(fn ($x) => [$x['key'] => $value])->all();
    }

    private function openCycle(): ReviewCycle
    {
        $c = $this->cycle();
        $this->actingAs($this->hr)->post("/reviews/{$c->id}/people")->assertSessionHasNoErrors();
        $this->actingAs($this->hr)->post("/reviews/{$c->id}/open");

        return $c->fresh();
    }

    public function test_reviews_are_hr_only(): void
    {
        $c = $this->cycle();
        $this->actingAs($this->user('viewer'))->get('/reviews')->assertForbidden();
        $this->actingAs($this->user('viewer'))->get("/reviews/{$c->id}")->assertForbidden();
        $this->actingAs($this->staff)->get('/reviews')->assertForbidden();
        $this->actingAs($this->user('viewer'))->get('/reports/review-outcomes')->assertForbidden();
    }

    public function test_adding_people_picks_current_staff_and_skips_the_self_review_without_a_login(): void
    {
        $c = $this->cycle();
        $this->assertSame('draft', $c->status);
        $this->assertCount(7, $c->criteria);
        $this->actingAs($this->hr)->post("/reviews/{$c->id}/open")->assertSessionHas('error', 'Add people before opening the cycle.');

        $this->actingAs($this->hr)->post("/reviews/{$c->id}/people")->assertSessionHas('success', 'Added 2 people.');
        $this->actingAs($this->hr)->post("/reviews/{$c->id}/people")->assertSessionHas('success', 'Nobody new to add: everyone who fits is already in.');
        $statuses = Review::with('employee')->get()->mapWithKeys(fn ($r) => [$r->employee->full_name => $r->status])->all();
        $this->assertSame(['Fake Staff' => 'self_review', 'Fake Offline' => 'manager_review'], $statuses);
    }

    public function test_criteria_change_only_while_setting_up(): void
    {
        $c = $this->cycle();
        $criteria = [['key' => 'quality', 'label' => 'Quality of work'], ['label' => 'Client feedback', 'description' => 'What clients said']];
        $this->actingAs($this->hr)->put("/reviews/{$c->id}", ['name' => $c->name, 'period_start' => '2026-01-01', 'period_end' => '2026-06-30', 'criteria' => $criteria])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(['quality', 'client_feedback'], array_column($c->fresh()->criteria, 'key'));

        $this->actingAs($this->hr)->post("/reviews/{$c->id}/people");
        $this->actingAs($this->hr)->post("/reviews/{$c->id}/open");
        $this->actingAs($this->hr)->put("/reviews/{$c->id}", ['name' => $c->name, 'period_start' => '2026-01-01', 'period_end' => '2026-06-30', 'criteria' => $criteria])
            ->assertSessionHasErrors('criteria');
    }

    public function test_the_whole_review_from_self_review_to_acknowledgement(): void
    {
        $c = $this->openCycle();
        $review = Review::where('employee_id', $this->withLogin->id)->sole();

        // Self-review: draft first, then send.
        $this->actingAs($this->staff)->put("/me/reviews/{$review->id}", ['self_ratings' => ['quality' => 4], 'self_summary' => 'Fake summary'])->assertSessionHasNoErrors();
        $this->assertSame(['self_review', ['quality' => 4]], [$review->fresh()->status, $review->fresh()->self_ratings]);
        $this->actingAs($this->hr)->get("/reviews/{$c->id}/people/{$review->id}")->assertInertia(fn (AssertableInertia $p) => $p
            ->where('review.self_ratings', [])->where('review.self_summary', null), 'an unsent draft stays with the person');
        $this->actingAs($this->staff)->put("/me/reviews/{$review->id}", ['self_ratings' => ['quality' => 4], 'submit' => true])->assertSessionHasErrors('self_ratings');
        $this->actingAs($this->staff)->put("/me/reviews/{$review->id}", ['self_ratings' => $this->ratings($c, 4) + ['made_up' => 5], 'submit' => true])->assertSessionHasNoErrors();
        $review->refresh();
        $this->assertSame('manager_review', $review->status);
        $this->assertArrayNotHasKey('made_up', $review->self_ratings);
        $this->actingAs($this->staff)->put("/me/reviews/{$review->id}", ['self_ratings' => $this->ratings($c, 5)])->assertSessionHasErrors('review');

        // HR: a draft stays hidden from the person.
        $this->actingAs($this->hr)->put("/reviews/{$c->id}/people/{$review->id}", ['manager_ratings' => ['quality' => 2], 'manager_summary' => 'Draft words'])->assertSessionHasNoErrors();
        $this->actingAs($this->staff)->get('/me')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('reviews.0.manager_summary', null)->where('reviews.0.overall', null)->etc());

        $ratings = $this->ratings($c, 3);
        $ratings['quality'] = 5;
        $ratings['delivery'] = 4;
        $this->actingAs($this->hr)->put("/reviews/{$c->id}/people/{$review->id}", [
            'manager_ratings' => $ratings, 'manager_summary' => 'Fake feedback', 'recommendation' => 'Pay increase',
            'goals' => [['title' => 'Fake goal', 'measure' => 'Fake measure', 'due' => '2026-12-31']], 'submit' => true,
        ])->assertSessionHasNoErrors();
        $review->refresh();
        $this->assertSame(['completed', 3.4, $this->hr->id], [$review->status, $review->overall(), $review->reviewed_by]);

        $this->actingAs($this->staff)->get('/me')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('reviews.0.overall', 3.4)->where('reviews.0.band', 'Meets expectations')->where('reviews.0.manager_summary', 'Fake feedback')
            ->where('reviews.0.goals.0.title', 'Fake goal')->where('reviews.0.recommendation', null)->etc());

        $this->actingAs($this->staff)->post("/me/reviews/{$review->id}/acknowledge", ['employee_comment' => 'Fair'])->assertSessionHasNoErrors();
        $this->assertNotNull($review->fresh()->acknowledged_at);
        $this->actingAs($this->staff)->post("/me/reviews/{$review->id}/acknowledge")->assertSessionHasErrors('review');

        $this->actingAs($this->hr)->get('/reports/review-outcomes?year=2026')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('result.rows.0.overall', 3.4)->where('result.rows.0.recommendation', 'Pay increase')->where('result.rows.0.read', 'Yes'));
    }

    public function test_people_only_touch_their_own_review_and_only_while_it_is_open(): void
    {
        $c = $this->cycle();
        $this->actingAs($this->hr)->post("/reviews/{$c->id}/people");
        $mine = Review::where('employee_id', $this->withLogin->id)->sole();
        $theirs = Review::where('employee_id', $this->noLogin->id)->sole();

        // Draft cycle: invisible and closed to the person.
        $this->actingAs($this->staff)->get('/me')->assertInertia(fn (AssertableInertia $p) => $p->has('reviews', 0)->etc());
        $this->actingAs($this->staff)->put("/me/reviews/{$mine->id}", ['self_ratings' => ['quality' => 4]])->assertSessionHasErrors('review');

        $this->actingAs($this->hr)->post("/reviews/{$c->id}/open");
        $this->actingAs($this->staff)->put("/me/reviews/{$theirs->id}", ['self_ratings' => ['quality' => 1]])->assertNotFound();
        $this->actingAs($this->staff)->post("/me/reviews/{$theirs->id}/acknowledge")->assertNotFound();

        // A review in another cycle can't be reached through this cycle's URL.
        $other = ReviewCycle::create(['name' => 'Other', 'period_start' => '2026-01-01', 'period_end' => '2026-06-30', 'criteria' => [], 'status' => 'open']);
        $this->actingAs($this->hr)->get("/reviews/{$other->id}/people/{$mine->id}")->assertNotFound();

        $this->actingAs($this->hr)->post("/reviews/{$c->id}/close");
        $this->actingAs($this->hr)->put("/reviews/{$c->id}/people/{$theirs->id}", ['manager_ratings' => $this->ratings($c, 3), 'submit' => true])->assertSessionHasErrors('review');
    }

    public function test_skip_and_take_out(): void
    {
        $c = $this->openCycle();
        $mine = Review::where('employee_id', $this->withLogin->id)->sole();
        $this->actingAs($this->hr)->post("/reviews/{$c->id}/people/{$mine->id}/skip-self")->assertSessionHasNoErrors();
        $this->assertSame('manager_review', $mine->fresh()->status);
        $this->actingAs($this->hr)->delete("/reviews/{$c->id}/people/{$mine->id}");
        $this->assertSame('cancelled', $mine->fresh()->status, 'kept once the cycle is open');
        $this->actingAs($this->staff)->get('/me')->assertInertia(fn (AssertableInertia $p) => $p->has('reviews', 0)->etc());
    }
}
