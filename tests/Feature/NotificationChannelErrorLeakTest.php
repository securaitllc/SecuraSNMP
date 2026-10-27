<?php

namespace Tests\Feature;

use App\Models\MailSetting;
use App\Models\NotificationChannel;
use App\Models\NotificationLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * SSRF-01: the channel test endpoint used to hand back the HTTP client's exception
 * message, which quotes the target's response body. Pointed at an internal service
 * that turned a blind probe into a read primitive. Only a fixed failure class and
 * the status code may come back — in the API reply and in notification_logs.
 */
class NotificationChannelErrorLeakTest extends TestCase
{
    use RefreshDatabase;

    private function webhook(): NotificationChannel
    {
        return NotificationChannel::factory()->create([
            'type' => 'webhook',
            'min_severity' => 'critical',
            'enabled' => false,
            'config' => ['url' => 'http://10.20.30.40:18083/internal-admin?token=abc'],
        ]);
    }

    /** SSRF-01: a non-2xx body never reaches the reply or the log. */
    public function test_a_failed_webhook_test_returns_only_the_status_code(): void
    {
        Http::fake(['*' => Http::response("INTERNAL-SECRET-BANNER-v6\n", 418)]);
        $channel = $this->webhook();

        $res = $this->actingAs(User::factory()->admin()->create())
            ->postJson("/api/notification-channels/{$channel->id}/test")
            ->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('error', 'delivery failed (HTTP 418)');

        $this->assertStringNotContainsString('INTERNAL-SECRET', (string) $res->json('error'));
        $this->assertSame('delivery failed (HTTP 418)', NotificationLog::latest('id')->value('error'));
    }

    /** SSRF-01: a refused/timed-out connection says so without quoting the target. */
    public function test_a_connection_failure_returns_a_fixed_string(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect to 10.20.30.40 port 18083: Connection refused'));
        $channel = $this->webhook();

        $this->actingAs(User::factory()->admin()->create())
            ->postJson("/api/notification-channels/{$channel->id}/test")
            ->assertJsonPath('error', 'connection failed');

        $this->assertStringNotContainsString('10.20.30.40', (string) NotificationLog::latest('id')->value('error'));
    }

    /** SSRF-01: a Slack destination answering with a body is held to the same rule. */
    public function test_a_slack_failure_does_not_echo_the_response_body(): void
    {
        Http::fake(['*' => Http::response('no_service: internal detail', 404)]);
        $channel = NotificationChannel::factory()->create([
            'type' => 'slack',
            'config' => ['webhook_url' => 'https://10.20.30.40/services/T0/B0/x'],
        ]);

        $this->actingAs(User::factory()->admin()->create())
            ->postJson("/api/notification-channels/{$channel->id}/test")
            ->assertJsonPath('error', 'delivery failed (HTTP 404)');
    }

    /** SSRF-01: an SMTP transport error (banner, host, port) is not echoed either. */
    public function test_an_email_failure_returns_a_fixed_string(): void
    {
        // Port 1 on loopback refuses at once: a real transport failure, no network needed.
        MailSetting::current()->update(['host' => '127.0.0.1', 'port' => 1, 'encryption' => 'none', 'enabled' => true]);
        $channel = NotificationChannel::factory()->create([
            'type' => 'email',
            'config' => ['email' => 'noc@example.test'],
        ]);

        $res = $this->actingAs(User::factory()->admin()->create())
            ->postJson("/api/notification-channels/{$channel->id}/test")
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('error', 'mail delivery failed');

        $this->assertStringNotContainsString('127.0.0.1', (string) $res->json('error'));
    }

    /** Guard against the fake shape drifting: a 2xx still records as sent. */
    public function test_a_successful_send_is_still_recorded_as_sent(): void
    {
        Http::fake(['*' => Http::response('', 204)]);
        $channel = $this->webhook();

        $this->actingAs(User::factory()->admin()->create())
            ->postJson("/api/notification-channels/{$channel->id}/test")
            ->assertJsonPath('status', 'sent')
            ->assertJsonPath('error', null);
    }
}
