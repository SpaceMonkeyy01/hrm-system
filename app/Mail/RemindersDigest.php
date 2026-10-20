<?php

namespace App\Mail;

use App\Services\Reminders;
use Carbon\CarbonInterface;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The daily email to HR and admin logins: every reminder for the day, grouped by type.
 * Sent synchronously by php artisan hr:send-reminders (no queue).
 */
class RemindersDigest extends Mailable
{
    /**
     * @param  list<array<string, mixed>>  $reminders  from Reminders::forDate()
     * @param  int  $backlog  from Reminders::documentsBacklog(), only on the weekly backlog day (0 = leave the line out)
     * @param  list<array{name: string, code: ?string, last_ok_at: ?string}>  $overdue  MachineSyncer::overdueDevices()
     * @param  list<array{tag: string, name: string, until: string}>  $warranties  assets whose warranty ends soon
     * @param  list<array{title: string, href: string, pending: int, oldest: ?string}>  $waitingRequests  RequestsInboxController::queues() with some pending
     */
    public function __construct(public CarbonInterface $today, public array $reminders, public int $backlog = 0, public array $overdue = [], public array $warranties = [],
        public array $waitingRequests = []) {}

    public function envelope(): Envelope
    {
        $n = count($this->reminders);

        $what = match (true) {
            $n > 0 => "$n ".($n === 1 ? 'item' : 'items'),
            $this->overdue !== [] => 'attendance machine not syncing',
            $this->waitingRequests !== [] => array_sum(array_column($this->waitingRequests, 'pending')).' '.(array_sum(array_column($this->waitingRequests, 'pending')) === 1 ? 'request' : 'requests').' waiting',
            $this->warranties !== [] => count($this->warranties).' '.(count($this->warranties) === 1 ? 'warranty' : 'warranties').' ending',
            default => 'older missing documents',
        };

        return new Envelope(subject: 'HR reminders for '.$this->today->format('j M Y').": $what");
    }

    public function content(): Content
    {
        $groups = [];
        foreach (Reminders::TYPES as $type => $heading) {
            $rows = array_values(array_filter($this->reminders, fn ($r) => $r['type'] === $type));
            if ($rows) {
                $groups[] = ['heading' => $heading, 'rows' => $rows];
            }
        }

        return new Content(markdown: 'mail.reminders', with: [
            'date' => $this->today->format('j M Y'),
            'count' => count($this->reminders),
            'groups' => $groups,
            'backlog' => $this->backlog,
            'backlogLink' => url('/documents?status=Pending'),
            'stalled' => array_map(fn ($d) => $d + ['since' => $d['last_ok_at'] ? \Carbon\CarbonImmutable::parse($d['last_ok_at'])->timezone(config('app.timezone'))->format('j M Y H:i') : null], $this->overdue),
            'devicesLink' => url('/attendance/devices'),
            'ending' => array_map(fn ($w) => $w + ['date' => \Carbon\CarbonImmutable::parse($w['until'])->format('j M Y')], $this->warranties),
            'assetsLink' => url('/assets?warranty=ending'),
            // Not "waitingRequests": a Mailable's public properties override view data of the same name.
            'waiting' => array_map(fn ($q) => $q + ['link' => url($q['href']), 'since' => $q['oldest'] ? \Carbon\CarbonImmutable::parse($q['oldest'])->timezone(config('app.timezone'))->format('j M') : null],
                $this->waitingRequests),
            'inboxLink' => url('/requests'),
            'dashboard' => url('/dashboard'),
        ]);
    }
}
