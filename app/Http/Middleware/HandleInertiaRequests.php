<?php

namespace App\Http\Middleware;

use App\Models\AttendanceRequest;
use App\Models\Review;
use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * Props every page receives: the logged-in user, what they may do, and one-off flash messages.
 */
class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'appName' => config('app.name'),
            'auth' => [
                'user' => $user ? $user->only('id', 'name', 'email', 'role') : null,
                'can' => [
                    'edit' => (bool) $user?->canEdit(),
                    'admin' => (bool) $user?->isAdmin(),
                    'view' => (bool) $user?->canViewHr(),
                    'self' => (bool) $user?->hasSelfService(),
                ],
            ],
            // One-off messages from ->with('success' | 'error', ...). The id lets the browser show
            // each message exactly once, even when a later partial reload keeps the old props.
            'flash' => function () use ($request) {
                $success = $request->session()->get('success');
                $error = $request->session()->get('error');

                return [
                    'success' => $success,
                    'error' => $error,
                    'id' => ($success || $error) ? substr(sha1($success.'|'.$error.'|'.microtime(true)), 0, 10) : null,
                ];
            },
            // Things waiting for HR, as sidebar badges. Two small counts, only for logins that can act on them.
            'nav' => fn () => $user?->canEdit() ? [
                'requests' => AttendanceRequest::where('status', 'pending')->whereHas('employee')->count(),
                'leave' => \App\Models\LeaveRequest::where('status', 'pending')->whereHas('employee')->count(),
                'reviews' => Review::where('status', 'manager_review')->whereHas('cycle', fn ($q) => $q->where('status', 'open'))->whereHas('employee')->count(),
            ] : null,
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
