<?php

namespace Tests\Feature;

use App\Models\MailSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SSRF-05: moving the SMTP host kept the stored password, so an admin could point
 * the server at their own listener and have it AUTH with the org's credential; the
 * test send then echoed the raw transport error (banner, host, port) back.
 */
class MailSettingSsrfTest extends TestCase
{
    use RefreshDatabase;

    private function seeded(): void
    {
        MailSetting::current()->update([
            'host' => 'smtp.office365.com', 'port' => 587, 'encryption' => 'tls',
            'username' => 'alerts@contoso.com', 'password' => 'org-secret', 'enabled' => true,
        ]);
    }

    /** SSRF-05: a new host without a new password drops the stored one. */
    public function test_changing_the_host_without_a_password_clears_it(): void
    {
        $this->seeded();

        $this->actingAs(User::factory()->admin()->create())->putJson('/api/mail-settings', [
            'host' => 'attacker.example', 'port' => 587, 'encryption' => 'tls', 'password' => '',
        ])->assertOk()->assertJsonPath('has_password', false);

        $this->assertNull(MailSetting::current()->password);
    }

    /** SSRF-05: a new port is a new destination too. */
    public function test_changing_the_port_without_a_password_clears_it(): void
    {
        $this->seeded();

        $this->actingAs(User::factory()->admin()->create())->putJson('/api/mail-settings', [
            'host' => 'smtp.office365.com', 'port' => 25, 'encryption' => 'none',
        ])->assertOk();

        $this->assertNull(MailSetting::current()->password);
    }

    /** SSRF-05: a new host WITH a password keeps the one just supplied. */
    public function test_changing_the_host_with_a_password_keeps_the_new_one(): void
    {
        $this->seeded();

        $this->actingAs(User::factory()->admin()->create())->putJson('/api/mail-settings', [
            'host' => 'smtp.example.net', 'port' => 587, 'encryption' => 'tls', 'password' => 'new-secret',
        ])->assertOk();

        $this->assertSame('new-secret', MailSetting::current()->password);
    }

    /** SSRF-05: same destination, blank password still means "keep it". */
    public function test_same_destination_with_blank_password_keeps_it(): void
    {
        $this->seeded();

        $this->actingAs(User::factory()->admin()->create())->putJson('/api/mail-settings', [
            'host' => 'smtp.office365.com', 'port' => 587, 'encryption' => 'tls', 'from_name' => 'NOC',
        ])->assertOk();

        $this->assertSame('org-secret', MailSetting::current()->password);
    }

    /** SSRF-05: the test send never echoes the transport exception. */
    public function test_a_failed_test_send_returns_a_generic_error(): void
    {
        // Loopback port 1 refuses at once: a real transport failure, no network needed.
        MailSetting::current()->update(['host' => '127.0.0.1', 'port' => 1, 'encryption' => 'none', 'enabled' => true]);

        $res = $this->actingAs(User::factory()->admin()->create())
            ->postJson('/api/mail-settings/test', ['to' => 'noc@example.test'])
            ->assertStatus(422)
            ->assertJsonPath('ok', false);

        $error = (string) $res->json('error');
        $this->assertStringNotContainsString('127.0.0.1', $error);
        $this->assertStringNotContainsString('Connection', $error);
        $this->assertSame('Could not send the test email — check the SMTP host, port, encryption and credentials.', $error);
    }
}
