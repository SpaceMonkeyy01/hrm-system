<?php

namespace App\Http\Controllers;

use App\Models\AttendanceDevice;
use App\Models\AttendancePunch;
use App\Models\Company;
use App\Models\Employee;
use App\Services\AttendanceLinks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Attendance > Links (HR and admin): everyone's machine number, device and HR.my ID in one table, edited in the row,
 * plus the numbers that punch but belong to nobody, with name suggestions. Every change is logged on the employee.
 * Bulk changes go through Excel (imports/links, AttendanceLinksImport).
 */
class AttendanceLinkController extends Controller
{
    public function index(): Response
    {
        $people = AttendanceLinks::people();

        return Inertia::render('attendance/links', [
            'rows' => AttendanceLinks::rows(),
            'unlinked' => AttendanceLinks::unlinked($people),
            'people' => $people->map(fn (Employee $e) => ['id' => $e->id, 'label' => "{$e->full_name} ({$e->employee_code})",
                'machine' => $e->attendance_machine_id, 'hrmy' => $e->hr_portal_id])->values(),
            'devices' => AttendanceDevice::query()->orderBy('id')->get(['id', 'code', 'name'])
                ->map(fn ($d) => ['id' => $d->id, 'code' => $d->code, 'name' => $d->name])->values(),
            'companies' => Company::query()->orderBy('name')->get(['id', 'name']),
            'staleDays' => AttendanceLinks::STALE_DAYS,
        ]);
    }

    /** Change one person's links from the table. An empty value removes that link. */
    public function update(Request $request, Employee $employee): RedirectResponse
    {
        abort_unless(in_array($employee->status, ['active', 'offboarding'], true), 422, 'Links are kept for current staff only.');
        $data = $request->validate([
            'attendance_machine_id' => ['nullable', 'string', 'max:60'],
            'hr_portal_id' => ['nullable', 'string', 'max:60'],
            'attendance_device_id' => ['nullable', 'integer', 'exists:attendance_devices,id'],
        ]);
        $data = array_map(fn ($v) => is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v, $data);

        $errors = [];
        foreach (AttendanceLinks::FIELDS as $source => $field) {
            if (! array_key_exists($field, $data) || $data[$field] === null) {
                continue;
            }
            $taken = Employee::where($field, $data[$field])->where('id', '!=', $employee->id)->first();
            if ($taken) {
                $errors[$field] = ucfirst(AttendanceLinks::NOUNS[$source])." {$data[$field]} already belongs to {$taken->full_name} ({$taken->employee_code}).";
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        // A new machine number with no device chosen gets the device it punches on.
        if (($data['attendance_machine_id'] ?? null) && ! ($data['attendance_device_id'] ?? null)
            && (string) $data['attendance_machine_id'] !== (string) $employee->attendance_machine_id) {
            $data['attendance_device_id'] = AttendancePunch::deviceFor($data['attendance_machine_id']) ?? $employee->attendance_device_id;
        }
        if (array_key_exists('attendance_machine_id', $data) && $data['attendance_machine_id'] === null) {
            $data['attendance_device_id'] = null;   // no number, no device
        }
        $employee->update($data);

        return back()->with('success', "Saved {$employee->full_name}'s links.");
    }

    /** Numbers that punch but belong to nobody: link them (picked in the table). */
    public function link(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'source' => ['required', Rule::in(array_keys(AttendanceLinks::FIELDS))],
            'links' => ['required', 'array', 'min:1', 'max:500'],
            'links.*.device_user_id' => ['required', 'string', 'max:40', 'distinct'],
            'links.*.employee_id' => ['required', 'integer', 'distinct', Rule::exists('employees', 'id')->withoutTrashed()],
        ], ['links.*.employee_id.distinct' => 'The same person is picked twice.']);
        $n = AttendanceLinks::link($data['source'], $data['links']);
        $what = AttendanceLinks::NOUNS[$data['source']];

        return back()->with('success', $n === 1 ? "Linked 1 $what." : "Linked $n {$what}s.");
    }

    /** Link every number whose name matches exactly one person who has no number of that kind yet. */
    public function acceptMatches(Request $request): RedirectResponse
    {
        $source = $request->validate(['source' => ['nullable', Rule::in(array_keys(AttendanceLinks::FIELDS))]])['source'] ?? null;
        $picked = [];
        foreach (AttendanceLinks::unlinked() as $u) {
            if ($u['confidence'] === 'match' && (! $source || $u['source'] === $source)) {
                $picked[$u['source']][$u['suggestion']][] = $u['device_user_id'];
            }
        }
        $n = 0;
        $skipped = 0;
        foreach ($picked as $src => $byPerson) {
            $links = [];
            foreach ($byPerson as $employeeId => $numbers) {
                if (count($numbers) === 1) {
                    $links[] = ['device_user_id' => $numbers[0], 'employee_id' => (int) $employeeId];
                } else {
                    $skipped += count($numbers);   // two numbers point at one person: HR picks
                }
            }
            $n += $links ? AttendanceLinks::link($src, $links) : 0;
        }

        return back()->with('success', ($n === 1 ? 'Linked 1 exact match.' : "Linked $n exact matches.")
            .($skipped ? " $skipped left for you: two numbers point to the same person." : ''));
    }
}
