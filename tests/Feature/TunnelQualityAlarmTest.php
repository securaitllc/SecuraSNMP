<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceAlarm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A latency threshold breach on a tunnel is not a tunnel being down.
 *
 * The appliance raises both against the same 'ec:<typeId>:to_<peer>' shape, so the
 * alarm id cannot distinguish them — only the description can. The dashboard used to
 * label every one of them "Tunnel down" and force it to critical, overriding the
 * warning the poller had correctly stored.
 *
 * The effect on the reference fleet was total: all 131 open alarms were threshold
 * breaches, every one stored as a warning, every one displayed as a critical tunnel
 * outage and counted in tunnels_down. A real outage would have been invisible in
 * that list.
 */
class TunnelQualityAlarmTest extends TestCase
{
    use RefreshDatabase;

    private const LATENCY = 'The second-sampled average latency exceeds the threshold of 1000ms for the last 5 minute-samples';

    private function alarm(string $source, string $description, string $severity): DeviceAlarm
    {
        return DeviceAlarm::create([
            'device_id' => Device::factory()->create()->id,
            'alarm_id' => "ec:327686:{$source}",
            'description' => $description,
            'severity' => $severity,
            'first_seen_at' => now(),
            'active_on_device' => true,
        ]);
    }

    private function alerts(): array
    {
        $response = $this->actingAs(User::factory()->create())->getJson('/api/dashboard');
        $response->assertOk();

        return $response->json('alerts') ?? [];
    }

    public function test_a_latency_breach_is_not_labelled_tunnel_down(): void
    {
        $this->alarm('to_AZURE-PRI_BulkApps', self::LATENCY, 'warning');

        $alert = collect($this->alerts())->firstWhere('type', 'tunnel-quality');

        $this->assertNotNull($alert, 'A threshold breach should be its own category.');
        $this->assertSame('Link quality threshold', $alert['subtitle']);
        $this->assertStringNotContainsStringIgnoringCase('tunnel down', $alert['subtitle']);
    }

    public function test_a_latency_breach_keeps_the_severity_the_poller_stored(): void
    {
        $this->alarm('to_AZURE-PRI_BulkApps', self::LATENCY, 'warning');

        $alert = collect($this->alerts())->firstWhere('type', 'tunnel-quality');

        // Hardcoding critical here is what turned a correctly-classified warning
        // into a false outage.
        $this->assertSame('warning', $alert['severity']);
    }

    public function test_a_real_tunnel_outage_is_still_critical_and_still_says_so(): void
    {
        $this->alarm('to_FL0001-HQ_Broadband1', 'Tunnel state is Down', 'critical');

        $alert = collect($this->alerts())->firstWhere('type', 'tunnel');

        $this->assertNotNull($alert, 'A genuine outage must remain in the tunnel category.');
        $this->assertSame('Tunnel down', $alert['subtitle']);
        $this->assertSame('critical', $alert['severity']);
    }

    public function test_quality_breaches_do_not_inflate_the_tunnels_down_kpi(): void
    {
        foreach (range(1, 5) as $i) {
            $this->alarm("to_PEER{$i}_BulkApps", self::LATENCY, 'warning');
        }
        $this->alarm('to_FL0001-HQ_Broadband1', 'Tunnel state is Down', 'critical');

        $response = $this->actingAs(User::factory()->create())->getJson('/api/dashboard');

        // One real outage, five degraded — not six outages.
        $response->assertJsonPath('counts.tunnels_down', 1);
        $response->assertJsonPath('counts.tunnels_degraded', 5);
    }

    public function test_an_analyst_can_clear_a_flood_in_one_action(): void
    {
        $ids = collect(range(1, 40))
            ->map(fn ($i) => $this->alarm("to_PEER{$i}_BulkApps", self::LATENCY, 'warning')->id)
            ->all();

        $response = $this->actingAs(User::factory()->analyst()->create())
            ->postJson('/api/alarms/bulk-clear', ['ids' => $ids, 'note' => 'Orchestrator latency event']);

        $response->assertOk();
        $response->assertJsonPath('cleared', 40);
        $this->assertSame(0, DeviceAlarm::whereNull('cleared_at')->count());
        $this->assertSame('Orchestrator latency event', DeviceAlarm::first()->clear_note);
    }

    public function test_bulk_clear_only_touches_the_ids_it_was_given(): void
    {
        $target = $this->alarm('to_PEER1_BulkApps', self::LATENCY, 'warning');
        $outage = $this->alarm('to_FL0001-HQ_Broadband1', 'Tunnel state is Down', 'critical');

        $this->actingAs(User::factory()->analyst()->create())
            ->postJson('/api/alarms/bulk-clear', ['ids' => [$target->id]])
            ->assertOk();

        // Clearing a flood must never sweep up the outage sitting next to it.
        $this->assertNotNull($target->fresh()->cleared_at);
        $this->assertNull($outage->fresh()->cleared_at);
    }

    public function test_a_viewer_cannot_bulk_clear(): void
    {
        $alarm = $this->alarm('to_PEER1_BulkApps', self::LATENCY, 'warning');

        $this->actingAs(User::factory()->create())
            ->postJson('/api/alarms/bulk-clear', ['ids' => [$alarm->id]])
            ->assertForbidden();
    }
}
