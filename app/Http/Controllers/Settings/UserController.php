<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\SetPasswordNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Str;
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
            'users' => User::query()->with('employee:id,full_name,employee_code')->orderBy('name')->get(['id', 'name', 'email', 'role', 'is_active', 'employee_id', 'created_at'])
                ->map(fn ($u) => [...$u->only('id', 'name', 'email', 'role', 'is_active', 'employee_id'),
                    'employee' => $u->employee ? "{$u->employee->full_name} ({$u->employee->employee_code})" : null,
                    'created_at' => $u->created_at?->toIso8601String()]),
            'roles' => config('hrm.roles'),
            'staffLogins' => (fn ($c) => ['ready' => $c['ready']->count(), 'noEmail' => $c['noEmail'], 'emailTaken' => $c['emailTaken']])(StaffLoginController::candidates()),
            // Current staff, for linking a login to its own employee record (My page).
            'employees' => Employee::query()->whereIn('status', ['active', 'offboarding'])->orderBy('full_name')->get(['id', 'full_name', 'employee_code'])
                ->map(fn ($e) => ['value' => $e->id, 'label' => "{$e->full_name} ({$e->employee_code})"]),
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
            // With the welcome email the person chooses their own password from the link, so HR doesn't set one.
            'send_invite' => ['boolean'],
            'password' => ['exclude_if:send_invite,true', 'required', Password::min(8)],
            ...$this->employeeRule(null),
        ], self::MESSAGES);
        $invite = $request->boolean('send_invite');
        $user = User::create(collect($data)->except('send_invite')->all() + ['is_active' => true]
            + ($invite ? ['password' => Str::random(40)] : []));   // unknown to everyone until they use the link

        if (! $invite) {
            return back()->with('success', "Login created for {$user->email}. Share the password with them directly.");
        }
        try {
            $user->notify(new SetPasswordNotification(PasswordBroker::broker('invites')->createToken($user), invite: true));
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', "Login created for {$user->email}, but the welcome email couldn't be sent. Check the email settings, then set a password for them here or ask them to use \"Forgot your password?\".");
        }

        return back()->with('success', "Login created. {$user->email} has an email with a link to choose their password.");
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'role' => ['required', Rule::in(array_keys(config('hrm.roles')))],
            'is_active' => ['required', 'boolean'],
            'password' => ['nullable', Password::min(8)],
            ...$this->employeeRule($user),
        ], self::MESSAGES);

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

    private const MESSAGES = [
        'employee_id.required_if' => 'Pick the employee this login belongs to.',
        'employee_id.unique' => 'That employee already has a login.',
    ];

    /** Employee logins must belong to someone; other roles may (so HR staff get My page too). */
    private function employeeRule(?User $user): array
    {
        return ['employee_id' => ['nullable', 'required_if:role,employee', 'integer',
            Rule::exists('employees', 'id')->withoutTrashed(), Rule::unique('users', 'employee_id')->ignore($user?->id)]];
    }
}
