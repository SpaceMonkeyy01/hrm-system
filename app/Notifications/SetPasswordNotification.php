<?php

namespace App\Notifications;

use App\Models\Company;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Link to choose a password: for a new login HR created ($invite) or for "Forgot your password?".
 * The link carries the token; the password itself is never emailed.
 */
class SetPasswordNotification extends Notification
{
    public function __construct(public string $token, public bool $invite = false) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = url(route('password.reset', ['token' => $this->token, 'email' => $notifiable->getEmailForPasswordReset()] + ($this->invite ? ['invite' => 1] : []), false));
        $hours = (int) round(config('auth.passwords.'.($this->invite ? 'invites' : 'users').'.expire') / 60);
        $app = config('app.name');
        $mail = $this->invite
            ? (new MailMessage)->subject("Your $app login")
                ->greeting("Hello {$notifiable->name},")
                ->line("HR has set up a login for you on $app. ".($notifiable->role === 'employee'
                    ? 'You can see your attendance, leave and reviews, and keep your contact details up to date.'
                    : 'You can use it to work with HR records.'))
                ->action('Choose your password', $url)
                ->line("The link works for $hours hours. If it has run out, use \"Forgot your password?\" on the login page.")
            : (new MailMessage)->subject("Reset your $app password")
                ->line('Someone asked to reset the password for this login.')
                ->action('Choose a new password', $url)
                ->line("The link works for $hours ".($hours === 1 ? 'hour' : 'hours').'. If you didn\'t ask for this, ignore this email: your password stays the same.');

        // Staff hear from their own company's HR address (Settings > Departments and teams); others from the default.
        if ($from = Company::mailFromFor($notifiable)) {
            $mail->from(...$from);
        }

        return $mail;
    }
}
