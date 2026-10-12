<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Profile photos. Stored privately (config hrm.uploads.disk) as a 320 px square JPEG, so lists stay light.
 * Seen by HR-side logins (admin, hr, viewer) and by the person themselves; changed by HR or by the person
 * on My page.
 */
class PhotoController extends Controller
{
    private const SIZE = 320;

    public function show(Request $request, Employee $employee): Response
    {
        $user = $request->user();
        abort_unless($user->canViewHr() || $user->employee_id === $employee->id, 403);
        $disk = Storage::disk(config('hrm.uploads.disk'));
        abort_unless($employee->photo_path && $disk->exists($employee->photo_path), 404);

        return $disk->response($employee->photo_path, 'photo.jpg', [
            'Content-Type' => 'image/jpeg',
            // The URL changes with every new photo (?v=), so the browser may keep this one for a day.
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    /** HR sets someone's photo. */
    public function update(Request $request, Employee $employee): RedirectResponse
    {
        $this->store($request, $employee);

        return back()->with('success', "Saved {$employee->full_name}'s photo.");
    }

    public function destroy(Employee $employee): RedirectResponse
    {
        $this->remove($employee);

        return back()->with('success', 'Photo removed.');
    }

    /** The person sets their own photo on My page. */
    public function updateMine(Request $request): RedirectResponse
    {
        $employee = Employee::findOrFail($request->user()->employee_id);
        $this->store($request, $employee);

        return back()->with('success', 'Saved your photo.');
    }

    private function store(Request $request, Employee $employee): void
    {
        if (! config('hrm.uploads.enabled')) {
            throw ValidationException::withMessages(['photo' => 'Uploads are switched off on this site until a file store is set up.']);
        }
        $file = $request->validate([
            'photo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ], ['photo.mimes' => 'Use a JPG, PNG or WebP picture.', 'photo.max' => 'The picture is larger than 5 MB.'])['photo'];

        $jpeg = $this->square($file);
        $path = "employee-photos/{$employee->id}/".Str::random(32).'.jpg';
        $disk = Storage::disk(config('hrm.uploads.disk'));
        $disk->put($path, $jpeg);
        $old = $employee->photo_path;
        $employee->update(['photo_path' => $path]);
        if ($old && $old !== $path) {
            $disk->delete($old);
        }
    }

    private function remove(Employee $employee): void
    {
        if ($employee->photo_path) {
            Storage::disk(config('hrm.uploads.disk'))->delete($employee->photo_path);
            $employee->update(['photo_path' => null]);
        }
    }

    /** Centre-crop to a square and scale to SIZE px, as JPEG. Without GD the picture is kept as it came. */
    private function square(UploadedFile $file): string
    {
        $raw = (string) file_get_contents($file->getRealPath());
        if (! function_exists('imagecreatefromstring')) {
            return $raw;
        }
        $src = @imagecreatefromstring($raw);
        if (! $src) {
            throw ValidationException::withMessages(['photo' => 'That picture can\'t be read. Try another file.']);
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $side = min($w, $h);
        $dst = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));   // transparent PNGs get a white background
        imagecopyresampled($dst, $src, 0, 0, intdiv($w - $side, 2), intdiv($h - $side, 2), self::SIZE, self::SIZE, $side, $side);
        ob_start();
        imagejpeg($dst, null, 85);

        return (string) ob_get_clean();
    }
}
