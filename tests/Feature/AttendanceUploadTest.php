<?php

namespace Tests\Feature;

use App\Models\AttendancePunch;
use App\Models\Company;
use App\Models\Holiday;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Support\XlsBuilder;
use Tests\TestCase;

/** Upload, check, then import: nothing is saved until HR confirms. Fake people and files only. */
class AttendanceUploadTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        [$this->company] = $this->baseData();
        $this->hr = $this->user('hr');
        $this->travelTo(CarbonImmutable::parse('2026-05-14 09:00'));   // a Thursday; the files are for Wednesday 13 May
    }

    private function machineFile(array $punches, string $name = 'InOutData.xls'): UploadedFile
    {
        return new UploadedFile(XlsBuilder::save(XlsBuilder::punchLog($punches)), $name, null, null, true);
    }

    private function hrmyFile(): UploadedFile
    {
        $table = [['Attendance Transactions for 2026-05-13'], [], ['Employer:', 'BCC'],
            ['Date', 'ID', 'Name', 'Status', 'First Clock In', 'Last Clock Out', 'Remark'],
            ['2026-05-13', 'BCC-901', 'Fake Remote', 'Present', '09:05 AM', '06:10 PM', '']];

        return new UploadedFile(XlsBuilder::save(XlsBuilder::biff2($table)), 'transactions.xls', null, null, true);
    }

    private function pending(): ?array
    {
        $props = null;
        $this->actingAs($this->hr)->get('/attendance/imports')->assertInertia(function (AssertableInertia $p) use (&$props) {
            $props = $p->toArray()['props']['pending'];
        });

        return $props;
    }

    public function test_checking_saves_nothing_and_shows_what_the_file_holds(): void
    {
        $this->employee($this->company, ['full_name' => 'Fake Linked', 'attendance_machine_id' => '7']);
        $this->actingAs($this->hr)->post('/attendance/imports/check', [
            'files' => [$this->machineFile([['Fake Linked', '7', '5/13/2026 9:58:03 AM'], ['Fake Linked', '7', '5/13/2026 6:02:00 PM'], ['Stranger', '8', '5/13/2026 10:00:00 AM']])],
            'expected_date' => '2026-05-13',
        ])->assertRedirect('/attendance/imports');

        $this->assertSame(0, AttendancePunch::count());
        $p = $this->pending()['files'][0]['preview'];
        $this->assertSame(['machine', 'Full day log', 3, 3, 2, 1], [$p['source'], $p['format'], $p['punches'], $p['new'], $p['people'], $p['linked']]);
        $this->assertSame([['id' => '8', 'name' => 'Stranger']], $p['unlinked']);
        $this->assertSame([['date' => '2026-05-13', 'punches' => 3, 'new' => 3, 'people' => 2, 'single' => 1]], $p['days']);
        $this->assertContains('Wednesday 13 May 2026 has no HR.my data yet. Upload that file too.', array_column($p['warnings'], 'text'));
        $this->assertSame([], array_filter($p['warnings'], fn ($w) => $w['tone'] === 'warn'));

        $this->actingAs($this->hr)->post('/attendance/imports/confirm')->assertSessionHas('success', 'Imported. Attendance machine: 3 new punches.');
        $this->assertSame(3, AttendancePunch::count());
        $this->assertNull($this->pending());
    }

    public function test_warnings_for_wrong_day_already_uploaded_and_half_a_day(): void
    {
        Holiday::create(['date' => '2026-05-13', 'name' => 'Fake Holiday']);
        $people = array_map(fn ($n) => ["Fake $n", (string) $n, '5/13/2026 9:00:00 AM'], range(1, 6));
        $this->actingAs($this->hr)->post('/attendance/imports/check', ['files' => [$this->machineFile($people)]]);
        $this->actingAs($this->hr)->post('/attendance/imports/confirm');

        $this->actingAs($this->hr)->post('/attendance/imports/check', ['files' => [$this->machineFile($people)], 'expected_date' => '2026-05-12']);
        $texts = array_column($this->pending()['files'][0]['preview']['warnings'], 'text');
        $this->assertContains('Nothing new: every punch in this file is already in. Importing it changes nothing.', $texts);
        $this->assertContains('You expected Tuesday 12 May 2026, but this file has Wednesday 13 May 2026.', $texts);
        $this->assertContains('On Wednesday 13 May 2026, 6 of 6 people have only one punch. Was the file exported before the end of the day?', $texts);
        $this->assertContains('Wednesday 13 May 2026 is a holiday: Fake Holiday.', $texts);
    }

    public function test_two_files_at_once_with_one_refused_and_import_all(): void
    {
        $fullReport = new UploadedFile(XlsBuilder::save(XlsBuilder::biff2([['Emp No.', 'AC-No.', 'No.', 'Name', 'Date', 'Clock In']])), 'report.xls', null, null, true);
        $this->actingAs($this->hr)->post('/attendance/imports/check', [
            'files' => [$this->machineFile([['Fake', '7', '5/13/2026 9:58:03 AM']]), $this->hrmyFile(), $fullReport],
        ]);
        $files = $this->pending()['files'];
        $this->assertSame(['Full day log', 'Attendance Transactions', null], array_map(fn ($f) => $f['preview']['format'] ?? null, $files));
        $this->assertStringContainsString('Full Report', $files[2]['error']);
        // Each file covers the other's day, so neither asks for "that file too".
        $this->assertSame([], array_filter([...$files[0]['preview']['warnings'], ...$files[1]['preview']['warnings']], fn ($w) => str_contains($w['text'], 'Upload that file too')));
        $this->assertCount(2, Storage::disk('local')->files('attendance-imports'));   // the refused file isn't kept

        $this->actingAs($this->hr)->post('/attendance/imports/confirm')
            ->assertSessionHas('success', 'Imported. Attendance machine: 1 new punch; HR.my: 2 new punches.');
        $this->assertSame(['hrmy' => 2, 'machine' => 1], AttendancePunch::query()->selectRaw('source, count(*) as n')->groupBy('source')->orderBy('source')->pluck('n', 'source')->map(fn ($n) => (int) $n)->all());
    }

    public function test_cancel_one_file_or_all_deletes_the_waiting_copies(): void
    {
        $this->actingAs($this->hr)->post('/attendance/imports/check', ['files' => [$this->machineFile([['Fake', '7', '5/13/2026 9:58:03 AM']]), $this->hrmyFile()]]);
        $first = $this->pending()['files'][0]['id'];

        $this->actingAs($this->hr)->post('/attendance/imports/discard', ['id' => $first]);
        $this->assertSame(['transactions.xls'], array_column($this->pending()['files'], 'name'));
        $this->assertCount(1, Storage::disk('local')->files('attendance-imports'));

        $this->actingAs($this->hr)->post('/attendance/imports/discard');
        $this->assertNull($this->pending());
        $this->assertCount(0, Storage::disk('local')->files('attendance-imports'));
        $this->actingAs($this->hr)->post('/attendance/imports/confirm')->assertSessionHasErrors('files');
        $this->assertSame(0, AttendancePunch::count());
    }

    public function test_viewers_cannot_upload_or_confirm(): void
    {
        $viewer = $this->user('viewer');
        $this->actingAs($viewer)->post('/attendance/imports/check', ['files' => [$this->hrmyFile()]])->assertForbidden();
        $this->actingAs($viewer)->post('/attendance/imports/confirm')->assertForbidden();
    }
}
