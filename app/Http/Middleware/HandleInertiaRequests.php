<?php

namespace App\Http\Middleware;

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
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
