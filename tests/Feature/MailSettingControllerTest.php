<?php

namespace Tests\Feature;

use App\Mail\AlertMail;
use App\Models\MailSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MailSettingControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_save_settings_and_password_is_never_returned(): void
    {
        $user = User::factory()->admin()->create();

        $res = $this->actingAs($user)->putJson('/api/mail-settings', [
            'host' => 'smtp.office365.com',
            'port' => 587,
            'encryption' => 'tls',
            'username' => 'alerts@contoso.com',
            'password' => 'secret-app-password',
            'from_address' => 'alerts@contoso.com',
            'from_name' => 'Nodus Alerts',
            'enabled' => true,
        ]);

        $res->assertOk()
            ->assertJsonPath('host', 'smtp.office365.com')
            ->assertJsonPath('has_password', true)
            ->assertJsonMissingPath('password');

        // Password is encrypted at rest, not stored plaintext.
        $this->assertNotSame('secret-app-password', MailSetting::current()->getRawOriginal('password'));
        $this->assertSame('secret-app-password', MailSetting::current()->password);
    }

    public function test_a_blank_password_keeps_the_existing_one(): void
    {
        $user = User::factory()->admin()->create();
        MailSetting::current()->update(['password' => 'original']);

        $this->actingAs($user)->putJson('/api/mail-settings', [
            'port' => 587, 'encryption' => 'tls', 'password' => '',
        ])->assertOk();

        $this->assertSame('original', MailSetting::current()->password);
    }

    public function test_test_endpoint_sends_the_alert_mailable(): void
    {
        Mail::fake();
        $user = User::factory()->admin()->create();

        $this->actingAs($user)->postJson('/api/mail-settings/test', ['to' => 'noc@example.test'])
            ->assertOk()->assertJsonPath('ok', true);

        Mail::assertSent(AlertMail::class, fn (AlertMail $m) => $m->hasTo('noc@example.test'));
    }

    public function test_viewer_cannot_change_mail_settings(): void
    {
        $user = User::factory()->create();   // non-admin
        $this->actingAs($user)->putJson('/api/mail-settings', ['port' => 25, 'encryption' => 'none'])
            ->assertForbidden();
    }
}
