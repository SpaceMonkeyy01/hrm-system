<?php

namespace App\Notifications;

use App\Models\Company;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A short email to a member of staff that something is waiting on their My page (StaffMail). It says what kind of
 * thing and links there; the letter, warning or notes themselves never go in the email. Sent from their company's HR
 * address when one is set (Settings > Departments and teams).
 */
class StaffNotice extends Notification
{
    /** @param  list<string>  $lines */
    public function __construct(public string $subject, public array $lines, public string $path, public string $button = 'Open My page') {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->subject)->greeting("Hello {$notifiable->name},");
        foreach ($this->lines as $line) {
            $mail->line($line);
        }
        $mail->action($this->button, url($this->path))
            ->line('You get this email because you have a login on '.config('app.name').'.');
        if ($from = Company::mailFromFor($notifiable)) {
            $mail->from(...$from);
        }

        return $mail;
    }
}
