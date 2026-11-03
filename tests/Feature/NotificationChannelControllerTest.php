<?php

namespace Tests\Feature;

use App\Models\NotificationChannel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NotificationChannelControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_a_webhook_channel_without_leaking_the_url(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->postJson('/api/notification-channels', [
            'name' => 'Ops Slack',
            'type' => 'slack',
            'min_severity' => 'warning',
            'config' => ['webhook_url' => 'https://hooks.slack.com/services/XXX'],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.destination', '••••••');
        $this->assertArrayNotHasKey('config', $response->json('data'));
        $this->assertDatabaseHas('notification_channels', ['name' => 'Ops Slack', 'type' => 'slack']);
    }

    public function test_config_is_encrypted_at_rest(): void
    {
        $channel = NotificationChannel::factory()->create(['config' => ['url' => 'https://secret.example/hook']]);

        $raw = \DB::table('notification_channels')->where('id', $channel->id)->value('config');
        $this->assertStringNotContainsString('secret.example', $raw);
        $this->assertSame('https://secret.example/hook', $channel->fresh()->config['url']);
    }

    public function test_email_channel_requires_a_valid_email(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->postJson('/api/notification-channels', [
            'name' => 'bad',
            'type' => 'email',
            'min_severity' => 'warning',
            'config' => ['email' => 'not-an-email'],
        ])->assertStatus(422);
    }

    public function test_webhook_url_to_metadata_or_loopback_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();

        foreach (['http://169.254.169.254/latest/meta-data', 'http://127.0.0.1:9000/x', 'http://[::1]/x'] as $bad) {
            $this->actingAs($admin)->postJson('/api/notification-channels', [
                'name' => 'ssrf',
                'type' => 'webhook',
                'min_severity' => 'warning',
                'config' => ['url' => $bad],
            ])->assertStatus(422);
        }
    }

    public function test_viewer_cannot_manage_channels(): void
    {
        $viewer = User::factory()->create();

        $this->actingAs($viewer)->getJson('/api/notification-channels')->assertForbidden();
        $this->actingAs($viewer)->postJson('/api/notification-channels', ['name' => 'x'])->assertForbidden();
    }

    public function test_test_endpoint_reports_delivery_status(): void
    {
        Http::fake();
        $admin = User::factory()->admin()->create();
        $channel = NotificationChannel::factory()->create(['config' => ['url' => 'https://hooks.example.test/t']]);

        $this->actingAs($admin)->postJson("/api/notification-channels/{$channel->id}/test")
            ->assertOk()
            ->assertJsonPath('status', 'sent');

        $this->assertDatabaseHas('notification_logs', ['notification_channel_id' => $channel->id, 'subject' => 'Nodus test notification']);
    }
}
