<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Settings\StaffLoginController;
use App\Http\Controllers\Settings\UserController;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\SetPasswordNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HR's side of logins: staff ("employee" role, My page only) logins and nothing else. HR creates them from the work
 * email on file, emails the password link, and switches them off or on. HR, viewer and admin logins stay with admins
 * (Settings > Users), so HR can't hand anyone more access than My page.
 */
class StaffLoginsController extends Controller
{
    public function index(): Response
    {
        $logins = User::query()->where('role', 'employee')->with('employee:id,full_name,employee_code,status')->orderBy('name')
            ->get(['id', 'name', 'email', 'is_active', 'last_login_at', 'employee_id', 'created_at']);
        $linked = User::whereNotNull('employee_id')->pluck('employee_id')->flip();
        $emails = User::pluck('email')->map(fn ($e) => Str::lower($e))->flip();

        return Inertia::render('logins/index', [
            'logins' => $logins->map(fn (User $u) => [...$u->only('id', 'name', 'email', 'is_active'),
                'employee' => $u->employee ? ['id' => $u->employee->id, 'name' => $u->employee->full_name, 'code' => $u->employee->employee_code, 'status' => $u->employee->status] : null,
                'last_login_at' => $u->last_login_at?->toIso8601String(), 'created_at' => $u->created_at?->toIso8601String()]),
            // Current staff with no login yet, and why one can't be made for some of them.
            'without' => Employee::query()->whereIn('status', ['active', 'offboarding'])->orderBy('full_name')->get(['id', 'full_name', 'employee_code', 'email'])
                ->reject(fn ($e) => $linked->has($e->id))
                ->map(fn ($e) => ['id' => $e->id, 'name' => $e->full_name, 'code' => $e->employee_code, 'email' => $e->email,
                    'problem' => ! $e->email ? 'no_email' : ($emails->has(Str::lower($e->email)) ? 'email_taken' : null)])
                ->values(),
        ]);
    }

    /** One person's login, from the work email on their record, with the welcome email. */
    public function store(Employee $employee): RedirectResponse
    {
        if (! in_array($employee->status, ['active', 'offboarding'], true)) {
            throw ValidationException::withMessages(['login' => "{$employee->full_name} isn't current staff, so they can't have a login."]);
        }
        if (User::where('employee_id', $employee->id)->exists()) {
            throw ValidationException::withMessages(['login' => "{$employee->full_name} already has a login."]);
        }
        $email = Str::lower(trim((string) $employee->email));
        if ($email === '') {
            throw ValidationException::withMessages(['login' => "Add {$employee->full_name}'s work email first (Edit)."]);
        }
        if (User::whereRaw('lower(email) = ?', [$email])->exists()) {
            throw ValidationException::withMessages(['login' => "$email is already used by another login. Ask an admin to sort it out in Settings > Users."]);
        }

        $user = User::create(['name' => $employee->full_name, 'email' => $email, 'role' => 'employee', 'employee_id' => $employee->id,
            'is_active' => true, 'password' => Str::random(40)]);   // unknown to everyone; they choose their own from the link
        try {
            $user->notify(new SetPasswordNotification(Password::broker('invites')->createToken($user), invite: true));
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', "Login created for $email, but the welcome email couldn't be sent. Try \"Send the link again\" later.");
        }

        return back()->with('success', "Login created. $email has an email with a link to choose their password.");
    }

    /** Everyone current with a work email and no login (the same as the button on Settings > Users). */
    public function storeAll(Request $request): RedirectResponse
    {
        return app(StaffLoginController::class)->store($request);
    }

    public function sendLink(User $user): RedirectResponse
    {
        $this->staffOnly($user);

        return UserController::mailLink($user);
    }

    public function setActive(Request $request, User $user): RedirectResponse
    {
        $this->staffOnly($user);
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $user->update(['is_active' => $data['is_active']]);

        return back()->with('success', $data['is_active'] ? "{$user->email} can log in again." : "{$user->email} is switched off and can't log in.");
    }

    private function staffOnly(User $user): void
    {
        abort_unless($user->role === 'employee', 403, 'Only an admin can change HR, viewer and admin logins.');
    }
}
