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

    /** Opened every few minutes by the cron service: reads new punches from each device with automatic sync on. */
    public function machine(string $token, MachineSyncer $syncer): Response
    {
        $secret = (string) config('hrm.cron_token');
        abort_if($secret === '' || ! hash_equals($secret, $token), 404);
        set_time_limit(180);
        $syncs = $syncer->runAll('cron');
        if (! $syncs) {
            return response('No attendance device to sync (Attendance > Devices).', 200, ['Content-Type' => 'text/plain']);
        }
        $failed = array_filter($syncs, fn ($s) => ! $s->ok);

        // Any failure answers 500 so the cron service shows it as failed and can email you.
        return response(MachineSyncer::summaries($syncs), $failed ? 500 : 200, ['Content-Type' => 'text/plain']);
    }
}
