<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** "Find an employee" (Ctrl K) and the sidebar counts. Fake people only. */
class SearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_finds_people_by_name_or_code_current_staff_first(): void
    {
        [$company] = $this->baseData();
        $gone = $this->employee($company, ['full_name' => 'Fake Aamir Gone', 'status' => 'exited']);
        $here = $this->employee($company, ['full_name' => 'Fake Aamir Here']);

        $hits = $this->actingAs($this->user('viewer'))->getJson('/search/employees?q=aamir')->assertOk()->json();
        $this->assertSame(['Fake Aamir Here', 'Fake Aamir Gone'], array_column($hits, 'name'));
        $this->assertSame([$here->id, 'active'], [$hits[0]['id'], $hits[0]['status']]);
        $this->assertSame($gone->employee_code, $this->actingAs($this->user('viewer'))->getJson('/search/employees?q='.$gone->employee_code)->json('0.code'));

        $this->actingAs($this->user('employee', ['employee_id' => $here->id]))->getJson('/search/employees?q=aamir')->assertForbidden();
    }

    public function test_sidebar_counts_are_for_hr_only(): void
    {
        $this->actingAs($this->user('hr'))->get('/dashboard')->assertInertia(fn (AssertableInertia $p) => $p->where('nav', ['requests' => 0, 'leave' => 0, 'inbox' => 0, 'reviews' => 0]));
        $this->actingAs($this->user('viewer'))->get('/dashboard')->assertInertia(fn (AssertableInertia $p) => $p->where('nav', null));
    }
}
