<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Settings > Users > Send a test email (admin): sends one short email to the admin's own address with the live
 * mail settings, and shows the mail service's own error when it fails (wrong API key, sender domain not verified).
 */
class TestEmailController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $to = $request->user()->email;
        try {
            Mail::raw("This is a test email from the HRM System.\n\nIf you can read this, welcome emails, password links and reminders can be sent.", function ($m) use ($to) {
                $m->to($to)->subject('HRM System test email');
            });
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', "The test email to $to couldn't be sent: ".mb_substr($e->getMessage(), 0, 300));
        }

        return back()->with('success', "Test email sent to $to (from ".config('mail.from.address').'). Check the inbox and the spam folder.');
    }
}
