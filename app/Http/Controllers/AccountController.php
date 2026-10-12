<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/** Change your own name and password. */
class AccountController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('account', ['user' => $request->user()->only('name', 'email', 'role')]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'current_password' => ['nullable', 'required_with:password', 'current_password'],
            'password' => ['nullable', 'confirmed', Password::min(8)],
        ], ['current_password.current_password' => 'Your current password is wrong.']);

        $user = $request->user();
        $user->name = $data['name'];
        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }
        $user->save();

        return back()->with('success', ! empty($data['password']) ? 'Name and password saved.' : 'Saved.');
    }
}
