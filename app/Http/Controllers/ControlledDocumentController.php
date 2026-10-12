<?php

namespace App\Http\Controllers;

use App\Models\ControlledDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Controlled_Documents register: policies, templates, forms and letters with a BCC-0001 number. */
class ControlledDocumentController extends Controller
{
    public function index(Request $request): Response
    {
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::in(config('hrm.document_types'))],
            'status' => ['nullable', Rule::in(config('hrm.document_statuses'))],
        ]);

        $docs = ControlledDocument::query()
            ->when($f['q'] ?? null, fn ($q, $t) => $q->where(fn ($w) => $w->where('name', 'like', "%$t%")->orWhere('doc_number', 'like', "%$t%")))
            ->when($f['type'] ?? null, fn ($q, $v) => $q->where('doc_type', $v))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderBy('doc_number')->get()
            ->map(fn ($d) => [
                ...$d->only(['id', 'doc_number', 'doc_type', 'name', 'description', 'status', 'link_url']),
                'issued_on' => $d->issued_on?->toDateString(),
                'obsolete_on' => $d->obsolete_on?->toDateString(),
            ]);

        return Inertia::render('controlled-documents/index', [
            'documents' => $docs,
            'filters' => $f,
            'types' => config('hrm.document_types'),
            'statuses' => config('hrm.document_statuses'),
            'nextNumber' => ControlledDocument::nextNumber(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $doc = ControlledDocument::create($this->validated($request) + ['doc_number' => ControlledDocument::nextNumber()]);

        return back()->with('success', "Added {$doc->doc_number} {$doc->name}.");
    }

    public function update(Request $request, ControlledDocument $document): RedirectResponse
    {
        $document->update($this->validated($request));

        return back()->with('success', "Saved {$document->doc_number}.");
    }

    public function destroy(ControlledDocument $document): RedirectResponse
    {
        $document->delete();

        return back()->with('success', "Deleted {$document->doc_number}.");
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'doc_type' => ['required', Rule::in(config('hrm.document_types'))],
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', Rule::in(config('hrm.document_statuses'))],
            'issued_on' => ['nullable', 'date'],
            'obsolete_on' => ['nullable', 'date', 'after_or_equal:issued_on'],
            'link_url' => ['nullable', 'url', 'max:500'],
        ]);
    }
}
