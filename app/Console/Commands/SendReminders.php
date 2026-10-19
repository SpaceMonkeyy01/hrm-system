<?php

namespace App\Console\Commands;

use App\Mail\RemindersDigest;
use App\Models\User;
use App\Services\Import\MachineSyncer;
use App\Services\Reminders;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * php artisan hr:send-reminders [--dry-run]
 *
 * Emails one digest of today's reminders to every active login whose role is in
 * config hrm.reminders.recipient_roles (admin, hr). Viewers are never emailed. Nothing is sent
 * when there are no reminders. Scheduled daily in routes/console.php; needs the schedule:run cron.
 */
class SendReminders extends Command
{
    protected $signature = 'hr:send-reminders {--dry-run : Print the reminders and recipients instead of sending}';

    protected $description = 'Email today\'s HR reminders (probation, check-ins, documents, offboarding) to HR and admin logins';

    public function handle(): int
    {
        $today = CarbonImmutable::today();
        $reminders = Reminders::forDate($today);
        $backlog = Reminders::isBacklogDay($today) ? Reminders::documentsBacklog($today) : 0;
        $overdue = MachineSyncer::overdueDevices();
        $recipients = User::query()->where('is_active', true)
            ->whereIn('role', config('hrm.reminders.recipient_roles', ['admin', 'hr']))
            ->orderBy('id')->get(['id', 'name', 'email', 'role']);

        if ($reminders === [] && $backlog === 0 && $overdue === []) {
            $this->info("Nothing needs attention on {$today->toDateString()}. No email sent.");

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->table(['Type', 'Severity', 'Code', 'Name', 'Due', 'Days', 'Message'], array_map(fn ($r) => [
                $r['type'], $r['severity'], $r['employee_code'], $r['employee_name'], $r['due_date'], $r['days'], $r['message'],
            ], $reminders));
            if ($backlog) {
                $this->line("Weekly line: $backlog older employees still have missing documents.");
            }
            foreach ($overdue as $d) {
                $this->line("Sync overdue: {$d['name']}, last good sync ".($d['last_ok_at'] ?? 'never').'.');
            }
            $this->info(count($reminders).' reminders. Dry run: no email sent. Would send to: '
                .($recipients->isEmpty() ? 'nobody (no active HR or admin logins)' : $recipients->pluck('email')->implode(', ')));

            return self::SUCCESS;
        }

        if ($recipients->isEmpty()) {
            $this->warn('There are '.count($reminders).' reminders but no active HR or admin logins to send them to.');

            return self::SUCCESS;
        }

        foreach ($recipients as $user) {
            Mail::to($user->email, $user->name)->send(new RemindersDigest($today, $reminders, $backlog, $overdue));
        }
        $this->info('Sent '.count($reminders).' reminders to '.$recipients->count().' '.($recipients->count() === 1 ? 'person' : 'people').'.');

        return self::SUCCESS;
    }
}
