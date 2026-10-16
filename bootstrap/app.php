<?php

use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['sidebar_state']);
        // Behind Render's (or any) HTTPS proxy: read the real scheme and client IP from its headers.
        $middleware->trustProxies(at: env('TRUSTED_PROXIES', '*'));
        $middleware->web(append: [
            HandleInertiaRequests::class,
            EnsureUserIsActive::class,
        ]);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn (Request $request) => $request->user()?->homeUrl() ?? route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        // Logged-in users get 403 / 404 inside the app (with the sidebar and a link home), not a bare error page.
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            if (in_array($response->getStatusCode(), [403, 404], true) && $request->user() && ! $request->expectsJson() && $request->isMethod('GET')) {
                // A missing record (/employees/999) fails before HandleInertiaRequests runs, so share its props here too,
                // or the page has no login details and renders blank.
                Inertia::share(app(HandleInertiaRequests::class)->share($request));

                return Inertia::render('error', ['status' => $response->getStatusCode()])->toResponse($request)->setStatusCode($response->getStatusCode());
            }

            return $response;
        });
    })->create();
