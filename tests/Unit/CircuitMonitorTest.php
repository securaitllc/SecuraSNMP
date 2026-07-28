<?php

namespace Tests\Unit;

use App\Models\Circuit;
use App\Models\CircuitAlert;
use App\Models\CircuitMetricHistory;
use App\Services\CircuitMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CircuitMonitorTest extends TestCase
{
    use RefreshDatabase;

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
