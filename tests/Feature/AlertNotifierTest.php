<?php

namespace Tests\Feature;

use App\Models\CircuitAlert;
use App\Models\DeviceAlarm;
use App\Models\Circuit;
use App\Models\Device;
use App\Models\MaintenanceWindow;
use App\Models\NotificationChannel;
use App\Models\Site;
use App\Mail\AlertMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AlertNotifierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    public function test_an_email_channel_sends_the_professional_alert_mailable(): void
    {
        Mail::fake();
        $channel = NotificationChannel::factory()->create([
            'type' => 'email',
            'min_severity' => 'warning',
            'config' => ['email' => 'noc@example.test'],
        ]);
        $site = Site::factory()->create(['name' => 'Jacksonville']);
        $circuit = Circuit::factory()->create(['site_id' => $site->id]);

        CircuitAlert::create(['circuit_id' => $circuit->id, 'started_at' => now()]);

        Mail::assertSent(AlertMail::class, function (AlertMail $mail) use ($circuit) {
            return $mail->hasTo('noc@example.test')
                && $mail->event === 'open'
                && $mail->severity === 'critical'
                && str_contains($mail->description, 'Jacksonville');   // the alert description
        });
        $this->assertDatabaseHas('notification_logs', [
            'notification_channel_id' => $channel->id,
            'event' => 'open',
            'status' => 'sent',
        ]);
    }

    public function test_a_webhook_that_became_unsafe_is_blocked_at_send_time(): void
    {
        // Simulate DNS rebind / a stored-then-unsafe URL by creating the channel
        // directly with a loopback target (bypassing the save-time validator).
        NotificationChannel::factory()->create([
            'type' => 'webhook',
            'min_severity' => 'warning',
            'config' => ['url' => 'http://127.0.0.1/hook'],
        ]);
        $circuit = Circuit::factory()->create(['site_id' => Site::factory()->create()->id]);

        CircuitAlert::create(['circuit_id' => $circuit->id, 'started_at' => now()]);

        Http::assertNothingSent();   // send-time SSRF guard refused it
        $this->assertDatabaseHas('notification_logs', ['status' => 'failed']);
    }

    public function test_a_teams_channel_posts_a_messagecard(): void
    {
        NotificationChannel::factory()->create([
            'type' => 'teams',
            'min_severity' => 'warning',
            'config' => ['webhook_url' => 'https://contoso.webhook.office.com/webhookb2/abc'],
        ]);
        $circuit = Circuit::factory()->create(['site_id' => Site::factory()->create()->id]);

        CircuitAlert::create(['circuit_id' => $circuit->id, 'started_at' => now()]);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'webhook.office.com')
            && $r['@type'] === 'MessageCard'
            && $r['themeColor'] === 'D13438');   // critical
    }

    public function test_opening_an_alert_notifies_matching_channels(): void
    {
        $channel = NotificationChannel::factory()->create([
            'type' => 'webhook',
            'min_severity' => 'warning',
            'config' => ['url' => 'https://hooks.example.test/hook'],
        ]);
        $site = Site::factory()->create();
        $circuit = Circuit::factory()->create(['site_id' => $site->id]);

        CircuitAlert::create(['circuit_id' => $circuit->id, 'started_at' => now()]);

        Http::assertSent(fn ($r) => $r->url() === 'https://hooks.example.test/hook' && $r['event'] === 'open');
        $this->assertDatabaseHas('notification_logs', [
            'notification_channel_id' => $channel->id,
            'event' => 'open',
            'severity' => 'critical',
            'status' => 'sent',
        ]);
    }

    public function test_severity_below_channel_minimum_is_not_sent(): void
    {
        NotificationChannel::factory()->create(['min_severity' => 'critical']);
        $site = Site::factory()->create();
        $device = Device::factory()->create(['site_id' => $site->id]);

        // Device alarm is 'warning' — below the channel's 'critical' minimum.
        DeviceAlarm::create([
            'device_id' => $device->id,
            'alarm_id' => 'ALM-1',
            'description' => 'test',
            'first_seen_at' => now(),
        ]);

        Http::assertNothingSent();
        $this->assertDatabaseCount('notification_logs', 0);
    }

    public function test_resolving_an_alert_sends_a_recovery_notification(): void
    {
        NotificationChannel::factory()->create(['min_severity' => 'warning']);
        $site = Site::factory()->create();
        $circuit = Circuit::factory()->create(['site_id' => $site->id]);
        $alert = CircuitAlert::create(['circuit_id' => $circuit->id, 'started_at' => now()]);

        $alert->update(['ended_at' => now()]);

        $this->assertDatabaseHas('notification_logs', ['event' => 'resolved', 'status' => 'sent']);
    }

    public function test_active_maintenance_window_suppresses_notifications(): void
    {
        NotificationChannel::factory()->create(['min_severity' => 'warning']);
        MaintenanceWindow::factory()->create(); // global, active now

        $site = Site::factory()->create();
        $circuit = Circuit::factory()->create(['site_id' => $site->id]);
        CircuitAlert::create(['circuit_id' => $circuit->id, 'started_at' => now()]);

        Http::assertNothingSent();
        $this->assertDatabaseHas('notification_logs', ['status' => 'suppressed', 'event' => 'open']);
    }

    public function test_alert_created_already_resolved_does_not_notify(): void
    {
        NotificationChannel::factory()->create(['min_severity' => 'warning']);
        $site = Site::factory()->create();
        $device = Device::factory()->create(['site_id' => $site->id]);

        // Historical/seed alarm created already cleared.
        DeviceAlarm::create([
            'device_id' => $device->id,
            'alarm_id' => 'ALM-old',
            'description' => 'old',
            'first_seen_at' => now()->subDay(),
            'cleared_at' => now()->subHour(),
        ]);

        Http::assertNothingSent();
        $this->assertDatabaseCount('notification_logs', 0);
    }
}
