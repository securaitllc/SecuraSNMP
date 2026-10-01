<?php

namespace Tests\Feature;

use App\Models\Circuit;
use App\Models\Device;
use App\Models\Site;
use App\Services\CircuitTransportLoss;
use App\Services\SshVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Real output from SC037-ECB01, 20 September.
 *
 * Nodus reported that circuit at 20% of polls lossy, peak 80%, from pinging Lumen's
 * gateway at 4.4.252.37 — a device that polices ICMP addressed to itself. The
 * appliance had carried 574,089 packets across the same circuit in 23 hours and lost
 * none of them.
 */
class CircuitTransportLossTest extends TestCase
{
    use RefreshDatabase;

    /** Trimmed verbatim from the appliance — the shape the parser must survive. */
    private const SHOW_TUNNEL = <<<'OUT'
Tunnel to_HQ-ECH01_DefaultOverlay(bondedTunnel_20) state
  Admin:               up
  Oper:                Up - Active
  Bonded tunnel state brown Loss 0
LAN Rx Bytes:          124420776
    Rx Pkts:           636983
    Tx Pkts:           560492

WAN Rx Bytes:          126197360
    Rx Pkts:           556722
    Rx Invalid Pkts:   0
    Rx Lost Pkts:      0
    Rx Duplicate Pkts: 0

Tunnel to_HQ-ECH01_DIA1-DIA2(tunnel_14) state
  Admin:               up
  Oper:                Up - Active
  Tunnel ID:           14
  Local IP address:    4.4.252.38
  Remote IP address:   131.148.15.198
  MTU:                 1488

LAN Rx Bytes:          129063074
    Rx Pkts:           670232
    Tx Pkts:           577964
    Tx Invalid Pkts:   0

WAN Rx Bytes:          131030448
    Rx Pkts:           574089
    Tx Pkts:           654303
    Rx Invalid Pkts:   0
    Rx Lost Pkts:      0
    Rx Duplicate Pkts: 0

Tunnel to_HQ-ECH02_DIA1-BB(tunnel_16) state
  Admin:               up
  Oper:                Up - Idle
  Tunnel ID:           16
  Local IP address:    4.4.252.38
  Remote IP address:   71.46.241.36
  MTU:                 1488

LAN Rx Bytes:          0
    Rx Pkts:           0

WAN Rx Bytes:          0
    Rx Pkts:           0
    Rx Invalid Pkts:   0
    Rx Lost Pkts:      0
    Rx Duplicate Pkts: 0
OUT;

    private function siteWithCircuit(): array
    {
        $site = Site::factory()->create(['site_number' => '037', 'name' => '#037 Lawrenceville GA']);
        $device = Device::factory()->for($site)->create(['name' => 'SC037-ECB01', 'role' => 'edgeconnect', 'vendor' => 'silverpeak']);
        $circuit = Circuit::factory()->for($site)->create([
            'isp_name' => 'Lumen', 'circuit_id' => '445453113',
            'monitored_ip' => '4.4.252.37', 'gateway_ip' => '4.4.252.37',
            'wan_interface' => 'wan1', 'monitoring_enabled' => true,
        ]);

        return [$device, $circuit];
    }

    /** @param array<string, array<string,mixed>> $underlays */
    private function record(Device $d, array $underlays): void
    {
        (new CircuitTransportLoss)->record($d, $underlays);
    }

    public function test_the_real_appliance_output_maps_to_the_circuit(): void
    {
        [$device, $circuit] = $this->siteWithCircuit();

        $verifier = new SshVerifier(fn () => ['show tunnel' => self::SHOW_TUNNEL]);
        $verifier->verify($device);

        $circuit->refresh();

        // 574,089 received and none lost across the two underlays on this circuit.
        $this->assertSame(574089, (int) $circuit->transport_rx_pkts);
        $this->assertSame(0, (int) $circuit->transport_lost_pkts);
    }

    public function test_the_first_reading_sets_a_baseline_and_claims_nothing(): void
    {
        // One cumulative reading is not a rate. Reporting 0% from it would be the
        // same false-healthy answer in a new place.
        [$device, $circuit] = $this->siteWithCircuit();

        $this->record($device, ['t' => ['local_ip' => '4.4.252.38', 'rx_pkts' => 574089, 'lost_pkts' => 0]]);

        $this->assertNull($circuit->refresh()->transport_loss_pct);
        $this->assertSame(574089, (int) $circuit->transport_rx_pkts);
    }

