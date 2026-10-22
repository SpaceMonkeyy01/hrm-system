<?php

namespace App\Console\Commands;

use App\Http\Controllers\RequestsInboxController;
use App\Mail\RemindersDigest;
use App\Models\Asset;
use App\Models\Company;
use App\Models\Employee;
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
 * config hrm.reminders.recipient_roles (admin, hr). Viewers are never emailed. A login with a home company
 * (users.company_id) gets only that company's people, warranties and waiting requests; one without gets both
 * companies. A sync overdue on the attendance machine goes to everyone. Nobody is emailed a digest with nothing
 * in it. Scheduled daily in routes/console.php; needs the schedule:run cron.
 */
class SendReminders extends Command
{
    protected $signature = 'hr:send-reminders {--dry-run : Print the reminders and recipients instead of sending}';

    protected $description = 'Email today\'s HR reminders (probation, check-ins, documents, offboarding) to HR and admin logins';

    public function handle(): int
    {
        $today = CarbonImmutable::today();
        $all = Reminders::forDate($today);
        $overdue = MachineSyncer::overdueDevices();
        $recipients = User::query()->where('is_active', true)
            ->whereIn('role', config('hrm.reminders.recipient_roles', ['admin', 'hr']))
            ->orderBy('id')->get(['id', 'name', 'email', 'role', 'company_id']);
        $companyOf = Employee::withTrashed()->pluck('company_id', 'id');
        $names = Company::query()->pluck('name', 'id');

        // One digest per home company (null = both), built once and sent to everyone with that home company.
        $digests = [];
        foreach ($recipients->pluck('company_id')->unique()->values() as $company) {
            $digests[$company ?? 'all'] = [
                'label' => $company ? ($names[$company] ?? "company $company") : 'both companies',
                'reminders' => $company ? array_values(array_filter($all, fn ($r) => ($companyOf[$r['employee_id']] ?? null) === $company)) : $all,
                'backlog' => Reminders::isBacklogDay($today) ? Reminders::documentsBacklog($today, $company) : 0,
                'warranties' => Asset::query()->warrantyEnding($today->toDateString(), $today->addDays((int) config('hrm.assets.warranty_days', 30))->toDateString())
                    // Assets with no company set go to everyone, so nothing is missed.
                    ->when($company, fn ($q) => $q->where(fn ($q) => $q->where('company_id', $company)->orWhereNull('company_id')))
                    ->orderBy('warranty_until')->get(['asset_tag', 'name', 'warranty_until'])
                    ->map(fn ($a) => ['tag' => $a->asset_tag, 'name' => $a->name, 'until' => $a->warranty_until->toDateString()])->all(),
                // What staff asked for on My page and HR hasn't answered yet (one line per kind).
                'waiting' => array_values(array_filter(RequestsInboxController::queues($company), fn ($q) => $q['pending'] > 0)),
            ];
        }
        $empty = fn (array $d) => $d['reminders'] === [] && $d['backlog'] === 0 && $overdue === [] && $d['warranties'] === [] && $d['waiting'] === [];

        if ($recipients->isEmpty()) {
            $this->warn('There are '.count($all).' reminders but no active HR or admin logins to send them to.');

            return self::SUCCESS;
        }
        if (array_filter($digests, fn ($d) => ! $empty($d)) === []) {
            $this->info("Nothing needs attention on {$today->toDateString()}. No email sent.");

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            foreach ($digests as $key => $d) {
                $to = $recipients->filter(fn ($u) => ($u->company_id ?? 'all') === $key)->pluck('email')->implode(', ');
                $this->line("== For {$d['label']} ($to)".($empty($d) ? ': nothing, no email' : ''));
                if ($empty($d)) {
                    continue;
                }
                $this->table(['Type', 'Severity', 'Code', 'Name', 'Due', 'Days', 'Message'], array_map(fn ($r) => [
                    $r['type'], $r['severity'], $r['employee_code'], $r['employee_name'], $r['due_date'], $r['days'], $r['message'],
                ], $d['reminders']));
                if ($d['backlog']) {
                    $this->line("Weekly line: {$d['backlog']} older employees still have missing documents.");
                }
                foreach ($d['warranties'] as $w) {
                    $this->line("Warranty ending: {$w['tag']} {$w['name']} on {$w['until']}.");
                }
                foreach ($d['waiting'] as $q) {
                    $this->line("Waiting for HR: {$q['title']} {$q['pending']}.");
                }
            }
            foreach ($overdue as $dev) {
                $this->line("Sync overdue (everyone): {$dev['name']}, last good sync ".($dev['last_ok_at'] ?? 'never').'.');
            }
            $this->info('Dry run: no email sent.');

            return self::SUCCESS;
        }

        $sent = 0;
        foreach ($recipients as $user) {
            $d = $digests[$user->company_id ?? 'all'];
            if ($empty($d)) {
                continue;
            }
            Mail::to($user->email, $user->name)->send(new RemindersDigest($today, $d['reminders'], $d['backlog'], $overdue, $d['warranties'], $d['waiting']));
            $sent++;
        }
        $this->info('Sent the digest to '.$sent.' '.($sent === 1 ? 'person' : 'people').' ('.count($all).' reminders in all).');

        return self::SUCCESS;
    }
}
