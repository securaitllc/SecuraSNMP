<?php

namespace Tests\Unit;

use App\Models\Device;
use App\Models\DeviceNextHop;
use App\Models\NextHopAlert;
use App\Models\Site;
use App\Services\NextHopPoller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NextHopPollerTest extends TestCase
{
    use RefreshDatabase;

    private const CMD = 'show system nexthops';

    private function edge(): Device
    {
        return Device::factory()->create(['role' => 'edgeconnect', 'site_id' => Site::factory()->create()->id]);
    }

    private function sampleOutput(string $wan0State, string $wan1State): array
    {
        return [self::CMD => <<<TXT
        Next-hop                                        Interface  Reachability      Uptime
        ---------------------------------------------   ---------- ---------------   ------------------
        4.17.76.49                                      wan1       {$wan1State}         1h 1m 6.202s
        192.168.1.1                                     wan0       {$wan0State}         5d 0h 16m 7.639s
        TXT];
    }

    public function test_collects_both_next_hops_with_interface_and_reachability(): void
    {
        $device = $this->edge();

        (new NextHopPoller(fn ($d, $c) => $this->sampleOutput('reachable', 'reachable')))->poll($device);

        $this->assertSame(2, DeviceNextHop::where('device_id', $device->id)->count());
        $wan0 = DeviceNextHop::where('ip_address', '192.168.1.1')->first();
        $this->assertSame('wan0', $wan0->interface);
        $this->assertSame('up', $wan0->status);
    }

    public function test_unreachable_next_hop_raises_an_alert(): void
    {
        $device = $this->edge();

        (new NextHopPoller(fn ($d, $c) => $this->sampleOutput('unreachable', 'reachable')))->poll($device);

        $wan0 = DeviceNextHop::where('ip_address', '192.168.1.1')->first();
        $this->assertSame('down', $wan0->status);
        $this->assertSame(1, NextHopAlert::where('device_next_hop_id', $wan0->id)->whereNull('ended_at')->count());
        // The still-reachable WAN raises no alert.
        $wan1 = DeviceNextHop::where('ip_address', '4.17.76.49')->first();
        $this->assertSame(0, NextHopAlert::where('device_next_hop_id', $wan1->id)->count());
    }

    public function test_a_paused_circuits_wan_next_hop_never_alarms(): void
    {
        $device = $this->edge();
        // wan1 (LTE) paused. Its next-hop is down but must NOT raise an alert;
        // wan0 down still alarms as normal.
        \App\Models\Circuit::factory()->create([
            'site_id' => $device->site_id, 'wan_interface' => 'wan1', 'monitoring_enabled' => false,
        ]);

        (new NextHopPoller(fn ($d, $c) => $this->sampleOutput('unreachable', 'unreachable')))->poll($device);

        $wan1 = DeviceNextHop::where('ip_address', '4.17.76.49')->first();
        $this->assertSame('down', $wan1->status); // still tracked in inventory
        $this->assertSame(0, NextHopAlert::where('device_next_hop_id', $wan1->id)->count(), 'paused WAN must not alarm');

        $wan0 = DeviceNextHop::where('ip_address', '192.168.1.1')->first();
        $this->assertSame(1, NextHopAlert::where('device_next_hop_id', $wan0->id)->whereNull('ended_at')->count());
    }

    public function test_pausing_a_circuit_closes_its_open_next_hop_alert(): void
    {
        $device = $this->edge();
        // wan1 down and already alarming.
        (new NextHopPoller(fn ($d, $c) => $this->sampleOutput('reachable', 'unreachable')))->poll($device);
        $wan1 = DeviceNextHop::where('ip_address', '4.17.76.49')->first();
        $this->assertSame(1, NextHopAlert::where('device_next_hop_id', $wan1->id)->whereNull('ended_at')->count());

        // Now pause wan1 → next poll must close the open alert.
        \App\Models\Circuit::factory()->create([
            'site_id' => $device->site_id, 'wan_interface' => 'wan1', 'monitoring_enabled' => false,
        ]);
        (new NextHopPoller(fn ($d, $c) => $this->sampleOutput('reachable', 'unreachable')))->poll($device);
        $this->assertSame(0, NextHopAlert::where('device_next_hop_id', $wan1->id)->whereNull('ended_at')->count());
    }

    public function test_alert_clears_when_the_next_hop_recovers(): void
    {
        $device = $this->edge();
        $poller = new NextHopPoller(fn ($d, $c) => $this->sampleOutput('unreachable', 'reachable'));
        $poller->poll($device);
        $wan0 = DeviceNextHop::where('ip_address', '192.168.1.1')->first();
        $this->assertNull(NextHopAlert::where('device_next_hop_id', $wan0->id)->first()->ended_at);

        // Recovers.
        (new NextHopPoller(fn ($d, $c) => $this->sampleOutput('reachable', 'reachable')))->poll($device);
        $this->assertNotNull(NextHopAlert::where('device_next_hop_id', $wan0->id)->first()->ended_at);
    }

    public function test_empty_output_does_not_wipe_state(): void
    {
        $device = $this->edge();
        (new NextHopPoller(fn ($d, $c) => $this->sampleOutput('reachable', 'reachable')))->poll($device);

        // Unreachable device / garbage output must not delete the known next-hops.
        (new NextHopPoller(fn ($d, $c) => [self::CMD => '']))->poll($device);
        $this->assertSame(2, DeviceNextHop::where('device_id', $device->id)->count());
    }
}
