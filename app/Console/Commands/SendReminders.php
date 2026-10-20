<?php

namespace App\Console\Commands;

use App\Http\Controllers\RequestsInboxController;
use App\Mail\RemindersDigest;
use App\Models\Asset;
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
 * when there are no reminders and no requests waiting for HR. Scheduled daily in routes/console.php; needs the schedule:run cron.
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
        $warranties = Asset::query()->warrantyEnding($today->toDateString(), $today->addDays((int) config('hrm.assets.warranty_days', 30))->toDateString())
            ->orderBy('warranty_until')->get(['asset_tag', 'name', 'warranty_until'])
            ->map(fn ($a) => ['tag' => $a->asset_tag, 'name' => $a->name, 'until' => $a->warranty_until->toDateString()])->all();
        // What staff asked for on My page and HR hasn't answered yet (one line per kind).
        $waiting = array_values(array_filter(RequestsInboxController::queues(), fn ($q) => $q['pending'] > 0));
        $recipients = User::query()->where('is_active', true)
            ->whereIn('role', config('hrm.reminders.recipient_roles', ['admin', 'hr']))
            ->orderBy('id')->get(['id', 'name', 'email', 'role']);

        if ($reminders === [] && $backlog === 0 && $overdue === [] && $warranties === [] && $waiting === []) {
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
            foreach ($warranties as $w) {
                $this->line("Warranty ending: {$w['tag']} {$w['name']} on {$w['until']}.");
            }
            foreach ($waiting as $q) {
                $this->line("Waiting for HR: {$q['title']} {$q['pending']}.");
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
            Mail::to($user->email, $user->name)->send(new RemindersDigest($today, $reminders, $backlog, $overdue, $warranties, $waiting));
        }
        $this->info('Sent '.count($reminders).' reminders to '.$recipients->count().' '.($recipients->count() === 1 ? 'person' : 'people').'.');

        return self::SUCCESS;
    }
}
