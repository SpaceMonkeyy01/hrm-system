<?php

namespace App\Http\Controllers;

use App\Models\AttendanceRequest;
use App\Models\LeaveRequest;
use App\Models\WfhRequest;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HR's one inbox (can:edit-hr): everything staff asked for on My page, with how many wait and since when. Each kind
 * keeps its own queue page; this lists them so nothing is missed. The sidebar badge is the total (HandleInertiaRequests).
 */
class RequestsInboxController extends Controller
{
    /** key => [model, title, what it is, queue url, date column of the oldest] */
    private const QUEUES = [
        'attendance' => [AttendanceRequest::class, 'Attendance fixes', 'A forgotten punch or wrong times on a day.', '/attendance/requests', 'date'],
        'leave' => [LeaveRequest::class, 'Leave', 'Leave applications with the signed form.', '/leave/requests', 'start_date'],
        'wfh' => [WfhRequest::class, 'Work from home', 'Days someone asks to work from home.', '/attendance/wfh', 'start_date'],
    ];

    /** @return array<string, int> key => requests waiting for HR */
    public static function pendingCounts(): array
    {
        return array_map(fn ($q) => $q[0]::query()->where('status', 'pending')->whereHas('employee')->count(), self::QUEUES);
    }

    public function index(): Response
    {
        $counts = self::pendingCounts();

        return Inertia::render('requests', [
            'queues' => collect(self::QUEUES)->map(fn ($q, $key) => [
                'key' => $key, 'title' => $q[1], 'description' => $q[2], 'href' => $q[3], 'pending' => $counts[$key],
                'oldest' => $counts[$key]
                    ? CarbonImmutable::parse($q[0]::query()->where('status', 'pending')->whereHas('employee')->min('created_at'))->toIso8601String() : null,
            ])->values(),
        ]);
    }
}
