<?php

namespace App\Http\Controllers;

use App\Models\ConductEntry;
use App\Models\Employee;
use App\Models\Meeting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The Feedback tab on a profile (can:edit-hr): the conduct record (warnings, appreciation) and meeting notes.
 * Changing an entry the person already acknowledged asks them to acknowledge it again.
 */
class FeedbackController extends Controller
{
    /** Both lists for the profile, newest first. */
    public static function forEmployee(int $employeeId): array
    {
        return [
            'conduct' => ConductEntry::query()->with('author:id,name', 'letter:id,reference')->where('employee_id', $employeeId)
                ->latest('happened_on')->latest('id')->get()->map->props(),
            'meetings' => Meeting::query()->with('author:id,name')->where('employee_id', $employeeId)
                ->latest('held_on')->latest('id')->get()->map->props(),
            'conductTypes' => ConductEntry::TYPES,
            'meetingTypes' => Meeting::TYPES,
        ];
    }

    public function storeConduct(Request $request, Employee $employee): RedirectResponse
    {
        ConductEntry::create($this->conductData($request) + ['employee_id' => $employee->id, 'created_by' => $request->user()->id]);

        return back()->with('success', 'Added to the conduct record. '.$employee->full_name.' sees it on My page.');
    }

    public function updateConduct(Request $request, ConductEntry $entry): RedirectResponse
    {
        $entry->fill($this->conductData($request));
        if ($entry->isDirty() && $entry->acknowledged_at) {
            $entry->acknowledged_at = null;
        }
        $entry->save();

        return back()->with('success', 'Saved.');
    }

    public function destroyConduct(ConductEntry $entry): RedirectResponse
    {
        $entry->delete();

        return back()->with('success', 'Removed from the conduct record.');
    }

    public function storeMeeting(Request $request, Employee $employee): RedirectResponse
    {
        $m = Meeting::create($this->meetingData($request) + ['employee_id' => $employee->id, 'created_by' => $request->user()->id]);

        return back()->with('success', $m->shared ? "Saved. {$employee->full_name} sees these notes on My page." : 'Saved. Only HR and admin see these notes.');
    }

    public function updateMeeting(Request $request, Meeting $meeting): RedirectResponse
    {
        $meeting->fill($this->meetingData($request));
        if ($meeting->isDirty(['notes', 'actions', 'held_on', 'type', 'attendees']) && $meeting->acknowledged_at) {
            $meeting->acknowledged_at = null;
        }
        $meeting->save();

        return back()->with('success', 'Saved.');
    }

    public function destroyMeeting(Meeting $meeting): RedirectResponse
    {
        $meeting->delete();

        return back()->with('success', 'Meeting notes removed.');
    }

    private function conductData(Request $request): array
    {
        return $request->validate([
            'type' => ['required', Rule::in(array_keys(ConductEntry::TYPES))],
            'happened_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'title' => ['required', 'string', 'max:200'],
            'details' => ['nullable', 'string', 'max:5000'],
        ], ['happened_on.before_or_equal' => 'The date can\'t be in the future.', 'title.required' => 'Give it a short title, e.g. "Late to client meetings".']);
    }

    private function meetingData(Request $request): array
    {
        return $request->validate([
            'type' => ['required', Rule::in(array_keys(Meeting::TYPES))],
            'held_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'attendees' => ['nullable', 'string', 'max:300'],
            'notes' => ['required', 'string', 'max:10000'],
            'actions' => ['nullable', 'string', 'max:5000'],
            'shared' => ['boolean'],
        ], ['held_on.before_or_equal' => 'The date can\'t be in the future.', 'notes.required' => 'Write what was discussed.']);
    }
}
