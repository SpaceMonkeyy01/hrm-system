<?php

namespace App\Http\Controllers;

use App\Services\Import\MachineSyncer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * For hosts with no always-on scheduler (Render free sleeps when idle): a free cron service such as cron-job.org
 * opens /cron/reminders/{CRON_TOKEN} every morning, which also wakes the app. Without CRON_TOKEN set the URL
 * doesn't exist. The email goes once per day however often the URL is opened.
 */
class CronController extends Controller
{
    public function reminders(string $token): Response
    {
        $secret = (string) config('hrm.cron_token');
        abort_if($secret === '' || ! hash_equals($secret, $token), 404);

        $key = 'cron:reminders:'.now()->toDateString();
        if (! Cache::add($key, true, now()->endOfDay())) {
            return response('Already sent today.', 200, ['Content-Type' => 'text/plain']);
        }
        Artisan::call('hr:send-reminders');

        return response(trim(Artisan::output()) ?: 'Done.', 200, ['Content-Type' => 'text/plain']);
    }

    /** Opened every few minutes by the cron service: reads new punches from the attendance machine. */
    public function machine(string $token, MachineSyncer $syncer): Response
    {
        $secret = (string) config('hrm.cron_token');
        abort_if($secret === '' || ! hash_equals($secret, $token), 404);
        if (! MachineSyncer::configured()) {
            return response('No machine set up (ZK_HOST is empty).', 200, ['Content-Type' => 'text/plain']);
        }
        set_time_limit(180);
        $sync = $syncer->run('cron');

        // A failure answers 500 so the cron service shows it as failed and can email you.
        return response(MachineSyncer::summary($sync), $sync->ok ? 200 : 500, ['Content-Type' => 'text/plain']);
    }
}
