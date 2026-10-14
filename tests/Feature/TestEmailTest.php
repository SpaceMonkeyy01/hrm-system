<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ResendTransport;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Settings > Users > Send a test email, and the Resend mailer the live site uses (Render free blocks SMTP). */
class TestEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sends_a_test_email_to_themselves(): void
    {
        $admin = $this->user('admin', ['email' => 'boss@test.local']);
        config(['mail.from.address' => 'hr@company.test']);
        $this->actingAs($admin)->post('/settings/test-email')
            ->assertSessionHas('success', 'Test email sent to boss@test.local (from hr@company.test). Check the inbox and the spam folder.');

        $sent = Mail::mailer()->getSymfonyTransport()->messages();
        $this->assertCount(1, $sent);
        $this->assertSame('boss@test.local', $sent->first()->getEnvelope()->getRecipients()[0]->getAddress());
    }

    public function test_a_failure_shows_the_mail_error(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 2]);
        $this->actingAs($this->user('admin', ['email' => 'boss@test.local']))->post('/settings/test-email')
            ->assertSessionHas('error', fn ($m) => str_starts_with($m, "The test email to boss@test.local couldn't be sent: Connection could not be established"));
    }

    public function test_only_admins(): void
    {
        $this->actingAs($this->user('hr'))->post('/settings/test-email')->assertForbidden();
    }

    public function test_resend_mailer_is_available(): void
    {
        config(['services.resend.key' => 're_test_key']);
        $this->assertInstanceOf(ResendTransport::class, Mail::mailer('resend')->getSymfonyTransport());
    }
}
