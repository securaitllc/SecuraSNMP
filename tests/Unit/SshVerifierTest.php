<?php

namespace Tests\Unit;

use App\Models\Device;
use App\Models\DeviceAlarm;
use App\Models\NextHopAlert;
use App\Models\Tunnel;
use App\Models\TunnelAlert;
use App\Services\SshVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SshVerifierTest extends TestCase
{
    use RefreshDatabase;

    private function fakeExecutor(array $responses): callable
    {
        return function (Device $device, array $commands) use ($responses) {
            return $responses;
        };
    }

    private function edgeConnectDevice(array $overrides = []): Device
    {
        return Device::factory()->create(array_merge([
            'role' => 'edgeconnect',
            'ssh_username' => 'admin',
            'ssh_credential' => 'secret',
        ], $overrides));
    }

    public function test_parses_bonded_overlay_tunnels_and_derives_the_hub(): void
    {
        $device = $this->edgeConnectDevice();
        $output = <<<'TXT'
        Tunnel to_HUB-A-PRI_DefaultOverlay(bondedTunnel_20) state
          Admin:               up
          Oper:                Up - Active
        Tunnel to_HUB-A-SEC_RealTime(bondedTunnel_32) state
          Admin:               up
          Oper:                Up - Idle
        Tunnel to_HUB-B-PRI_CriticalApps(bondedTunnel_81) state
          Admin:               up
          Oper:                Down
        Tunnel to_HUB-A-PRI_DIA1-DIA1(tunnel_92) state
          Admin:               up
          Oper:                Up - Active
        Tunnel Passthrough_DIA1_Managment(passThrough_107) state
          Admin:               up
          Oper:                Up - Active
        TXT;

        (new SshVerifier($this->fakeExecutor(['show tunnel' => $output])))->verify($device);

        $tunnels = Tunnel::where('device_id', $device->id)->get();
        // Only the 3 bonded overlays — never the underlay tunnel_ or passThrough_.
        $this->assertCount(3, $tunnels);

        $hq = $tunnels->firstWhere('tunnel_name', 'to_HUB-A-PRI_DefaultOverlay');
        $this->assertSame('HUB-A-PRI', $hq->peer);
        $this->assertSame('HUB-A', $hq->hub);   // -PRI/-SEC roll up to one hub
        $this->assertSame('up', $hq->status);

        $az = $tunnels->firstWhere('tunnel_name', 'to_HUB-B-PRI_CriticalApps');
        $this->assertSame('HUB-B', $az->hub);
        $this->assertSame('down', $az->status);      // Oper: Down

        $this->assertEqualsCanonicalizing(['HUB-A', 'HUB-B'], $tunnels->pluck('hub')->unique()->values()->all());
    }

    public function test_ssh_verify_does_not_touch_alarms(): void
    {
        // EdgeConnect alarms are owned by EdgeConnectAlarmPoller (SNMP). SSH
        // verify must never create or clear DeviceAlarm rows, so it can't
        // duplicate a fault the SNMP poller already tracks.
        $device = $this->edgeConnectDevice();
        DeviceAlarm::factory()->create([
            'device_id' => $device->id,
            'alarm_id' => 'ec:65541:Tunnel',
            'cleared_at' => null,
        ]);

        $verifier = new SshVerifier($this->fakeExecutor([
            'show tunnel' => '',
        ]));

        $verifier->verify($device);

        // The pre-existing SNMP alarm is left exactly as it was, and no new
        // alarm was created from SSH.
        $this->assertSame(1, DeviceAlarm::where('device_id', $device->id)->count());
        $this->assertNull(DeviceAlarm::where('device_id', $device->id)->first()->cleared_at);
    }

    public function test_a_tunnel_first_seen_down_does_not_open_an_alert(): void
    {
        // A peer already removed from the orchestrator: the tunnel is down the very
        // first time we see it. That is not a fresh fault — no alert.
        $device = $this->edgeConnectDevice();
        (new SshVerifier($this->fakeExecutor([
            'show tunnel' => "Tunnel to_HUB-A-PRI_DefaultOverlay(bondedTunnel_20) state\n  Oper: Down",
        ])))->verify($device);

        $tunnel = Tunnel::where('device_id', $device->id)->first();
        $this->assertSame('down', $tunnel->status);
        $this->assertSame(0, TunnelAlert::where('tunnel_id', $tunnel->id)->count());
    }

    public function test_a_tunnel_coming_back_up_auto_closes_its_open_alert(): void
    {
        // The primary behaviour: an open tunnel-down alarm clears itself when the
        // tunnel recovers — no manual action.
        $device = $this->edgeConnectDevice();
        $tunnel = Tunnel::factory()->create(['device_id' => $device->id, 'tunnel_name' => 'to_HUB-A-PRI_X', 'status' => 'down']);
        $alert = TunnelAlert::factory()->create(['tunnel_id' => $tunnel->id, 'ended_at' => null]);

        (new SshVerifier($this->fakeExecutor([
            'show tunnel' => "Tunnel to_HUB-A-PRI_X(bondedTunnel_1) state\n  Oper: Up - Active",
        ])))->verify($device);

        $this->assertNotNull($alert->fresh()->ended_at);
    }

    public function test_a_vanished_tunnel_is_deleted_from_inventory(): void
    {
        // Peer removed from the orchestrator → the tunnel is gone from the output.
        // It no longer exists, so it must be deleted (not left as a down ghost);
        // the cascade takes its open alert with it.
        $device = $this->edgeConnectDevice();
        $tunnel = Tunnel::factory()->create(['device_id' => $device->id, 'tunnel_name' => 'to_GONE-PRI_X', 'status' => 'down']);
        TunnelAlert::factory()->create(['tunnel_id' => $tunnel->id, 'ended_at' => null]);

        (new SshVerifier($this->fakeExecutor([
            'show tunnel' => "Tunnel to_HUB-A-PRI_Y(bondedTunnel_2) state\n  Oper: Up - Active",
        ])))->verify($device);

        $this->assertDatabaseMissing('tunnels', ['id' => $tunnel->id]);
        $this->assertDatabaseMissing('tunnel_alerts', ['tunnel_id' => $tunnel->id]);
    }

    public function test_tunnel_transition_to_down_opens_an_alert(): void
    {
        $device = $this->edgeConnectDevice();
        // A real up->down flap (the tunnel was up on the prior poll) opens an alert.
        $tunnel = Tunnel::factory()->create(['device_id' => $device->id, 'tunnel_name' => 'MPLS-to-DC', 'status' => 'up']);

        (new SshVerifier($this->fakeExecutor([
            'show alarms' => '',
            'show tunnel' => 'MPLS-to-DC down 100 50',
        ])))->verify($device);

        $tunnel->refresh();
        $this->assertSame('down', $tunnel->status);
        $this->assertDatabaseHas('tunnel_alerts', ['tunnel_id' => $tunnel->id, 'ended_at' => null]);
    }

    public function test_tunnel_transition_to_up_closes_the_alert_and_computes_discard_deltas(): void
    {
        $device = $this->edgeConnectDevice();
        $tunnel = Tunnel::factory()->create([
            'device_id' => $device->id,
            'tunnel_name' => 'MPLS-to-DC',
            'status' => 'down',
            'in_discards' => 100,
            'out_discards' => 50,
        ]);
        $alert = TunnelAlert::factory()->create(['tunnel_id' => $tunnel->id, 'ended_at' => null]);

        $verifier = new SshVerifier($this->fakeExecutor([
            'show alarms' => '',
            'show tunnel' => 'MPLS-to-DC up 130 70',
        ]));

        $verifier->verify($device);

        $tunnel->refresh();
        $alert->refresh();
        $this->assertSame('up', $tunnel->status);
        $this->assertNotNull($alert->ended_at);
        $this->assertSame(30, $tunnel->in_discards_delta);
        $this->assertSame(20, $tunnel->out_discards_delta);
    }

    public function test_a_normal_tunnel_sync_writes_a_tunnel_metric_history_row(): void
    {
        $device = $this->edgeConnectDevice();
        $tunnel = Tunnel::factory()->create([
            'device_id' => $device->id,
            'tunnel_name' => 'MPLS-to-DC',
            'status' => 'up',
            'in_discards' => 100,
            'out_discards' => 50,
        ]);

        $verifier = new SshVerifier($this->fakeExecutor([
            'show alarms' => '',
            'show tunnel' => 'MPLS-to-DC up 130 70',
        ]));

        $verifier->verify($device);

        $this->assertDatabaseHas('tunnel_metric_history', [
            'tunnel_id' => $tunnel->id,
            'status' => 'up',
            'in_discards_delta' => 30,
            'out_discards_delta' => 20,
        ]);
    }

    public function test_a_tunnel_missing_from_a_malformed_cycle_gets_no_history_row(): void
    {
        $device = $this->edgeConnectDevice();
        $tunnel = Tunnel::factory()->create([
            'device_id' => $device->id,
            'tunnel_name' => 'MPLS-to-DC',
            'status' => 'up',
        ]);

        $verifier = new SshVerifier($this->fakeExecutor([
            'show alarms' => '',
            // Truncated line — the existing parseTunnels() protection drops this
            // entirely rather than partially parsing it.
            'show tunnel' => 'MPLS-to-DC up',
        ]));

        $verifier->verify($device);

        $this->assertSame(0, \App\Models\TunnelMetricHistory::where('tunnel_id', $tunnel->id)->count());
    }

    public function test_tunnel_staying_down_does_not_open_a_duplicate_alert(): void
    {
        $device = $this->edgeConnectDevice();
        $tunnel = Tunnel::factory()->create([
            'device_id' => $device->id,
            'tunnel_name' => 'MPLS-to-DC',
            'status' => 'down',
        ]);
        TunnelAlert::factory()->create(['tunnel_id' => $tunnel->id, 'ended_at' => null]);

        $verifier = new SshVerifier($this->fakeExecutor([
            'show alarms' => '',
            'show tunnel' => 'MPLS-to-DC down 0 0',
        ]));

        $verifier->verify($device);

        $this->assertSame(1, TunnelAlert::where('tunnel_id', $tunnel->id)->count());
    }

    public function test_a_malformed_tunnel_line_leaves_that_tunnel_untouched(): void
    {
        $device = $this->edgeConnectDevice();
        $tunnel = Tunnel::factory()->create([
            'device_id' => $device->id,
            'tunnel_name' => 'MPLS-to-DC',
            'status' => 'up',
            'in_discards' => 40,
        ]);

        $verifier = new SshVerifier($this->fakeExecutor([
            'show alarms' => '',
            // Truncated line for MPLS-to-DC (missing the discard columns) —
            // must be skipped entirely, not partially parsed.
            'show tunnel' => 'MPLS-to-DC up',
        ]));

        $verifier->verify($device);

        $tunnel->refresh();
        $this->assertSame('up', $tunnel->status);
        $this->assertSame(40, $tunnel->in_discards);
    }


    public function test_next_hop_unreachable_opens_an_alert(): void
    {
        $device = $this->edgeConnectDevice(['next_hop_ip' => '10.0.0.254']);

        $verifier = new SshVerifier($this->fakeExecutor([
            'show alarms' => '',
            'show tunnel' => '',
            'ping 10.0.0.254' => '5 packets transmitted, 0 received, 100% packet loss',
        ]));

        $verifier->verify($device);

        $this->assertDatabaseHas('next_hop_alerts', ['device_id' => $device->id, 'ended_at' => null]);
    }

    public function test_next_hop_recovering_closes_the_open_alert(): void
    {
        $device = $this->edgeConnectDevice(['next_hop_ip' => '10.0.0.254']);
        $alert = NextHopAlert::factory()->create(['device_id' => $device->id, 'ended_at' => null]);

        $verifier = new SshVerifier($this->fakeExecutor([
            'show alarms' => '',
            'show tunnel' => '',
            'ping 10.0.0.254' => '5 packets transmitted, 5 received, 0% packet loss',
        ]));

        $verifier->verify($device);

        $alert->refresh();
        $this->assertNotNull($alert->ended_at);
    }

    public function test_next_hop_unparseable_output_leaves_an_open_alert_untouched(): void
    {
        $device = $this->edgeConnectDevice(['next_hop_ip' => '10.0.0.254']);
        $alert = NextHopAlert::factory()->create(['device_id' => $device->id, 'ended_at' => null]);

        $verifier = new SshVerifier($this->fakeExecutor([
            'show alarms' => '',
            'show tunnel' => '',
            // Doesn't match the expected packet-loss format at all.
            'ping 10.0.0.254' => 'unexpected garbage from firmware variant',
        ]));

        $verifier->verify($device);

        $alert->refresh();
        $this->assertNull($alert->ended_at);
    }

    public function test_next_hop_unparseable_output_does_not_open_a_new_alert(): void
    {
        $device = $this->edgeConnectDevice(['next_hop_ip' => '10.0.0.254']);

        $verifier = new SshVerifier($this->fakeExecutor([
            'show alarms' => '',
            'show tunnel' => '',
            'ping 10.0.0.254' => '',
        ]));

        $verifier->verify($device);

        $this->assertSame(0, NextHopAlert::where('device_id', $device->id)->count());
    }

    public function test_a_device_with_no_next_hop_ip_skips_only_that_check(): void
    {
        $device = $this->edgeConnectDevice(['next_hop_ip' => null]);

        $verifier = new SshVerifier($this->fakeExecutor([
            'show tunnel' => 'MPLS-to-DC up 0 0',
        ]));

        $verifier->verify($device);

        // SSH verify no longer writes alarms; it syncs the tunnel and skips the
        // next-hop check because the device has no next_hop_ip.
        $this->assertSame(0, DeviceAlarm::where('device_id', $device->id)->count());
        $this->assertSame(1, Tunnel::where('device_id', $device->id)->count());
        $this->assertSame(0, NextHopAlert::where('device_id', $device->id)->count());
    }

    public function test_verifyall_skips_a_device_missing_ssh_credentials(): void
    {
        $device = Device::factory()->create(['role' => 'edgeconnect', 'ssh_username' => null, 'ssh_credential' => null]);

        $walkerInvoked = false;
        $verifier = new SshVerifier(function (Device $d, array $commands) use (&$walkerInvoked) {
            $walkerInvoked = true;

            return [];
        });

        $verifier->verifyAll();

        $this->assertFalse($walkerInvoked, 'Executor should never be invoked for a device missing SSH credentials.');
    }

    public function test_verifyall_skips_non_edgeconnect_devices(): void
    {
        $device = Device::factory()->create(['role' => 'switch', 'ssh_username' => 'admin', 'ssh_credential' => 'secret']);

        $walkerInvoked = false;
        $verifier = new SshVerifier(function (Device $d, array $commands) use (&$walkerInvoked) {
            $walkerInvoked = true;

            return [];
        });

        $verifier->verifyAll();

        $this->assertFalse($walkerInvoked, 'Executor should never be invoked for a non-edgeconnect device.');
    }

    public function test_verifyall_isolates_a_failing_device_from_the_rest(): void
    {
        $goodDevice = $this->edgeConnectDevice(['ip_address' => '10.0.0.1']);
        $badDevice = $this->edgeConnectDevice(['ip_address' => '10.0.0.2']);

        $verifier = new SshVerifier(function (Device $device, array $commands) {
            if ($device->ip_address === '10.0.0.2') {
                throw new \RuntimeException('ssh login failed');
            }

            return ['show alarms' => '', 'show tunnel' => 'MPLS-to-DC up 0 0'];
        });

        $verifier->verifyAll();

        $this->assertSame(1, Tunnel::where('device_id', $goodDevice->id)->count());
        $this->assertSame(0, Tunnel::where('device_id', $badDevice->id)->count());
    }

    public function test_verifyall_does_not_touch_existing_data_for_a_failing_device(): void
    {
        $device = $this->edgeConnectDevice();
        $tunnel = Tunnel::factory()->create([
            'device_id' => $device->id,
            'tunnel_name' => 'MPLS-to-DC',
            'status' => 'up',
        ]);

        $verifier = new SshVerifier(function (Device $d, array $commands) {
            throw new \RuntimeException('ssh login failed');
        });

        $verifier->verifyAll();

        $tunnel->refresh();
        $this->assertSame('up', $tunnel->status);
    }
}
