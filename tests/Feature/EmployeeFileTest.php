<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ChecklistItem;
use App\Models\Company;
use App\Models\EmployeeFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Scanned documents on the private disk. Fake files only. */
class EmployeeFileTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        [$this->company] = $this->baseData();
    }

    private function cnicItem(): ChecklistItem
    {
        return ChecklistItem::where('list', 'document')->where('key', 'cnic_copy')->firstOrFail();
    }

    public function test_hr_uploads_a_file_linked_to_a_document(): void
    {
        $e = $this->employee($this->company);
        $hr = $this->user('hr');

        $this->actingAs($hr)->post("/employees/{$e->id}/files", [
            'file' => UploadedFile::fake()->create('cnic scan.pdf', 300, 'application/pdf'),
            'checklist_item_id' => $this->cnicItem()->id,
            'label' => 'Front and back',
        ])->assertRedirect()->assertSessionHas('success');

        $f = EmployeeFile::sole();
        $this->assertSame([$e->id, $this->cnicItem()->id, 'Front and back', 'cnic scan.pdf', $hr->id],
            [$f->employee_id, $f->checklist_item_id, $f->label, $f->original_name, $f->uploaded_by]);
        $this->assertStringStartsWith("employee-files/{$e->id}/", $f->path);
        $this->assertStringNotContainsString('cnic', $f->path);   // stored under a random name
        Storage::disk('local')->assertExists($f->path);
        $this->assertTrue(ActivityLog::where('subject_type', 'EmployeeFile')->where('event', 'created')->exists());
    }

    public function test_only_pdf_jpg_png_up_to_the_limit(): void
    {
        $e = $this->employee($this->company);
        $hr = $this->user('hr');

        foreach ([
            UploadedFile::fake()->create('macro.docx', 10, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
            UploadedFile::fake()->create('script.php', 1, 'text/x-php'),
            UploadedFile::fake()->create('huge.pdf', 10241, 'application/pdf'),
        ] as $bad) {
            $this->actingAs($hr)->post("/employees/{$e->id}/files", ['file' => $bad])->assertSessionHasErrors('file');
        }
        // A checklist item from another list is refused.
        $onboardingItem = ChecklistItem::where('list', 'onboarding')->first();
        $this->actingAs($hr)->post("/employees/{$e->id}/files", ['file' => UploadedFile::fake()->image('a.png'), 'checklist_item_id' => $onboardingItem->id])
            ->assertSessionHasErrors('checklist_item_id');

        $this->actingAs($hr)->post("/employees/{$e->id}/files", ['file' => UploadedFile::fake()->image('photo.jpg')])->assertSessionHasNoErrors();
        $this->assertSame(1, EmployeeFile::count());
        $this->assertSame(1, count(Storage::disk('local')->allFiles()));
    }

    public function test_viewers_cannot_list_open_upload_or_remove_files(): void
    {
        $e = $this->employee($this->company);
        $this->actingAs($this->user('hr'))->post("/employees/{$e->id}/files", ['file' => UploadedFile::fake()->image('photo.png')]);
        $f = EmployeeFile::sole();
        $viewer = $this->user('viewer');

        $this->actingAs($viewer)->get("/employees/{$e->id}")->assertInertia(fn (AssertableInertia $p) => $p
            ->where('files', null)->where('fileCount', 1));
        $this->actingAs($viewer)->get("/employees/{$e->id}/files/{$f->id}")->assertForbidden();
        $this->actingAs($viewer)->post("/employees/{$e->id}/files", ['file' => UploadedFile::fake()->image('x.png')])->assertForbidden();
        $this->actingAs($viewer)->delete("/employees/{$e->id}/files/{$f->id}")->assertForbidden();
        Storage::disk('local')->assertExists($f->path);
    }

    public function test_hr_opens_downloads_and_removes_a_file(): void
    {
        $e = $this->employee($this->company);
        $other = $this->employee($this->company);
        $hr = $this->user('hr');
        $this->actingAs($hr)->post("/employees/{$e->id}/files", ['file' => UploadedFile::fake()->create('nda.pdf', 5, 'application/pdf')]);
        $f = EmployeeFile::sole();

        $this->actingAs($hr)->get("/employees/{$e->id}")->assertInertia(fn (AssertableInertia $p) => $p
            ->has('files', 1)->where('files.0.name', 'nda.pdf'));

        $open = $this->actingAs($hr)->get("/employees/{$e->id}/files/{$f->id}")->assertOk();
        $this->assertStringStartsWith('inline', $open->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $open->headers->get('X-Content-Type-Options'));
        $download = $this->actingAs($hr)->get("/employees/{$e->id}/files/{$f->id}?download=1")->assertOk();
        $this->assertStringContainsString('attachment; filename=nda.pdf', $download->headers->get('Content-Disposition'));

        // Another employee's URL does not reach the file.
        $this->actingAs($hr)->get("/employees/{$other->id}/files/{$f->id}")->assertNotFound();
        $this->actingAs($hr)->delete("/employees/{$other->id}/files/{$f->id}")->assertNotFound();

        $this->actingAs($hr)->delete("/employees/{$e->id}/files/{$f->id}")->assertRedirect();
        $this->assertSame(0, EmployeeFile::count());
        Storage::disk('local')->assertMissing($f->path);
    }
}
