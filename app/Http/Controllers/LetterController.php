<?php

namespace App\Http\Controllers;

use App\Models\ChecklistEntry;
use App\Models\ChecklistItem;
use App\Models\Employee;
use App\Models\IssuedLetter;
use App\Models\LetterTemplate;
use App\Services\LetterRenderer;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Letters: pick a template for a person, check and edit the draft, issue it (reference number, kept on their
 * record), print it or save it as PDF from the browser. HR and admin only: letters carry CNIC numbers.
 */
class LetterController extends Controller
{
    public function templates(): Response
    {
        return Inertia::render('letters/templates', [
            'templates' => LetterTemplate::query()->orderBy('sort_order')->orderBy('name')->get()
                ->map->only('id', 'name', 'subject', 'body', 'details_label', 'checklist_key', 'is_active'),
            'fields' => LetterRenderer::FIELDS,
            'checklistItems' => ChecklistItem::where('list', 'offboarding')->where('is_active', true)->orderBy('sort_order')->get(['key', 'label'])
                ->map(fn ($i) => ['value' => $i->key, 'label' => $i->label]),
        ]);
    }

    public function storeTemplate(Request $request): RedirectResponse
    {
        $t = LetterTemplate::create($this->templateData($request, null) + ['sort_order' => (int) LetterTemplate::max('sort_order') + 10]);

        return back()->with('success', "Added the template {$t->name}.");
    }

    public function updateTemplate(Request $request, LetterTemplate $template): RedirectResponse
    {
        $template->update($this->templateData($request, $template));

        return back()->with('success', "Saved {$template->name}.");
    }

    /** The draft: the chosen template filled in for this person, with anything not on file listed. */
    public function draft(Request $request, Employee $employee): Response
    {
        $f = $request->validate(['template' => ['nullable', 'integer'], 'details' => ['nullable', 'string', 'max:5000']]);
        $templates = LetterTemplate::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get();
        $template = $templates->firstWhere('id', (int) ($f['template'] ?? 0)) ?? $templates->first();
        $employee->load(['company:id,name,code', 'department:id,name', 'team:id,name']);
        $draft = $template ? LetterRenderer::render($template->body, $employee, $f['details'] ?? null) : null;

        return Inertia::render('letters/draft', [
            'employee' => ['id' => $employee->id, 'name' => $employee->full_name, 'code' => $employee->employee_code, 'photo' => $employee->photoUrl()],
            'templates' => $templates->map->only('id', 'name', 'details_label'),
            'template' => $template?->only('id', 'name', 'subject', 'details_label'),
            'details' => $f['details'] ?? '',
            'draft' => $draft,
            'reference' => LetterRenderer::nextReference($employee),
        ]);
    }

    public function issue(Request $request, Employee $employee): RedirectResponse
    {
        $data = $request->validate([
            'template_id' => ['nullable', 'integer', Rule::exists('letter_templates', 'id')],
            'subject' => ['nullable', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:20000'],
        ], ['body.required' => 'The letter is empty.']);
        $template = isset($data['template_id']) ? LetterTemplate::find($data['template_id']) : null;
        $employee->loadMissing('company:id,code');

        $letter = DB::transaction(function () use ($data, $employee, $template, $request) {
            $letter = IssuedLetter::create([
                'employee_id' => $employee->id, 'letter_template_id' => $template?->id, 'reference' => LetterRenderer::nextReference($employee),
                'subject' => $data['subject'] ?? null, 'body' => $data['body'], 'issued_on' => CarbonImmutable::today()->toDateString(),
                'issued_by' => $request->user()->id,
            ]);
            // e.g. the experience letter ticks "Experience letter issued" in offboarding.
            if ($template?->checklist_key && ($item = ChecklistItem::where('list', 'offboarding')->where('key', $template->checklist_key)->first())) {
                ChecklistEntry::updateOrCreate(['employee_id' => $employee->id, 'checklist_item_id' => $item->id], ['status' => 'Done']);
            }

            return $letter;
        });

        return redirect()->route('letters.show', $letter)->with('success', "Issued {$letter->reference}. Print it, or choose Save as PDF in the print window.");
    }

    /** The letter on an A4 page, ready to print (print/letter has no sidebar). */
    public function show(IssuedLetter $letter): Response
    {
        $letter->load(['employee' => fn ($q) => $q->withTrashed()->with('company:id,name'), 'issuer:id,name']);

        return Inertia::render('print/letter', [
            'letter' => [
                ...$letter->only('id', 'reference', 'subject', 'body'),
                'issued_on' => $letter->issued_on->toDateString(),
                'issued_by' => $letter->issuer?->name,
                'company' => $letter->employee->company?->name,
                'employee' => ['id' => $letter->employee->id, 'name' => $letter->employee->full_name],
            ],
        ]);
    }

    public function destroy(IssuedLetter $letter): RedirectResponse
    {
        $employeeId = $letter->employee_id;
        $letter->delete();

        return redirect()->route('employees.show', $employeeId)->with('success', 'Letter withdrawn. Its reference number is not reused.');
    }

    /** Letters issued to one person, newest first (profile, Documents and assets tab). */
    public static function letterRows(int $employeeId): array
    {
        return IssuedLetter::query()->with('issuer:id,name')->where('employee_id', $employeeId)->latest('issued_on')->latest('id')->get()
            ->map(fn (IssuedLetter $l) => ['id' => $l->id, 'reference' => $l->reference, 'subject' => $l->subject,
                'issued_on' => $l->issued_on->toDateString(), 'issued_by' => $l->issuer?->name])->all();
    }

    private function templateData(Request $request, ?LetterTemplate $template): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('letter_templates', 'name')->ignore($template?->id)],
            'subject' => ['nullable', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:20000'],
            'details_label' => ['nullable', 'string', 'max:150'],
            'checklist_key' => ['nullable', 'string', Rule::exists('checklist_items', 'key')->where('list', 'offboarding')],
            'is_active' => ['boolean'],
        ], ['name.unique' => 'Another template already has this name.']);
    }
}
