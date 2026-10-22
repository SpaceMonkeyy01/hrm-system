<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRecord;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\WfhRequest;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** The All / BCC / Emerge switch (?company=) on Requests, its queues and Leave. Today is Wed 20 May 2026. */
class CompanySwitchTest extends TestCase
{
    use RefreshDatabase;

    private Company $bcc;

    private Company $emerge;

    private Employee $bccPerson;

    private Employee $emgPerson;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->bcc] = $this->baseData();
        $this->travelTo(CarbonImmutable::parse('2026-05-20 09:00'));
        $this->emerge = Company::create(['name' => 'Emerge', 'code' => 'EMG', 'employee_code_prefix' => 'EMG-', 'employee_code_digits' => 3]);
        $this->bccPerson = $this->employee($this->bcc, ['full_name' => 'Fake Bcc', 'start_date' => '2025-01-01']);
        $this->emgPerson = $this->employee($this->emerge, ['full_name' => 'Fake Emerge', 'start_date' => '2025-01-01']);
        $type = LeaveType::first()->id;
        foreach ([$this->bccPerson, $this->emgPerson] as $e) {
            LeaveRequest::create(['employee_id' => $e->id, 'leave_type_id' => $type, 'start_date' => '2026-05-25', 'end_date' => '2026-05-25']);
            LeaveRecord::create(['employee_id' => $e->id, 'leave_type_id' => $type, 'start_date' => '2026-05-20', 'end_date' => '2026-05-20', 'part' => 'full', 'days' => 1]);
        }
        WfhRequest::create(['employee_id' => $this->emgPerson->id, 'start_date' => '2026-05-21', 'end_date' => '2026-05-21', 'reason' => 'Plumber']);
    }

    private function props(string $url): array
    {
        $props = [];
        $this->actingAs($this->user('hr'))->get($url)->assertOk()->assertInertia(function (AssertableInertia $p) use (&$props) {
            $props = $p->toArray()['props'];
        });

        return $props;
    }

    public function test_requests_inbox_and_queues_narrow_to_one_company(): void
    {
        $pending = fn (array $p) => collect($p['queues'])->pluck('pending', 'key')->only(['leave', 'wfh'])->all();

        $all = $this->props('/requests');
        $this->assertSame([null, ['leave' => 2, 'wfh' => 1]], [$all['company'], $pending($all)]);
        $this->assertSame(['BCC', 'Emerge'], array_column($all['companies'], 'name'));

        $bcc = $this->props("/requests?company={$this->bcc->id}");
        $this->assertSame([$this->bcc->id, ['leave' => 1, 'wfh' => 0]], [$bcc['company'], $pending($bcc)]);
        $this->assertSame("/leave/requests?company={$this->bcc->id}", collect($bcc['queues'])->firstWhere('key', 'leave')['href']);   // the choice carries on
        $this->assertNull($this->props('/requests?company=999')['company']);   // unknown company = all

        $emg = $this->props("/leave/requests?company={$this->emerge->id}");
        $this->assertSame([['Fake Emerge'], 1], [array_column(array_column($emg['requests'], 'employee'), 'name'), $emg['counts']['pending']]);
        $this->assertSame([], $this->props("/attendance/wfh?company={$this->bcc->id}")['requests']);
        $this->assertCount(1, $this->props("/attendance/wfh?company={$this->emerge->id}")['requests']);
        $this->assertSame(0, $this->props("/loans?company={$this->bcc->id}")['counts']['pending'] ?? 0);
        $this->assertSame([], $this->props("/attendance/requests?company={$this->bcc->id}")['requests']);
    }

    public function test_leave_page_narrows_people_and_holidays(): void
    {
        Holiday::create(['date' => '2026-05-28', 'name' => 'Fake common']);
        Holiday::create(['date' => '2026-05-29', 'name' => 'Fake BCC day', 'company_id' => $this->bcc->id]);
        Holiday::create(['date' => '2026-05-29', 'name' => 'Fake Emerge day', 'company_id' => $this->emerge->id]);

        $all = $this->props('/leave');
        $this->assertSame([null, 2, 3, 2], [$all['company'], count($all['records']), count($all['holidays']), count($all['onLeaveToday'])]);

        $bcc = $this->props("/leave?company={$this->bcc->id}");
        $this->assertSame($this->bcc->id, $bcc['company']);
        $this->assertSame(['Fake Bcc'], array_column(array_column($bcc['records'], 'employee'), 'name'));
        $this->assertSame(['Fake Bcc'], array_column($bcc['onLeaveToday'], 'name'));
        $this->assertSame(['Fake Bcc'], array_column($bcc['balances']['rows'], 'name'));
        $this->assertSame(['Fake common', 'Fake BCC day'], array_column($bcc['holidays'], 'name'));
        $this->assertSame([$this->bccPerson->id], array_column($bcc['people'], 'id'));
    }
}
