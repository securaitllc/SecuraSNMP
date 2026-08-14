<?php

namespace Tests\Unit;

use App\Models\Circuit;
use App\Models\CircuitAlert;
use App\Models\CircuitMetricHistory;
use App\Models\Device;
use App\Models\DeviceNextHop;
use App\Models\Site;
use App\Services\CircuitMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CircuitMonitorTest extends TestCase
{
    use RefreshDatabase;

    public function test_appliance_reachable_nexthop_overrides_our_failed_gateway_ping(): void
    {
        // The #113 case: our direct ICMP to the public gateway fails, but the appliance
        // reports wan0 reachable — the circuit is up, not down.
        $site = Site::factory()->create();
        $edge = Device::factory()->create(['site_id' => $site->id, 'role' => 'edgeconnect']);
        DeviceNextHop::create(['device_id' => $edge->id, 'ip_address' => '108.188.128.1', 'interface' => 'wan0', 'status' => 'up']);
        $circuit = Circuit::factory()->create(['site_id' => $site->id, 'wan_interface' => 'wan0', 'gateway_ip' => '108.188.128.1', 'monitored_ip' => '108.188.128.1', 'status' => 'up', 'monitor_via' => 'icmp']);

        (new CircuitMonitor(fn (string $ip) => ['loss' => 100, 'rtt' => null]))->check($circuit->fresh());

        $this->assertSame('up', $circuit->fresh()->status);
        $this->assertSame(0, CircuitAlert::whereNull('ended_at')->count(), 'no false circuit-down alert');
    }

    public function test_circuit_is_down_when_our_ping_and_the_appliance_nexthop_both_fail(): void
    {
        $site = Site::factory()->create();
        $edge = Device::factory()->create(['site_id' => $site->id, 'role' => 'edgeconnect']);
        DeviceNextHop::create(['device_id' => $edge->id, 'ip_address' => '108.188.128.1', 'interface' => 'wan0', 'status' => 'down']);
        $circuit = Circuit::factory()->create(['site_id' => $site->id, 'wan_interface' => 'wan0', 'gateway_ip' => '108.188.128.1', 'monitored_ip' => '108.188.128.1', 'status' => 'up', 'monitor_via' => 'icmp']);

        (new CircuitMonitor(fn (string $ip) => ['loss' => 100, 'rtt' => null]))->check($circuit->fresh());

        $this->assertSame('down', $circuit->fresh()->status);
        $this->assertSame(1, CircuitAlert::whereNull('ended_at')->count());
    }

    public function test_checkall_beats_once_per_circuit_so_a_long_sweep_stays_alive(): void
    {
        // Regression: the hang watchdog once killed a slow full sweep before it
        // finished, starving every circuit late in the ordering (e.g. 243). The
        // sweep must emit a progress beat per circuit, not only when it completes.
        Circuit::factory()->count(3)->create(['monitoring_enabled' => true]);
        $monitor = new CircuitMonitor(fn (string $ip) => ['loss' => 0, 'rtt' => 5.0]);

        $beats = 0;
        $monitor->checkAll(function () use (&$beats) {
            $beats++;
        });

        $this->assertSame(3, $beats, 'one heartbeat per circuit swept');
    }

    public function test_checkall_uses_the_parallel_batch_and_still_drives_alarms(): void
    {
        // One up circuit that will read down, one down circuit that will read up —
        // both measured from the batch map, not the single-host pinger.
        $goingDown = Circuit::factory()->create(['status' => 'up', 'monitored_ip' => '10.0.0.1', 'monitor_via' => 'icmp']);
        $recovering = Circuit::factory()->create(['status' => 'down', 'monitored_ip' => '10.0.0.2', 'monitor_via' => 'icmp']);
        CircuitAlert::factory()->create(['circuit_id' => $recovering->id, 'ended_at' => null]);

        $batch = [
            '10.0.0.1' => ['loss' => 100, 'rtt' => null],   // now down
            '10.0.0.2' => ['loss' => 0, 'rtt' => 8.0],      // now up
        ];
        // Single-host pinger must NOT be consulted for batchable circuits.
        $monitor = new CircuitMonitor(
            fn (string $ip) => throw new \RuntimeException("single pinger used for {$ip} — should have used the batch"),
            null,
            fn (array $ips) => $batch,
        );

        $monitor->checkAll();

        $goingDown->refresh();
        $recovering->refresh();
        $this->assertSame('down', $goingDown->status);
        $this->assertSame('up', $recovering->status);
        $this->assertDatabaseHas('circuit_alerts', ['circuit_id' => $goingDown->id, 'ended_at' => null]);
        $this->assertNotNull($recovering->alerts()->latest('started_at')->first()->ended_at);
    }

    public function test_a_circuit_that_throws_still_beats_so_the_sweep_continues(): void
    {
        Circuit::factory()->count(2)->create(['monitoring_enabled' => true]);
        // Pinger blows up → check() throws → caught per circuit; the beat must
        // still fire so one bad circuit cannot stall the whole sweep's liveness.
        $monitor = new CircuitMonitor(function (string $ip) {
            throw new \RuntimeException('ping exploded');
        });

        $beats = 0;
        $monitor->checkAll(function () use (&$beats) {
            $beats++;
        });

        $this->assertSame(2, $beats);
    }

    public function test_a_single_lossy_poll_does_not_flag_sustained_degradation(): void
    {
        // The false positive: one dropped probe (a jitter spike that recovers) must
        // NOT read as "degraded" — the median of recent polls stays at 0.
        $circuit = Circuit::factory()->create(['status' => 'up']);
        foreach ([0, 0, 20, 0, 0] as $loss) {
            (new CircuitMonitor(fn (string $ip) => ['loss' => $loss, 'rtt' => 5.0]))->check($circuit->fresh());
        }

        $this->assertSame(0, (int) $circuit->fresh()->sustained_loss_pct);
    }

    public function test_sustained_loss_flags_degradation(): void
    {
        $circuit = Circuit::factory()->create(['status' => 'up']);
        foreach ([0, 20, 40, 20, 20] as $loss) {
            (new CircuitMonitor(fn (string $ip) => ['loss' => $loss, 'rtt' => 5.0]))->check($circuit->fresh());
        }

        $this->assertGreaterThanOrEqual(20, (int) $circuit->fresh()->sustained_loss_pct);
    }

    public function test_transition_to_down_opens_an_alert(): void
    {
        $circuit = Circuit::factory()->create(['status' => 'up']);
        $monitor = new CircuitMonitor(fn (string $ip) => null);

        $monitor->check($circuit);

        $circuit->refresh();
        $this->assertSame('down', $circuit->status);
        $this->assertDatabaseHas('circuit_alerts', [
            'circuit_id' => $circuit->id,
            'ended_at' => null,
        ]);
    }

    public function test_transition_to_up_closes_the_open_alert(): void
    {
        $circuit = Circuit::factory()->create(['status' => 'down']);
        $alert = CircuitAlert::factory()->create(['circuit_id' => $circuit->id, 'ended_at' => null]);
        $monitor = new CircuitMonitor(fn (string $ip) => ['loss' => 0, 'rtt' => 12.5]);

        $monitor->check($circuit);

        $circuit->refresh();
        $alert->refresh();
        $this->assertSame('up', $circuit->status);
        $this->assertNotNull($alert->ended_at);
    }

    public function test_an_up_circuit_self_heals_a_stale_open_alert_never_seen_go_down(): void
    {
        // #024 Boca (ticket 35049308): a seeded/legacy alert (null cause) sat open on
        // a circuit that was 'up' the whole time, so the down→up edge never fired and
        // it showed false "down" for over a day. A poll that reads up must close it
        // even though the circuit never transitioned in this process.
        $circuit = Circuit::factory()->create(['status' => 'up']);
        $stale = CircuitAlert::factory()->create([
            'circuit_id' => $circuit->id, 'ended_at' => null, 'cause' => null,
        ]);
        $monitor = new CircuitMonitor(fn (string $ip) => ['loss' => 0, 'rtt' => 9.0]);

        $monitor->check($circuit);

        $this->assertNotNull($stale->refresh()->ended_at, 'a stale open alert must clear once the circuit reads up');
    }

    public function test_staying_down_does_not_open_a_duplicate_alert(): void
    {
        $circuit = Circuit::factory()->create(['status' => 'down']);
        CircuitAlert::factory()->create(['circuit_id' => $circuit->id, 'ended_at' => null]);
        $monitor = new CircuitMonitor(fn (string $ip) => null);

        $monitor->check($circuit);

        $this->assertSame(1, CircuitAlert::where('circuit_id', $circuit->id)->count());
    }

    public function test_a_reachable_check_records_the_response_time(): void
    {
        $circuit = Circuit::factory()->create(['status' => 'up']);
        $monitor = new CircuitMonitor(fn (string $ip) => ['loss' => 0, 'rtt' => 18.4]);

        $monitor->check($circuit);

        $this->assertDatabaseHas('circuit_metric_history', [
            'circuit_id' => $circuit->id,
            'response_time_ms' => 18.4,
        ]);
    }

    public function test_a_timeout_records_a_null_response_time(): void
    {
        $circuit = Circuit::factory()->create(['status' => 'up']);
        $monitor = new CircuitMonitor(fn (string $ip) => null);

        $monitor->check($circuit);

        $history = CircuitMetricHistory::where('circuit_id', $circuit->id)->first();
        $this->assertNotNull($history);
        $this->assertNull($history->response_time_ms);
    }

    public function test_an_abrupt_outage_is_classified_hard_down(): void
    {
        $circuit = Circuit::factory()->create(['status' => 'up']);
        // Prior cycles were clean (0% loss), then a sudden 100% drop.
        CircuitMetricHistory::create(['circuit_id' => $circuit->id, 'recorded_at' => now()->subMinutes(2), 'response_time_ms' => 10, 'loss_pct' => 0]);
        CircuitMetricHistory::create(['circuit_id' => $circuit->id, 'recorded_at' => now()->subMinute(), 'response_time_ms' => 11, 'loss_pct' => 0]);

        (new CircuitMonitor(fn (string $ip) => ['loss' => 100, 'rtt' => null]))->check($circuit);

        $alert = CircuitAlert::where('circuit_id', $circuit->id)->first();
        $this->assertSame('hard_down', $alert->cause);
        $this->assertSame(100, $alert->detected_loss_pct);
    }

    public function test_an_outage_after_rising_loss_is_classified_packet_loss(): void
    {
        $circuit = Circuit::factory()->create(['status' => 'up']);
        // A brownout: loss climbing before the circuit finally drops.
        CircuitMetricHistory::create(['circuit_id' => $circuit->id, 'recorded_at' => now()->subMinutes(2), 'response_time_ms' => 20, 'loss_pct' => 20]);
        CircuitMetricHistory::create(['circuit_id' => $circuit->id, 'recorded_at' => now()->subMinute(), 'response_time_ms' => 60, 'loss_pct' => 60]);

        (new CircuitMonitor(fn (string $ip) => ['loss' => 100, 'rtt' => null]))->check($circuit);

        $this->assertSame('packet_loss', CircuitAlert::where('circuit_id', $circuit->id)->first()->cause);
    }

    public function test_partial_loss_stays_up_but_records_the_loss(): void
    {
        $circuit = Circuit::factory()->create(['status' => 'up']);

        (new CircuitMonitor(fn (string $ip) => ['loss' => 40, 'rtt' => 30.0]))->check($circuit);

        $circuit->refresh();
        $this->assertSame('up', $circuit->status);   // 40% loss still passes traffic
        $this->assertSame(40, $circuit->last_loss_pct);
        $this->assertSame(0, CircuitAlert::where('circuit_id', $circuit->id)->count()); // no outage
        $this->assertDatabaseHas('circuit_metric_history', ['circuit_id' => $circuit->id, 'loss_pct' => 40]);
    }

    public function test_checkall_isolates_a_failing_circuit_from_the_rest(): void
    {
        $goodCircuit = Circuit::factory()->create(['status' => 'up', 'monitored_ip' => '10.0.0.1']);
        $badCircuit = Circuit::factory()->create(['status' => 'up', 'monitored_ip' => '10.0.0.2']);

        $monitor = new CircuitMonitor(function (string $ip) {
            if ($ip === '10.0.0.2') {
                throw new \RuntimeException('unreachable');
            }

            return ['loss' => 0, 'rtt' => 9.0];
        });

        $monitor->checkAll();

        $goodCircuit->refresh();
        $badCircuit->refresh();

        // The bad circuit's check throws before it can update status, so it
        // stays at its prior value — checkAll() logs and continues rather
        // than crashing the whole batch or leaving the good circuit unchecked.
        $this->assertSame('up', $goodCircuit->status);
        $this->assertSame('up', $badCircuit->status);
    }

    public function test_sdwan_circuit_uses_the_wan_sourced_ping_not_direct_icmp(): void
    {
        $site = \App\Models\Site::factory()->create();
        $edge = \App\Models\Device::factory()->create(['site_id' => $site->id, 'role' => 'edgeconnect']);
        $circuit = Circuit::factory()->create([
            'site_id' => $site->id, 'status' => 'down',
            'monitor_via' => 'sdwan', 'wan_interface' => 'wan0', 'ping_target' => '8.8.8.8',
        ]);

        $icmpCalled = false;
        $sdwanArgs = null;
        $monitor = new CircuitMonitor(
            function () use (&$icmpCalled) { $icmpCalled = true; return null; },
            function ($dev, $wan, $target) use (&$sdwanArgs) { $sdwanArgs = [$dev->id, $wan, $target]; return 12.0; },
        );

        $monitor->check($circuit);

        $this->assertFalse($icmpCalled, 'A SDWAN circuit must not fall back to direct ICMP when an edge + WAN exist');
        $this->assertSame([$edge->id, 'wan0', '8.8.8.8'], $sdwanArgs);
        $this->assertSame('up', $circuit->fresh()->status); // WAN ping replied → up
    }
}
