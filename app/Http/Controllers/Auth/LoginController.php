<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\WorkSchedule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Email + password login. No self-registration: an admin creates logins in Settings > Users. */
class LoginController extends Controller
{
    public function create(): Response
    {
        // The clock's workday ring and "time left" line: the default schedule's hours, else 9 to 6.
        $schedule = WorkSchedule::query()->where('is_default', true)->first(['type', 'start_time', 'end_time']);
        $fixed = $schedule && ! $schedule->isFlexible() && $schedule->start_time && $schedule->end_time;

        return Inertia::render('auth/login', [
            'workday' => ['start' => $fixed ? substr($schedule->start_time, 0, 5) : '09:00', 'end' => $fixed ? substr($schedule->end_time, 0, 5) : '18:00'],
            // Days to move the Islamic (Umm al-Qura) date by, when Pakistan's moon sighting differs (config hrm.hijri_offset)
            'hijriOffset' => (int) config('hrm.hijri_offset', 0),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // 5 wrong tries per email + IP per minute
        $key = Str::lower($data['email']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.',
            ]);
        }

        if (! Auth::attempt(['email' => Str::lower($data['email']), 'password' => $data['password'], 'is_active' => true], $request->boolean('remember'))) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['email' => 'That email and password don\'t match an active login.']);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        // Staff go where they were heading; an employee login only ever has My page.
        $user = $request->user();
        DB::table('users')->where('id', $user->id)->update(['last_login_at' => now()]);   // not a change worth logging

        return $user->canViewHr() ? redirect()->intended($user->homeUrl()) : redirect($user->homeUrl());
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
