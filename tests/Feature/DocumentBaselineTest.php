<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ChecklistEntry;
use App\Models\ChecklistItem;
use App\Models\Company;
use App\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Old-staff baseline on the document checklist: blank cells of long-serving staff become "Old employee". Fake people only. */
class DocumentBaselineTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->company] = $this->baseData();
        $this->travelTo(CarbonImmutable::parse('2026-10-16 10:00'));
    }

    private function docStatus(Employee $e, string $key): ?string
    {
        $item = ChecklistItem::where('list', 'document')->where('key', $key)->first();

        return ChecklistEntry::where('employee_id', $e->id)->where('checklist_item_id', $item->id)->value('status');
    }

    private function set(Employee $e, string $key, ?string $status): void
    {
        $item = ChecklistItem::where('list', 'document')->where('key', $key)->first();
        ChecklistEntry::updateOrCreate(['employee_id' => $e->id, 'checklist_item_id' => $item->id], ['status' => $status]);
    }

    public function test_marks_only_blank_cells_of_people_who_started_before_the_date(): void
    {
        $old = $this->employee($this->company, ['full_name' => 'Old Timer', 'start_date' => '2019-03-01']);
        $this->set($old, 'cnic_copy', 'Yes');
        $this->set($old, 'bank_details', 'No');
        $this->set($old, 'photo', null);   // a row that exists but is blank counts as blank
        $new = $this->employee($this->company, ['full_name' => 'New Joiner', 'start_date' => '2026-09-20']);
        $undated = $this->employee($this->company, ['full_name' => 'No Date', 'start_date' => null]);
        $left = $this->employee($this->company, ['full_name' => 'Gone', 'start_date' => '2018-01-01', 'status' => 'exited']);
        $items = ChecklistItem::where('list', 'document')->where('is_active', true)->count();
        $hr = $this->user('hr');

        $this->actingAs($hr)->get('/documents')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('baseline', ['before' => '2026-04-19', 'people' => 1, 'cells' => $items - 2, 'undated' => 1, 'undated_cells' => $items]));
        $this->actingAs($this->user('viewer'))->get('/documents')->assertInertia(fn (AssertableInertia $p) => $p->where('baseline', null));
        $this->actingAs($this->user('viewer'))->post('/documents/baseline', ['started_before' => '2026-04-19'])->assertForbidden();

        $this->actingAs($hr)->post('/documents/baseline', ['started_before' => '2026-04-19'])
            ->assertSessionHas('success', 'Marked '.($items - 2).' blank documents "Old employee" for 1 person.');

        $this->assertSame('Yes', $this->docStatus($old, 'cnic_copy'));
        $this->assertSame('No', $this->docStatus($old, 'bank_details'));
        $this->assertSame('Old employee', $this->docStatus($old, 'photo'));
        $this->assertSame('Old employee', $this->docStatus($old, 'nda_signed'));
        $this->assertNull($this->docStatus($new, 'photo'));
        $this->assertNull($this->docStatus($undated, 'photo'));
        $this->assertNull($this->docStatus($left, 'photo'));

        // Logged on the person's timeline, one row per cell.
        $logs = ActivityLog::where('employee_id', $old->id)->where('description', 'like', '%old-staff baseline%')->get();
        $this->assertCount($items - 2, $logs);
        $this->assertSame(['status' => [null, 'Old employee']], $logs->first()->changes);
        $this->assertSame($hr->id, $logs->first()->user_id);
        $this->assertNotNull($logs->first()->subject_id);

        // Old Timer still owes bank details, so stays Pending; the rest is done.
        $this->actingAs($hr)->get('/documents?status=Pending')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('rows', fn ($rows) => collect($rows)->pluck('name')->sort()->values()->all() === ['New Joiner', 'No Date', 'Old Timer']));

        // Undated ones on request; running again changes nothing.
        $this->actingAs($hr)->post('/documents/baseline', ['started_before' => '2026-04-19', 'include_undated' => true])
            ->assertSessionHas('success', "Marked $items blank documents \"Old employee\" for 1 person.");
        $this->assertSame('Old employee', $this->docStatus($undated, 'photo'));
        $this->actingAs($hr)->post('/documents/baseline', ['started_before' => '2026-04-19', 'include_undated' => true])
            ->assertSessionHas('success', 'Nothing to mark: those people have no blank documents.');
    }

    public function test_recent_filter_shows_joiners_of_the_last_six_months_and_undated(): void
    {
        $this->employee($this->company, ['full_name' => 'Old Timer', 'start_date' => '2019-03-01']);
        $this->employee($this->company, ['full_name' => 'New Joiner', 'start_date' => '2026-09-20']);
        $this->employee($this->company, ['full_name' => 'No Date', 'start_date' => null]);

        $this->actingAs($this->user('viewer'))->get('/documents?scope=recent')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('rows', fn ($rows) => collect($rows)->pluck('name')->sort()->values()->all() === ['New Joiner', 'No Date']));
    }
}
