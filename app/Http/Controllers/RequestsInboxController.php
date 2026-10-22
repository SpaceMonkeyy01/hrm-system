<?php

namespace App\Http\Controllers;

use App\Models\AttendanceRequest;
use App\Models\Company;
use App\Models\LeaveRequest;
use App\Models\Loan;
use App\Models\MedicalRequest;
use App\Models\WfhRequest;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
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
        'loans' => [Loan::class, 'Loans', 'Loan requests, with the amount and months to repay.', '/loans', 'created_at'],
        'medical' => [MedicalRequest::class, 'Medical', 'Medical bills to claim back and advances for treatment.', '/medical', 'created_at'],
    ];

    /** @return array<string, int> key => requests waiting for HR (from people of $company only, when given) */
    public static function pendingCounts(?int $company = null): array
    {
        return array_map(fn ($q) => self::waiting($q[0], $company)->count(), self::QUEUES);
    }

    /** @return list<array{key: string, title: string, description: string, href: string, pending: int, oldest: ?string}> */
    public static function queues(?int $company = null): array
    {
        $counts = self::pendingCounts($company);

        return collect(self::QUEUES)->map(fn ($q, $key) => [
            'key' => $key, 'title' => $q[1], 'description' => $q[2], 'href' => $q[3].($company ? "?company=$company" : ''), 'pending' => $counts[$key],
            'oldest' => $counts[$key] ? CarbonImmutable::parse(self::waiting($q[0], $company)->min('created_at'))->toIso8601String() : null,
        ])->values()->all();
    }

    public function index(): Response
    {
        $switch = Company::switchProps();

        return Inertia::render('requests', ['queues' => self::queues($switch['company'])] + $switch);
    }

    /** @param class-string<\Illuminate\Database\Eloquent\Model> $model */
    private static function waiting(string $model, ?int $company): Builder
    {
        return $model::query()->where('status', 'pending')->whereHas('employee', fn ($q) => $q->inCompany($company));
    }
}
