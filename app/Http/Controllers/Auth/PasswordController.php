<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Forgot your password?" and the page the emailed link opens. Two brokers share the token table:
 * users (forgotten password, short expiry) and invites (a login HR just created, longer expiry).
 */
class PasswordController extends Controller
{
    public function request(): Response
    {
        return Inertia::render('auth/forgot-password', LoginController::sceneProps());
    }

    public function email(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $user = User::where('email', Str::lower($data['email']))->where('is_active', true)->first();
        // Same answer whether or not the email has a login, so this page can't be used to find out who works here.
        if ($user) {
            Password::broker('users')->sendResetLink(['email' => $user->email]);
        }

        return back()->with('success', 'If that email has a login, a link to choose a new password is on its way.');
    }

    public function edit(Request $request, string $token): Response
    {
        return Inertia::render('auth/reset-password', [
            'token' => $token, 'email' => (string) $request->query('email', ''), 'invite' => $request->boolean('invite'),
        ] + LoginController::sceneProps());
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
            'invite' => ['boolean'],
        ]);
        $broker = ! empty($data['invite']) ? 'invites' : 'users';
        $status = Password::broker($broker)->reset(
            ['email' => Str::lower($data['email']), 'password' => $data['password'], 'password_confirmation' => $request->input('password_confirmation'), 'token' => $data['token']],
            function (User $user, string $password) {
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            },
        );
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => 'This link has run out or was already used. Ask for a new one under "Forgot your password?".']);
        }

        return redirect()->route('login')->with('success', 'Password saved. Log in with it now.');
    }
}
