<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Admins create logins and set roles. There is no public sign-up. */
class UserController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('settings/users', [
            'users' => User::query()->orderBy('name')->get(['id', 'name', 'email', 'role', 'is_active', 'created_at'])
                ->map(fn ($u) => [...$u->only('id', 'name', 'email', 'role', 'is_active'), 'created_at' => $u->created_at?->toIso8601String()]),
            'roles' => config('hrm.roles'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        // Emails are stored lowercased, so check uniqueness on the lowercased value too.
        if (is_string($request->input('email'))) {
            $request->merge(['email' => strtolower(trim($request->input('email')))]);
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'role' => ['required', Rule::in(array_keys(config('hrm.roles')))],
            'password' => ['required', Password::min(8)],
        ]);
        $user = User::create($data + ['is_active' => true]);

        return back()->with('success', "Login created for {$user->email}. Share the password with them directly.");
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'role' => ['required', Rule::in(array_keys(config('hrm.roles')))],
            'is_active' => ['required', 'boolean'],
            'password' => ['nullable', Password::min(8)],
        ]);

        // Don't let an admin lock themselves (and possibly everyone) out.
        if ($user->is($request->user()) && ($data['role'] !== 'admin' || ! $data['is_active'])) {
            throw ValidationException::withMessages(['role' => 'You can\'t remove your own admin access or switch off your own login.']);
        }
        if (empty($data['password'])) {
            unset($data['password']);
        }
        $user->update($data);

        return back()->with('success', "Saved {$user->email}.");
    }
}
