<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeeFile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Upload, open and remove an employee's scanned documents. Files live on a private disk (config hrm.uploads.disk:
 * "local" = storage/app/private, or a private S3-compatible bucket), never under public/, so the only way to them
 * is through these routes (can:edit-hr).
 */
class EmployeeFileController extends Controller
{
    public function store(Request $request, Employee $employee): RedirectResponse
    {
        $cfg = config('hrm.uploads');
        if (! $cfg['enabled']) {
            throw ValidationException::withMessages(['file' => 'Uploads are switched off on this site until a file store is set up. Keep the file in the Drive folder for now.']);
        }
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:'.implode(',', $cfg['extensions']), 'max:'.$cfg['max_kb']],
            'checklist_item_id' => ['nullable', 'integer', Rule::exists('checklist_items', 'id')->where('list', 'document')],
            'label' => ['nullable', 'string', 'max:150'],
        ], [
            'file.mimes' => 'Upload a PDF, JPG or PNG file.',
            'file.max' => 'The file is larger than '.round($cfg['max_kb'] / 1024).' MB.',
            'file.uploaded' => 'The file did not arrive. It may be larger than the server allows.',
        ]);

        $file = $data['file'];
        // Random name with the extension worked out from the content, not the client's file name.
        $ext = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension());
        $path = $file->storeAs("employee-files/{$employee->id}", Str::random(40).'.'.$ext, config('hrm.uploads.disk'));

        try {
            EmployeeFile::create([
                'employee_id' => $employee->id,
                'checklist_item_id' => $data['checklist_item_id'] ?? null,
                'label' => $data['label'] ?? null,
                'original_name' => Str::limit(basename($file->getClientOriginalName()), 250, ''),
                'path' => $path,
                'mime' => $file->getMimeType() ?? 'application/octet-stream',
                'size' => $file->getSize(),
                'uploaded_by' => $request->user()->id,
            ]);
        } catch (\Throwable $e) {
            Storage::disk(config('hrm.uploads.disk'))->delete($path);   // no orphan file without a row
            throw $e;
        }

        return back()->with('success', 'Uploaded '.$file->getClientOriginalName().'.');
    }

    /** Opens in the browser (PDF and images display inline); ?download=1 saves it instead. */
    public function show(Request $request, Employee $employee, EmployeeFile $file): StreamedResponse
    {
        abort_unless($file->employee_id === $employee->id, 404);
        abort_unless(Storage::disk(config('hrm.uploads.disk'))->exists($file->path), 404, 'The file is missing from storage.');

        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return Storage::disk(config('hrm.uploads.disk'))->response($file->path, $file->original_name, [
            'Content-Type' => $file->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ], $disposition);
    }

    public function destroy(Employee $employee, EmployeeFile $file): RedirectResponse
    {
        abort_unless($file->employee_id === $employee->id, 404);

        DB::transaction(fn () => $file->delete());
        Storage::disk(config('hrm.uploads.disk'))->delete($file->path);

        return back()->with('success', "Removed {$file->original_name}.");
    }
}
