<?php

namespace Tests\Feature;

use App\Models\ChecklistEntry;
use App\Models\ChecklistItem;
use App\Models\Company;
use App\Models\IssuedLetter;
use App\Models\LetterTemplate;
use App\Services\LetterRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Letter templates, drafts, issuing and the print page. Fake people only. */
class LetterTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->company] = $this->baseData();
    }

    public function test_render_fills_fields_and_lists_what_is_not_on_file(): void
    {
        $e = $this->employee($this->company, ['full_name' => 'Fake Writer', 'start_date' => '2024-03-01', 'cnic' => null]);
        $out = LetterRenderer::render('{{name}} joined on {{start_date}}. CNIC {{cnic}}. {{details}} {{unknown}}', $e->load('company'), null);

        $this->assertSame('Fake Writer joined on 1 March 2024. CNIC [CNIC]. [Details typed when issuing] {{unknown}}', $out['body']);
        $this->assertSame(['CNIC', 'Details typed when issuing'], $out['missing']);
    }

    public function test_hr_drafts_and_issues_a_letter_which_gets_a_reference_and_ticks_the_checklist(): void
    {
        $e = $this->employee($this->company, ['full_name' => 'Fake Leaver']);
        $template = LetterTemplate::where('checklist_key', 'experience_letter_issued')->firstOrFail();
        $hr = $this->user('hr');

        $this->actingAs($hr)->get("/employees/{$e->id}/letters/new?template={$template->id}")->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('letters/draft')
                ->where('template.id', $template->id)->where('reference', 'BCC/HR/'.now()->year.'/001')
                ->where('draft.body', fn ($b) => str_contains($b, 'Fake Leaver')));

        $this->actingAs($hr)->post("/employees/{$e->id}/letters", ['template_id' => $template->id, 'subject' => 'Experience letter', 'body' => 'Edited text.'])
            ->assertRedirect();
        $letter = IssuedLetter::sole();
        $this->assertSame('BCC/HR/'.now()->year.'/001', $letter->reference);
        $this->assertSame('Edited text.', $letter->body);
        $item = ChecklistItem::where('key', 'experience_letter_issued')->firstOrFail();
        $this->assertSame('Done', ChecklistEntry::where('employee_id', $e->id)->where('checklist_item_id', $item->id)->value('status'));

        $this->actingAs($hr)->post("/employees/{$e->id}/letters", ['body' => 'Second.']);
        $this->assertSame('BCC/HR/'.now()->year.'/002', IssuedLetter::latest('id')->value('reference'));

        $this->actingAs($hr)->get("/letters/{$letter->id}")->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('print/letter')->where('letter.reference', $letter->reference));
        $this->actingAs($hr)->get("/employees/{$e->id}")
            ->assertInertia(fn (AssertableInertia $p) => $p->has('letters', 2));
    }

    public function test_viewers_cannot_see_or_issue_letters_and_only_admin_withdraws(): void
    {
        $e = $this->employee($this->company);
        $letter = IssuedLetter::create(['employee_id' => $e->id, 'reference' => 'BCC/HR/2026/001', 'body' => 'x', 'issued_on' => '2026-05-13']);
        $viewer = $this->user('viewer');

        $this->actingAs($viewer)->get("/employees/{$e->id}/letters/new")->assertForbidden();
        $this->actingAs($viewer)->get("/letters/{$letter->id}")->assertForbidden();
        $this->actingAs($viewer)->get('/letters/templates')->assertForbidden();
        $this->actingAs($viewer)->get("/employees/{$e->id}")->assertInertia(fn (AssertableInertia $p) => $p->where('letters', null));

        $this->actingAs($this->user('hr'))->delete("/letters/{$letter->id}")->assertForbidden();
        $this->actingAs($this->user('admin'))->delete("/letters/{$letter->id}")->assertRedirect();
        $this->assertModelMissing($letter);
    }

    public function test_hr_adds_and_edits_templates(): void
    {
        $hr = $this->user('hr');
        $this->actingAs($hr)->get('/letters/templates')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('letters/templates')->has('templates', 4)->missing('templates.0.body'));
        $this->actingAs($hr)->get('/letters/templates/new')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('letters/template')->where('template', null)->has('fields'));

        $this->actingAs($hr)->post('/letters/templates', ['name' => 'Salary certificate', 'body' => 'For {{name}}.', 'is_active' => true])
            ->assertSessionHasNoErrors()->assertRedirect('/letters/templates');
        $t = LetterTemplate::where('name', 'Salary certificate')->firstOrFail();
        $this->actingAs($hr)->get("/letters/templates/{$t->id}/edit")->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('letters/template')->where('template.body', 'For {{name}}.')->where('issued', 0));
        $this->actingAs($hr)->put("/letters/templates/{$t->id}", ['name' => 'Experience letter', 'body' => 'x', 'is_active' => true])
            ->assertSessionHasErrors('name');
        $this->actingAs($hr)->put("/letters/templates/{$t->id}", ['name' => 'Salary certificate', 'body' => 'x', 'checklist_key' => 'nope', 'is_active' => false])
            ->assertSessionHasErrors('checklist_key');
        $this->actingAs($hr)->put("/letters/templates/{$t->id}", ['name' => 'Salary certificate', 'body' => 'New.', 'is_active' => false])
            ->assertSessionHasNoErrors()->assertRedirect('/letters/templates');
        $this->assertFalse($t->fresh()->is_active);
    }

    public function test_list_shows_what_issuing_does_and_how_often_it_was_issued(): void
    {
        $e = $this->employee($this->company);
        $template = LetterTemplate::where('checklist_key', 'experience_letter_issued')->firstOrFail();
        IssuedLetter::create(['employee_id' => $e->id, 'letter_template_id' => $template->id, 'reference' => 'BCC/HR/2026/001', 'body' => 'x', 'issued_on' => '2026-05-13']);
        IssuedLetter::create(['employee_id' => $e->id, 'letter_template_id' => $template->id, 'reference' => 'BCC/HR/2026/002', 'body' => 'x', 'issued_on' => '2026-06-02']);
        $label = ChecklistItem::where('key', 'experience_letter_issued')->value('label');

        $this->actingAs($this->user('hr'))->get('/letters/templates')
            ->assertInertia(fn (AssertableInertia $p) => $p->has('templates', 4, fn (AssertableInertia $row) => $row->hasAll(['id', 'name', 'subject', 'details_label', 'is_active', 'checklist', 'conduct', 'issued', 'last_issued'])->etc())
                ->where('templates', fn ($rows) => collect($rows)->contains(fn ($r) => $r['id'] === $template->id && $r['issued'] === 2 && $r['last_issued'] === '2026-06-02' && $r['checklist'] === $label)));
    }

    public function test_offered_switch_flips_and_is_logged(): void
    {
        $t = LetterTemplate::firstOrFail();
        $hr = $this->user('hr');

        $this->actingAs($hr)->patch("/letters/templates/{$t->id}/active", ['is_active' => false])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertFalse($t->fresh()->is_active);
        $this->assertDatabaseHas('activity_log', ['subject_type' => 'LetterTemplate', 'subject_id' => $t->id, 'event' => 'updated', 'user_id' => $hr->id]);
        $this->actingAs($hr)->patch("/letters/templates/{$t->id}/active", [])->assertSessionHasErrors('is_active');
    }

    public function test_preview_fills_unsaved_text_for_a_person_and_saves_nothing(): void
    {
        $e = $this->employee($this->company, ['full_name' => 'Fake Reader', 'cnic' => null]);
        $before = [LetterTemplate::count(), IssuedLetter::count()];

        $this->actingAs($this->user('hr'))->postJson('/letters/templates/preview', ['employee_id' => $e->id, 'body' => 'Dear {{name}}, CNIC {{cnic}}. {{details}}', 'details' => 'Thanks.'])
            ->assertOk()->assertJson(['body' => 'Dear Fake Reader, CNIC [CNIC]. Thanks.', 'missing' => ['CNIC']])->assertJsonStructure(['reference']);
        $this->assertSame($before, [LetterTemplate::count(), IssuedLetter::count()]);

        $this->actingAs($this->user('hr'))->postJson('/letters/templates/preview', ['employee_id' => 999999, 'body' => 'x'])->assertJsonValidationErrors('employee_id');
    }

    public function test_viewers_cannot_open_or_change_templates(): void
    {
        $t = LetterTemplate::firstOrFail();
        $e = $this->employee($this->company);
        $viewer = $this->user('viewer');

        $this->actingAs($viewer)->get('/letters/templates/new')->assertForbidden();
        $this->actingAs($viewer)->get("/letters/templates/{$t->id}/edit")->assertForbidden();
        $this->actingAs($viewer)->patch("/letters/templates/{$t->id}/active", ['is_active' => false])->assertForbidden();
        $this->actingAs($viewer)->postJson('/letters/templates/preview', ['employee_id' => $e->id, 'body' => '{{cnic}}'])->assertForbidden();
        $this->assertTrue($t->fresh()->is_active);
    }
}
