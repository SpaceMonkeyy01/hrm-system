<?php

namespace Tests\Feature;

use App\Mail\RemindersDigest;
use App\Models\Asset;
use App\Models\AssetType;
use App\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Assets: company, automatic tags, HR-managed types, cost and warranty. Fake data only. */
class AssetRegisterTest extends TestCase
{
    use RefreshDatabase;

    private Company $bcc;

    private Company $emg;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        [$this->bcc] = $this->baseData();
        $this->emg = Company::create(['name' => 'Emerge', 'code' => 'EMG', 'employee_code_prefix' => 'EMG-', 'employee_code_digits' => 3]);
        $this->travelTo(CarbonImmutable::parse('2026-10-19 09:00'));
    }

    private function add(array $data)
    {
        return $this->actingAs($this->user('hr'))->post('/assets', $data + ['type' => 'Laptop', 'name' => 'Fake Laptop', 'status' => 'in_stock']);
    }

    public function test_new_assets_get_the_next_tag_per_company_and_type(): void
    {
        $this->add(['company_id' => $this->bcc->id])->assertSessionHas('success', 'Added BCC-LAP-001.');
        $this->add(['company_id' => $this->bcc->id])->assertSessionHas('success', 'Added BCC-LAP-002.');
        $this->add(['company_id' => $this->emg->id])->assertSessionHas('success', 'Added EMG-LAP-001.');
        $this->add(['company_id' => $this->bcc->id, 'type' => 'SIM card'])->assertSessionHas('success', 'Added BCC-SIM-001.');
        $this->add([])->assertSessionHas('success', 'Added LAP-001.');
        // A typed tag is kept; the next automatic one goes past the highest number
        $this->add(['company_id' => $this->bcc->id, 'asset_tag' => 'BCC-LAP-010'])->assertSessionHas('success', 'Added BCC-LAP-010.');
        $this->add(['company_id' => $this->bcc->id])->assertSessionHas('success', 'Added BCC-LAP-011.');
        $this->add(['asset_tag' => 'BCC-LAP-011'])->assertSessionHasErrors('asset_tag');
        $this->assertSame($this->emg->id, Asset::where('asset_tag', 'EMG-LAP-001')->value('company_id'));

        $this->actingAs($this->user('viewer'))->get("/assets?company={$this->emg->id}")->assertInertia(fn (AssertableInertia $p) => $p
            ->where('assets', fn ($a) => collect($a)->pluck('asset_tag')->all() === ['EMG-LAP-001']));
    }

    public function test_hr_manages_types_and_renames_follow_the_assets(): void
    {
        $hr = $this->user('hr');
        $this->add(['asset_tag' => 'X-1', 'type' => 'Headset']);
        $this->actingAs($hr)->post('/assets/types', ['name' => 'UPS', 'prefix' => 'ups'])->assertSessionHasNoErrors();
        $this->assertSame('UPS', AssetType::where('name', 'UPS')->value('prefix'));
        $this->actingAs($hr)->post('/assets/types', ['name' => 'Battery', 'prefix' => 'UPS'])->assertSessionHasErrors('prefix');
        $this->actingAs($hr)->post('/assets/types', ['name' => 'Bad', 'prefix' => 'B-1'])->assertSessionHasErrors('prefix');
        $this->add(['type' => 'UPS', 'company_id' => $this->bcc->id])->assertSessionHas('success', 'Added BCC-UPS-001.');

        $headset = AssetType::where('name', 'Headset')->first();
        $this->actingAs($hr)->put("/assets/types/{$headset->id}", ['name' => 'Headphones', 'prefix' => 'HDP', 'is_active' => false])->assertSessionHasNoErrors();
        $this->assertSame('Headphones', Asset::where('asset_tag', 'X-1')->value('type'));
        // Switched off: not for new assets, but its existing asset can still be saved
        $this->add(['type' => 'Headphones'])->assertSessionHasErrors('type');
        $x = Asset::where('asset_tag', 'X-1')->first();
        $this->actingAs($hr)->put("/assets/{$x->id}", ['asset_tag' => 'X-1', 'type' => 'Headphones', 'name' => 'Fake', 'status' => 'in_stock'])->assertSessionHasNoErrors();
        $this->actingAs($this->user('viewer'))->post('/assets/types', ['name' => 'Nope', 'prefix' => 'NOP'])->assertForbidden();
    }

    public function test_cost_warranty_totals_and_the_daily_email(): void
    {
        Mail::fake();
        $this->user('hr', ['email' => 'hr@example.test']);
        $this->add(['company_id' => $this->bcc->id, 'cost' => 150000, 'vendor' => 'Fake Traders', 'warranty_until' => '2026-11-05'])->assertSessionHasNoErrors();
        $this->add(['company_id' => $this->bcc->id, 'cost' => 50000.5, 'warranty_until' => '2027-06-01']);
        $this->add(['company_id' => $this->bcc->id]);   // no cost
        $this->add(['company_id' => $this->emg->id, 'cost' => 20000, 'warranty_until' => '2026-09-01']);   // expired
        $this->add(['company_id' => $this->emg->id, 'cost' => 99000, 'status' => 'retired', 'warranty_until' => '2026-10-25']);   // not in use
        $this->add(['cost' => -5])->assertSessionHasErrors('cost');

        $this->actingAs($this->user('viewer'))->get('/assets')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('warrantyEnding', 1)
            ->where('totals', fn ($t) => collect($t)->keyBy('company_id')->map(fn ($r) => [$r['count'], (float) $r['cost'], $r['no_cost']])->all()
                == [$this->bcc->id => [3, 200000.5, 1], $this->emg->id => [1, 20000.0, 0]])
            ->where('assets', fn ($a) => collect($a)->firstWhere('asset_tag', 'BCC-LAP-001')['vendor'] === 'Fake Traders'));
        $this->actingAs($this->user('viewer'))->get('/assets?warranty=ending')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('assets', fn ($a) => collect($a)->pluck('asset_tag')->all() === ['BCC-LAP-001']));
        $this->actingAs($this->user('viewer'))->get('/assets?warranty=expired')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('assets', fn ($a) => collect($a)->pluck('asset_tag')->all() === ['EMG-LAP-001']));

        $this->artisan('hr:send-reminders')->assertSuccessful();
        Mail::assertSent(RemindersDigest::class, fn (RemindersDigest $m) => $m->warranties === [['tag' => 'BCC-LAP-001', 'name' => 'Fake Laptop', 'until' => '2026-11-05']]
            && $m->hasSubject('HR reminders for 19 Oct 2026: 1 warranty ending'));
        $html = (new RemindersDigest(now(), [], 0, [], [['tag' => 'BCC-LAP-001', 'name' => 'Fake Laptop', 'until' => '2026-11-05']]))->render();
        $this->assertStringContainsString('BCC-LAP-001 Fake Laptop: warranty ends 5 Nov 2026', $html);
    }

    public function test_upload_fills_tags_company_cost_and_warranty(): void
    {
        $this->employee($this->emg, ['full_name' => 'Fake Holder', 'employee_code' => 'EMG-001']);
        $path = tempnam(sys_get_temp_dir(), 'csv');
        $fh = fopen($path, 'w');
        foreach ([
            ['Tag', 'Type', 'Company', 'Make and model', 'Serial number', 'Bought on', 'Cost', 'Vendor', 'Warranty until', 'Given to', 'Given on', 'Notes'],
            ['', 'Laptop', 'BCC', 'Fake A', '', '', '1,20,000', 'Fake Shop', '2027-01-31', '', '', ''],
            ['', 'Laptop', 'bcc', 'Fake B', '', '', '', '', '', '', '', ''],
            ['', 'SIM card', '', 'Fake SIM', '', '', '', '', '', 'EMG-001', '', ''],   // company from the holder
            ['', 'Laptop', 'Nowhere', 'Fake C', '', '', '', '', '', '', '', ''],
            ['', 'Laptop', '', 'Fake D', '', '', 'abc', '', '', '', '', ''],
        ] as $r) {
            fputcsv($fh, $r, escape: '');
        }
        fclose($fh);
        $hr = $this->user('hr');
        $this->actingAs($hr)->post('/imports/assets', ['file' => new UploadedFile($path, 'assets.csv', 'text/csv', null, true)]);
        $this->actingAs($hr)->get('/imports/assets')->assertInertia(fn (AssertableInertia $p) => $p->has('plan.changes', 3)->has('plan.problems', 2));
        $this->actingAs($hr)->post('/imports/assets/apply')->assertSessionHas('success', 'Added 3 assets, 1 given to someone.');

        $a = Asset::where('name', 'Fake A')->first();
        $this->assertSame(['BCC-LAP-001', $this->bcc->id, 120000.0, 'Fake Shop', '2027-01-31'], [$a->asset_tag, $a->company_id, $a->cost, $a->vendor, $a->warranty_until->toDateString()]);
        $this->assertSame('BCC-LAP-002', Asset::where('name', 'Fake B')->value('asset_tag'));
        $this->assertSame(['EMG-SIM-001', $this->emg->id], [Asset::where('name', 'Fake SIM')->value('asset_tag'), Asset::where('name', 'Fake SIM')->value('company_id')]);
    }
}
