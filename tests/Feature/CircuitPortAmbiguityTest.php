<?php

namespace Tests\Feature;

use App\Models\Circuit;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\DeviceNextHop;
use App\Models\InterfaceMetricHistory;
use App\Models\Site;
use App\Services\CircuitBandwidth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A port NAME is not an identifier.
 *
 * Massey's HQ runs five appliances that each have a "wan1". Two different
 * circuits recorded as wan1 both resolved to whichever appliance came first and
 * reported the SAME throughput — one of those numbers was fiction, and it would
 * send an engineer to the wrong provider. Evidence is used strongest-first: the
 * port's own label, then the gateway, then the name where it is unambiguous.
 */
class CircuitPortAmbiguityTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Site::factory()->create();
    }

    /** An appliance with one WAN port carrying $mbps down. */
    private function appliance(string $name, string $port, string $label, float $mbps): DeviceInterface
    {
        $device = Device::factory()->create(['site_id' => $this->site->id, 'name' => $name, 'role' => 'edgeconnect']);
        $iface = DeviceInterface::factory()->create([
            'device_id' => $device->id, 'if_name' => $label, 'if_canonical_name' => $port,
        ]);

        $now = now();
        foreach ([[300, 0.0], [0, $mbps]] as [$ago, $rate]) {
            InterfaceMetricHistory::create([
                'device_interface_id' => $iface->id, 'recorded_at' => $now->copy()->subSeconds($ago),
                'status' => 'up', 'in_octets_delta' => (int) ($rate * 1_000_000 / 8 * 300), 'out_octets_delta' => 0,
                'in_discards_delta' => 0, 'out_discards_delta' => 0,
            ]);
        }

        return $iface;
    }

    private function circuit(array $attributes): Circuit
    {
        return Circuit::factory()->create($attributes + [
            'site_id' => $this->site->id, 'contract_down_mbps' => 100, 'contract_up_mbps' => 100,
        ]);
    }

    public function test_the_port_label_naming_the_circuit_beats_a_wrong_port_name(): void
    {
        // Exactly HQ: the circuit is recorded as wan1, but the FortiGate's wan2
        // carries a label naming that circuit. The label is the fact.
        $this->appliance('HQ-PRI', 'wan1', 'wan1', 11.75);
        $this->appliance('HQ-FW', 'wan2', 'Spectrum CID 40.L1XX.009358..CHTR', 4.0);

        $bw = (new CircuitBandwidth)->for($this->circuit([
            'circuit_id' => '40.L1XX.009358..CHTR', 'wan_interface' => 'wan1', 'gateway_ip' => null,
        ]));

        $this->assertTrue($bw['mapped']);
        $this->assertSame(4.0, $bw['down_mbps'], 'it must read the port that names this circuit, not the first wan1 at the site');
        $this->assertSame('wan2', $bw['wan_interface']);
    }

    public function test_two_circuits_on_a_shared_port_name_never_report_the_same_number(): void
    {
        $this->appliance('HQ-PRI', 'wan1', 'wan1', 11.75);
        $this->appliance('HQ-SEC', 'wan1', 'wan1', 3.5);

        $a = (new CircuitBandwidth)->for($this->circuit(['circuit_id' => 'AAA-111111', 'wan_interface' => 'wan1', 'gateway_ip' => null]));
        $b = (new CircuitBandwidth)->for($this->circuit(['circuit_id' => 'BBB-222222', 'wan_interface' => 'wan1', 'gateway_ip' => null]));

        // Neither is measured, and the reason says what to do about it. A shared
        // reading would be worse than none: it reads as a fact.
        $this->assertFalse($a['mapped']);
        $this->assertFalse($b['mapped']);
        $this->assertStringContainsString('2 appliances', $a['reason']);
        $this->assertStringContainsString('gateway IP', $a['reason']);
    }

    public function test_the_gateway_breaks_the_tie(): void
    {
        $this->appliance('HQ-PRI', 'wan1', 'wan1', 11.75);
        $second = $this->appliance('HQ-SEC', 'wan1', 'wan1', 3.5);
        DeviceNextHop::create([
            'device_id' => $second->device_id, 'ip_address' => '4.71.38.129',
            'interface' => 'wan1', 'status' => 'up', 'last_checked_at' => now(),
        ]);

        $bw = (new CircuitBandwidth)->for($this->circuit([
            'circuit_id' => 'CCC-333333', 'wan_interface' => 'wan1', 'gateway_ip' => '4.71.38.129',
        ]));

        $this->assertTrue($bw['mapped']);
        $this->assertSame(3.5, $bw['down_mbps'], 'the appliance that reaches this gateway is the one carrying the circuit');
    }

    public function test_a_single_appliance_still_resolves_by_name_alone(): void
    {
        $this->appliance('BRANCH-EC', 'wan0', 'BB,', 8.0);

        $bw = (new CircuitBandwidth)->for($this->circuit([
            'circuit_id' => 'DDD-444444', 'wan_interface' => 'wan0', 'gateway_ip' => null,
        ]));

        $this->assertTrue($bw['mapped']);
        $this->assertSame(8.0, $bw['down_mbps']);
    }
}
