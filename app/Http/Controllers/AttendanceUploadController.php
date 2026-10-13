<?php

namespace App\Http\Controllers;

use App\Services\Import\AttendanceFileImporter;
use App\Services\Import\AttendanceFilePreview;
use App\Services\Import\MachineSyncer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Upload, check, then import: HR sees what each attendance file holds (days, people, what's new, warnings)
 * and confirms before any punch is saved. Files wait in private storage between the steps, per session.
 * POST attendance/imports (AttendanceController::upload) still imports in one step, for scripts.
 */
class AttendanceUploadController extends Controller
{
    private const KEY = 'attendance.pending';

    private const MAX_FILES = 5;

    public function check(Request $request, AttendanceFileImporter $importer): RedirectResponse
    {
        $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:'.self::MAX_FILES],
            'files.*' => ['required', 'file', 'extensions:xls,xlsx', 'max:'.config('hrm.uploads.max_kb')],
            'expected_date' => ['nullable', 'date_format:Y-m-d'],
        ], [
            'files.max' => 'Check up to '.self::MAX_FILES.' files at a time.',
            'files.*.extensions' => 'Upload the .xls export as it comes from the machine or HR.my (or an .xlsx copy).',
        ]);
        self::forget($request);

        $pending = [];
        foreach ($request->file('files') as $file) {
            $entry = ['id' => Str::random(12), 'name' => $file->getClientOriginalName()];
            $path = $file->storeAs('attendance-imports', now()->format('Ymd-His').'-'.Str::random(8).'.'.strtolower($file->getClientOriginalExtension()), 'local');
            try {
                $importer->punches(Storage::disk('local')->path($path), $entry['name']);
                $entry['path'] = $path;
            } catch (ValidationException $e) {
                // Kept in the list with the reason, so HR sees why this one can't be imported next to the others.
                Storage::disk('local')->delete($path);
                $entry['error'] = collect($e->errors())->flatten()->first();
            }
            $pending[] = $entry;
        }
        $request->session()->put(self::KEY, ['expected_date' => $request->input('expected_date'), 'files' => $pending]);

        return redirect()->route('attendance.imports');
    }

    public function confirm(Request $request, AttendanceFileImporter $importer): RedirectResponse
    {
        $request->validate(['id' => ['nullable', 'string', 'max:20']]);
        $pending = $request->session()->get(self::KEY);
        $ready = collect($pending['files'] ?? [])->filter(fn ($f) => isset($f['path']) && (! $request->filled('id') || $f['id'] === $request->input('id')));
        if ($ready->isEmpty() || $ready->contains(fn ($f) => ! Storage::disk('local')->exists($f['path']))) {
            self::forget($request);
            throw ValidationException::withMessages(['files' => 'The check has expired. Choose the files again.']);
        }

        $done = [];
        foreach ($ready as $f) {
            // Read again from the file, so what is saved is checked against the punches as they are now.
            $r = $importer->import(Storage::disk('local')->path($f['path']), $f['name'], $f['path'], $request->user()->id);
            $done[] = AttendanceFileImporter::SOURCES[$r['source']].": {$r['added']} new ".Str::plural('punch', $r['added']);
        }
        // Imported files are kept (the import row points at them); only the list of waiting files changes.
        $left = array_values(array_filter($pending['files'], fn ($f) => ! $ready->contains('id', $f['id'])));
        if (array_filter($left, fn ($f) => isset($f['path']))) {
            $request->session()->put(self::KEY, [...$pending, 'files' => $left]);
        } else {
            $request->session()->forget(self::KEY);
        }

        return redirect()->route('attendance.imports')->with('success', 'Imported. '.implode('; ', $done).'.');
    }

    /** "Sync now": read the attendance machine straight away. */
    public function syncMachine(Request $request, MachineSyncer $syncer): RedirectResponse
    {
        abort_unless(MachineSyncer::configured(), 404);
        set_time_limit(180);
        $sync = $syncer->run('manual', $request->user()->id);

        return back()->with($sync->ok ? 'success' : 'error', MachineSyncer::summary($sync));
    }

    public function discard(Request $request): RedirectResponse
    {
        $request->validate(['id' => ['nullable', 'string', 'max:20']]);
        if (! $request->filled('id')) {
            self::forget($request);
        } elseif ($pending = $request->session()->get(self::KEY)) {
            $keep = [];
            foreach ($pending['files'] as $f) {
                if ($f['id'] === $request->input('id')) {
                    isset($f['path']) && Storage::disk('local')->delete($f['path']);
                } else {
                    $keep[] = $f;
                }
            }
            $keep ? $request->session()->put(self::KEY, [...$pending, 'files' => $keep]) : $request->session()->forget(self::KEY);
        }

        return redirect()->route('attendance.imports');
    }

    /** The files waiting for HR to confirm, each with its preview (or why it can't be imported). */
    public static function pending(Request $request): ?array
    {
        $pending = $request->session()->get(self::KEY);
        if (! $pending) {
            return null;
        }
        $importer = app(AttendanceFileImporter::class);
        $read = [];
        foreach ($pending['files'] as $f) {
            if (isset($f['path']) && Storage::disk('local')->exists($f['path'])) {
                $read[$f['id']] = $importer->punches(Storage::disk('local')->path($f['path']), $f['name']);
            }
        }
        // Days each source covers across the batch, so a machine file next to its HR.my file doesn't ask for it.
        $covers = ['machine' => [], 'hrmy' => []];
        foreach ($read as $r) {
            $covers[$r['source']] = [...$covers[$r['source']], ...array_column($r['rows'], 'work_date')];
        }
        $files = [];
        foreach ($pending['files'] as $f) {
            $r = $read[$f['id']] ?? null;
            $files[] = ['id' => $f['id'], 'name' => $f['name'],
                'error' => $f['error'] ?? ($r ? null : 'The check has expired. Choose this file again.'),
                'preview' => $r ? app(AttendanceFilePreview::class)->build($r, $pending['expected_date'],
                    array_values(array_unique($covers[$r['source'] === 'machine' ? 'hrmy' : 'machine']))) : null];
        }

        return ['expected_date' => $pending['expected_date'], 'files' => $files];
    }

    private static function forget(Request $request): void
    {
        foreach ($request->session()->pull(self::KEY)['files'] ?? [] as $f) {
            isset($f['path']) && Storage::disk('local')->delete($f['path']);
        }
    }
}