    public function test_a_second_reading_gives_the_loss_over_the_interval(): void
    {
        [$device, $circuit] = $this->siteWithCircuit();

        $this->record($device, ['t' => ['local_ip' => '4.4.252.38', 'rx_pkts' => 100000, 'lost_pkts' => 0]]);
        $this->record($device, ['t' => ['local_ip' => '4.4.252.38', 'rx_pkts' => 109900, 'lost_pkts' => 100]]);

        $circuit->refresh();
        $this->assertSame(1.0, round((float) $circuit->transport_loss_pct, 3));
        $this->assertSame(10000, (int) $circuit->transport_sample_pkts);
    }

    public function test_a_clean_circuit_reads_zero_not_null(): void
    {
        // The whole point: real traffic, really measured, really clean. #037 must be
        // able to say 0% and mean it.
        [$device, $circuit] = $this->siteWithCircuit();

        $this->record($device, ['t' => ['local_ip' => '4.4.252.38', 'rx_pkts' => 500000, 'lost_pkts' => 0]]);
        $this->record($device, ['t' => ['local_ip' => '4.4.252.38', 'rx_pkts' => 574089, 'lost_pkts' => 0]]);

        $this->assertSame(0.0, (float) $circuit->refresh()->transport_loss_pct);
    }

    public function test_an_idle_tunnel_reads_unmeasured_not_clean(): void
    {
        // Most tunnels are standby and carry nothing. No traffic is not evidence of
        // health — this is the rule the 15 September outage was built on breaking.
        [$device, $circuit] = $this->siteWithCircuit();

        $this->record($device, ['t' => ['local_ip' => '4.4.252.38', 'rx_pkts' => 500000, 'lost_pkts' => 0]]);
        $this->record($device, ['t' => ['local_ip' => '4.4.252.38', 'rx_pkts' => 500004, 'lost_pkts' => 0]]);

        $circuit->refresh();
        $this->assertNull($circuit->transport_loss_pct, '4 packets is not a measurement');
        $this->assertSame(4, (int) $circuit->transport_sample_pkts);
    }

    public function test_a_counter_reset_rebaselines_instead_of_reporting_nonsense(): void
    {
        // An appliance reboot restarts the counters. A negative delta must not become
        // a negative loss percentage.
        [$device, $circuit] = $this->siteWithCircuit();

        $this->record($device, ['t' => ['local_ip' => '4.4.252.38', 'rx_pkts' => 900000, 'lost_pkts' => 40]]);
        $this->record($device, ['t' => ['local_ip' => '4.4.252.38', 'rx_pkts' => 120, 'lost_pkts' => 0]]);

        $circuit->refresh();
        $this->assertNull($circuit->transport_loss_pct);
        $this->assertSame(120, (int) $circuit->transport_rx_pkts, 're-baselined to the new counter');
    }

    public function test_several_tunnels_on_one_circuit_are_summed(): void
    {
        // A branch holds an underlay to each hub over the same WAN. The circuit
        // carried all of it, so the counters add.
        [$device, $circuit] = $this->siteWithCircuit();

        $this->record($device, [
            'a' => ['local_ip' => '4.4.252.38', 'rx_pkts' => 0, 'lost_pkts' => 0],
            'b' => ['local_ip' => '4.4.252.38', 'rx_pkts' => 0, 'lost_pkts' => 0],
        ]);
        $this->record($device, [
            'a' => ['local_ip' => '4.4.252.38', 'rx_pkts' => 60000, 'lost_pkts' => 300],
            'b' => ['local_ip' => '4.4.252.38', 'rx_pkts' => 40000, 'lost_pkts' => 100],
        ]);

        $circuit->refresh();
        $this->assertSame(100000, (int) $circuit->transport_rx_pkts);
        $this->assertSame(400, (int) $circuit->transport_lost_pkts);
        $this->assertSame(0.398, round((float) $circuit->transport_loss_pct, 3));
    }

    public function test_a_tunnel_on_a_different_circuit_is_not_attributed_here(): void
    {
        [$device, $circuit] = $this->siteWithCircuit();

        $this->record($device, ['t' => ['local_ip' => '50.73.67.49', 'rx_pkts' => 999999, 'lost_pkts' => 5000]]);

        $this->assertNull($circuit->refresh()->transport_rx_pkts, 'the broadband underlay is not the Lumen circuit');
    }

    public function test_the_gateway_itself_never_matches(): void
    {
        // The circuit's own gateway address is the carrier's end, not ours. Matching
        // it would attribute the policed device's counters to the circuit.
        $this->assertFalse(CircuitTransportLoss::sameHandoff('4.4.252.37', '4.4.252.37'));
        $this->assertTrue(CircuitTransportLoss::sameHandoff('4.4.252.38', '4.4.252.37'));
        $this->assertFalse(CircuitTransportLoss::sameHandoff('4.4.252.42', '4.4.252.37'));
    }
}
