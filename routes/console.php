<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Daily reminder email to HR and admin logins. Needs the cron: * * * * * php artisan schedule:run
Schedule::command('hr:send-reminders')
    ->dailyAt(config('hrm.reminders.send_at', '08:00'))
    ->timezone(config('app.timezone'));
