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
     */
    public function __construct(public CarbonInterface $today, public array $reminders, public int $backlog = 0) {}

    public function envelope(): Envelope
    {
        $n = count($this->reminders);

        $what = $n === 0 ? 'older missing documents' : "$n ".($n === 1 ? 'item' : 'items');

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
            'dashboard' => url('/dashboard'),
        ]);
    }
}
