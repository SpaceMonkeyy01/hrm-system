<?php

namespace App\Http\Controllers;

use App\Services\Import\AssetListImport;
use App\Services\Import\EmployeeDetailsImport;
use App\Services\Import\SpreadsheetImport;
use App\Support\Csv;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Download a sheet, fill it in Excel, upload, preview, save. Nothing is saved until HR has seen every change.
 * The uploaded file waits in private storage between the two steps (one per kind, per session).
 */
class SpreadsheetImportController extends Controller
{
    /** @var array<string, class-string<SpreadsheetImport>> */
    public const KINDS = ['details' => EmployeeDetailsImport::class, 'assets' => AssetListImport::class];

    public function show(Request $request, string $kind): Response
    {
        $import = $this->importer($kind);
        $upload = $request->session()->get("import.$kind");
        $plan = null;
        if ($upload && Storage::disk('local')->exists($upload['path'])) {
            try {
                $plan = $import->plan(Storage::disk('local')->path($upload['path']), $upload['ext']);
            } catch (RuntimeException $e) {
                $this->forget($request, $kind);
                $request->session()->now('error', $e->getMessage());
            }
        }
        $public = ['name', 'code', 'label', 'old', 'new', 'replaces'];

        return Inertia::render('imports/show', [
            'kind' => $kind,
            'meta' => $import::meta(),
            'upload' => $plan ? ['name' => $upload['name']] : null,
            'plan' => $plan ? [
                'rows' => $plan['rows'], 'matched' => $plan['matched'], 'problems' => $plan['problems'],
                // Only what HR reads; the values to save stay on the server.
                'changes' => array_map(fn ($c) => array_intersect_key($c, array_flip($public)), $plan['changes']),
                'records' => count(array_unique(array_map(fn ($c) => $c['code'] ?: $c['name'], $plan['changes']))),
            ] : null,
            'maxKb' => config('hrm.uploads.max_kb'),
        ]);
    }

    public function template(string $kind): StreamedResponse
    {
        [$header, $rows] = (self::KINDS[$kind])::template();

        return Csv::download("$kind-".now()->format('Y-m-d').'.csv', $header, $rows);
    }

    public function upload(Request $request, string $kind): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'extensions:csv,xlsx', 'max:'.config('hrm.uploads.max_kb')]],
            ['file.extensions' => 'Upload the file as .xlsx or .csv.']);
        $this->forget($request, $kind);
        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());
        $path = $file->storeAs('spreadsheet-imports', now()->format('Ymd-His').'-'.Str::random(8).'.'.$ext, 'local');
        $request->session()->put("import.$kind", ['path' => $path, 'ext' => $ext, 'name' => $file->getClientOriginalName()]);

        return redirect()->route('imports.show', $kind);
    }

    public function apply(Request $request, string $kind): RedirectResponse
    {
        $import = $this->importer($kind);
        $upload = $request->session()->get("import.$kind");
        if (! $upload || ! Storage::disk('local')->exists($upload['path'])) {
            throw ValidationException::withMessages(['file' => 'Upload the file again: the preview has expired.']);
        }
        // Planned again from the file, so what is saved is checked against the data as it is now.
        $summary = $import->apply($import->plan(Storage::disk('local')->path($upload['path']), $upload['ext']), $request->user()->id);
        $this->forget($request, $kind);

        return redirect()->route('imports.show', $kind)->with('success', $summary);
    }

    public function discard(Request $request, string $kind): RedirectResponse
    {
        $this->forget($request, $kind);

        return redirect()->route('imports.show', $kind);
    }

    private function importer(string $kind): SpreadsheetImport
    {
        return app(self::KINDS[$kind]);
    }

    private function forget(Request $request, string $kind): void
    {
        if ($old = $request->session()->pull("import.$kind")) {
            Storage::disk('local')->delete($old['path']);
        }
    }
}
