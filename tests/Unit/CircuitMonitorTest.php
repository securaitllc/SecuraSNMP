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
        $monitor = new CircuitMonitor(fn (string $ip) => 12.5);

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
        $monitor = new CircuitMonitor(fn (string $ip) => 18.4);

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

    public function test_checkall_isolates_a_failing_circuit_from_the_rest(): void
    {
        $goodCircuit = Circuit::factory()->create(['status' => 'up', 'monitored_ip' => '10.0.0.1']);
        $badCircuit = Circuit::factory()->create(['status' => 'up', 'monitored_ip' => '10.0.0.2']);

        $monitor = new CircuitMonitor(function (string $ip) {
            if ($ip === '10.0.0.2') {
                throw new \RuntimeException('unreachable');
            }

            return 9.0;
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
