<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\SetPasswordNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Creates an "employee" login for every current employee with a work email and no login yet, and emails each
 * one a link to choose their own password (nobody else ever knows it). Admin only.
 */
class StaffLoginController extends Controller
{
    /** Current employees who could get a login now, and how many can't for want of a work email. */
    public static function candidates(): array
    {
        $current = Employee::query()->whereIn('status', ['active', 'offboarding'])->get(['id', 'full_name', 'email']);
        $linked = User::whereNotNull('employee_id')->pluck('employee_id')->flip();
        $emails = User::pluck('email')->map(fn ($e) => Str::lower($e))->flip();
        $open = $current->reject(fn ($e) => $linked->has($e->id));

        return [
            'ready' => $open->filter(fn ($e) => $e->email && ! $emails->has(Str::lower($e->email)))->values(),
            'noEmail' => $open->whereNull('email')->count(),
            'emailTaken' => $open->filter(fn ($e) => $e->email && $emails->has(Str::lower($e->email)))->count(),
        ];
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['send' => ['boolean']]);
        /** @var Collection<int, Employee> $ready */
        $ready = self::candidates()['ready'];
        if ($ready->isEmpty()) {
            return back()->with('error', 'Nobody to create a login for: everyone with a work email already has one.');
        }
        $users = DB::transaction(fn () => $ready->map(fn (Employee $e) => User::create([
            'name' => $e->full_name, 'email' => Str::lower($e->email), 'role' => 'employee', 'employee_id' => $e->id, 'is_active' => true,
            'password' => Str::random(40),   // unknown to everyone; the person chooses their own from the email link
        ])));
        $sent = 0;
        if ($request->boolean('send', true)) {
            foreach ($users as $u) {
                $u->notify(new SetPasswordNotification(Password::broker('invites')->createToken($u), invite: true));
                $sent++;
            }
        }

        return back()->with('success', 'Created '.$users->count().' staff '.Str::plural('login', $users->count()).'.'
            .($sent ? " Each person has an email with a link to choose their password." : ' No emails were sent: use "Forgot your password?" or set a password here.'));
    }
}
