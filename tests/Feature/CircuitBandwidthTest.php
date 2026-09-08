<?php

namespace Tests\Feature;

use App\Models\Circuit;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\InterfaceMetricHistory;
use App\Models\Site;
use App\Services\CircuitBandwidth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A circuit is ICMP-monitored, so it has no throughput of its own. The traffic is
 * measured on the EdgeConnect WAN port it lands on, and reported as a share of the
 * CONTRACT — never of the physical port, which is 1 Gbps (sometimes a nonsense
 * 40 Gbps) behind a circuit sold at 20 or 100 Mbps.
 */
class CircuitBandwidthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  int  $speedBps  deliberately variable — the result must not depend on it
     */
    private function scenario(int $downMbps, int $upMbps, int $inBytes, int $outBytes, int $seconds = 300, int $speedBps = 1_000_000_000): Circuit
    {
        $site = Site::factory()->create();
        $edge = Device::factory()->create(['site_id' => $site->id, 'role' => 'edgeconnect']);
        $iface = DeviceInterface::factory()->create([
            'device_id' => $edge->id, 'if_name' => 'wan0', 'speed_bps' => $speedBps,
        ]);

        $now = now();
        InterfaceMetricHistory::create([
            'device_interface_id' => $iface->id, 'recorded_at' => $now->copy()->subSeconds($seconds),
            'status' => 'up', 'in_octets_delta' => 0, 'out_octets_delta' => 0,
            'in_discards_delta' => 0, 'out_discards_delta' => 0,
        ]);
        InterfaceMetricHistory::create([
            'device_interface_id' => $iface->id, 'recorded_at' => $now,
            'status' => 'up', 'in_octets_delta' => $inBytes, 'out_octets_delta' => $outBytes,
            'in_discards_delta' => 0, 'out_discards_delta' => 0,
        ]);

        return Circuit::factory()->create([
            'site_id' => $site->id, 'wan_interface' => 'wan0',
            'contract_down_mbps' => $downMbps, 'contract_up_mbps' => $upMbps,
        ]);
    }

    public function test_throughput_is_measured_against_the_contract_not_the_port(): void
    {
        // 18 Mbps for 300s = 675 MB in. On a 20 Mbps circuit that is 90% consumed.
        // Against the 1 Gbps PORT the same traffic would read 1.8% and look idle —
        // that difference is the entire reason this class exists.
        $bytes = (int) (18 * 1_000_000 / 8 * 300);
        $circuit = $this->scenario(20, 20, $bytes, 0);

        $bw = (new CircuitBandwidth)->for($circuit);

        $this->assertTrue($bw['mapped']);
        $this->assertSame(18.0, $bw['down_mbps']);
        $this->assertSame(90.0, $bw['down_pct'], 'a 20 Mbps circuit at 18 Mbps is 90% used, not 1.8%');
    }

    public function test_a_nonsense_port_speed_does_not_change_the_answer(): void
    {
        // Some EdgeConnects report a 40 Gbps WAN port. The measurement must come
        // from the octet counters alone, so a bad speed cannot distort it.
        $bytes = (int) (18 * 1_000_000 / 8 * 300);
        $sane = (new CircuitBandwidth)->for($this->scenario(20, 20, $bytes, 0, 300, 1_000_000_000));
        $bogus = (new CircuitBandwidth)->for($this->scenario(20, 20, $bytes, 0, 300, 40_000_000_000));

        $this->assertSame($sane['down_pct'], $bogus['down_pct']);
        $this->assertSame(90.0, $bogus['down_pct']);
    }

    public function test_download_and_upload_are_reported_separately(): void
    {
        // The case that motivated it: a 100/10 circuit comfortable on download and
        // nearly exhausted on upload. Averaging the two would hide the upload.
        $in = (int) (62 * 1_000_000 / 8 * 300);
        $out = (int) (9.4 * 1_000_000 / 8 * 300);
        $bw = (new CircuitBandwidth)->for($this->scenario(100, 10, $in, $out));

        $this->assertSame(62.0, $bw['down_pct']);
        $this->assertSame(94.0, $bw['up_pct']);
    }

    public function test_traffic_over_the_contract_is_not_clamped(): void
    {
        // Bursting past the contract is real and worth seeing; capping at 100 would
        // hide a circuit whose recorded contract speed is simply wrong.
        $bytes = (int) (30 * 1_000_000 / 8 * 300);
        $bw = (new CircuitBandwidth)->for($this->scenario(20, 20, $bytes, 0));

        $this->assertGreaterThan(100, $bw['down_pct']);
    }

    public function test_an_unmapped_circuit_says_so_instead_of_reporting_zero(): void
    {
        // Silence must not look like idleness — 0% would read as "no traffic".
        $site = Site::factory()->create();
        $circuit = Circuit::factory()->create([
            'site_id' => $site->id, 'wan_interface' => null,
            'contract_down_mbps' => 20, 'contract_up_mbps' => 20,
        ]);

        $bw = (new CircuitBandwidth)->for($circuit);

        $this->assertFalse($bw['mapped']);
        $this->assertNull($bw['down_pct']);
        $this->assertSame('no WAN port mapped', $bw['reason']);
    }

    public function test_stale_samples_are_refused_rather_than_read_as_idle(): void
    {
        // A dead interface poller must never make a busy circuit look quiet.
        $site = Site::factory()->create();
        $edge = Device::factory()->create(['site_id' => $site->id, 'role' => 'edgeconnect']);
        $iface = DeviceInterface::factory()->create(['device_id' => $edge->id, 'if_name' => 'wan0']);
        InterfaceMetricHistory::create([
            'device_interface_id' => $iface->id, 'recorded_at' => now()->subHours(3),
            'status' => 'up', 'in_octets_delta' => 999_999, 'out_octets_delta' => 999_999,
            'in_discards_delta' => 0, 'out_discards_delta' => 0,
        ]);
        $circuit = Circuit::factory()->create([
            'site_id' => $site->id, 'wan_interface' => 'wan0',
            'contract_down_mbps' => 20, 'contract_up_mbps' => 20,
        ]);

        $bw = (new CircuitBandwidth)->for($circuit);

        $this->assertFalse($bw['mapped']);
        $this->assertNull($bw['down_mbps']);
    }

    public function test_a_circuit_with_no_contract_speed_cannot_be_scored(): void
    {
        $site = Site::factory()->create();
        $edge = Device::factory()->create(['site_id' => $site->id, 'role' => 'edgeconnect']);
        DeviceInterface::factory()->create(['device_id' => $edge->id, 'if_name' => 'wan0']);
        $circuit = Circuit::factory()->create([
            'site_id' => $site->id, 'wan_interface' => 'wan0',
            'contract_down_mbps' => null, 'contract_up_mbps' => null,
        ]);

        $bw = (new CircuitBandwidth)->for($circuit);

        $this->assertFalse($bw['mapped']);
        $this->assertSame('no contract speed on file', $bw['reason']);
    }

    public function test_the_wan_port_is_inferred_from_the_gateway_when_not_recorded(): void
    {
        // 36 of Massey's circuits never had wan_interface filled in. The appliance
        // already knows which port each gateway sits behind, so the mapping is derived
        // rather than typed — no polling change fixes a field nobody entered.
        $site = Site::factory()->create();
        $edge = Device::factory()->create(['site_id' => $site->id, 'role' => 'edgeconnect']);
        $iface = DeviceInterface::factory()->create(['device_id' => $edge->id, 'if_name' => 'wan1']);
        \App\Models\DeviceNextHop::create([
            'device_id' => $edge->id, 'ip_address' => '64.159.162.181', 'interface' => 'wan1', 'status' => 'up',
        ]);

        $now = now();
        foreach ([[300, 0], [0, (int) (9 * 1_000_000 / 8 * 300)]] as $i => [$ago, $inBytes]) {
            \App\Models\InterfaceMetricHistory::create([
                'device_interface_id' => $iface->id,
                'recorded_at' => $now->copy()->subSeconds($ago),
                'status' => 'up', 'in_octets_delta' => $inBytes, 'out_octets_delta' => 0,
                'in_discards_delta' => 0, 'out_discards_delta' => 0,
            ]);
        }

        $circuit = Circuit::factory()->create([
            'site_id' => $site->id,
            'wan_interface' => null,                  // never entered
            'gateway_ip' => '64.159.162.181',         // but the appliance knows this hop
            'contract_down_mbps' => 100, 'contract_up_mbps' => 10,
        ]);

        $bw = (new CircuitBandwidth)->for($circuit);

        $this->assertTrue($bw['mapped'], 'the port should be inferred from the gateway');
        $this->assertSame('wan1', $bw['wan_interface']);
        $this->assertTrue($bw['inferred'], 'derived mappings must be flagged as derived');
        $this->assertSame(9.0, $bw['down_mbps']);
    }

    public function test_a_circuit_with_neither_port_nor_known_gateway_still_says_so(): void
    {
        $site = Site::factory()->create();
        $edge = Device::factory()->create(['site_id' => $site->id, 'role' => 'edgeconnect']);
        DeviceInterface::factory()->create(['device_id' => $edge->id, 'if_name' => 'wan0']);
        $circuit = Circuit::factory()->create([
            'site_id' => $site->id, 'wan_interface' => null, 'gateway_ip' => '10.9.9.9',
            'contract_down_mbps' => 100, 'contract_up_mbps' => 10,
        ]);

        $this->assertSame('no WAN port mapped', (new CircuitBandwidth)->for($circuit)['reason']);
    }

    public function test_the_whole_list_is_measured_without_a_query_per_circuit(): void
    {
        // The circuits page loads 250 rows; per-circuit lookups would be hundreds of
        // queries. Batched resolution must stay flat as the list grows.
        $site = Site::factory()->create();
        $edge = Device::factory()->create(['site_id' => $site->id, 'role' => 'edgeconnect']);
        DeviceInterface::factory()->create(['device_id' => $edge->id, 'if_name' => 'wan0']);
        $circuits = Circuit::factory()->count(25)->create([
            'site_id' => $site->id, 'wan_interface' => 'wan0', 'contract_down_mbps' => 100, 'contract_up_mbps' => 10,
        ]);

        \DB::enableQueryLog();
        $out = (new CircuitBandwidth)->forMany($circuits);
        $queries = count(\DB::getQueryLog());
        \DB::disableQueryLog();

        $this->assertCount(25, $out);
        $this->assertLessThanOrEqual(4, $queries, "expected a fixed number of queries, ran {$queries}");
    }

    public function test_live_bandwidth_refuses_a_circuit_it_cannot_measure(): void
    {
        // The live probe reads counters off the appliance. With no port mapped there
        // is nothing to read, and it must say so rather than return a zero rate that
        // the graph would draw as a flat idle line.
        $user = \App\Models\User::factory()->create(['role' => 'admin']);
        $site = Site::factory()->create();
        $circuit = Circuit::factory()->create([
            'site_id' => $site->id, 'wan_interface' => null,
            'contract_down_mbps' => 20, 'contract_up_mbps' => 20,
        ]);

        $this->actingAs($user)
            ->postJson("/api/circuits/{$circuit->id}/bandwidth-live")
            ->assertOk()
            ->assertJson(['ok' => false, 'reason' => 'no WAN port mapped']);
    }

    public function test_live_bandwidth_refuses_an_appliance_with_no_snmp_credentials(): void
    {
        // Mapped, polled, but unreachable by SNMP — again an honest refusal, not a zero.
        $user = \App\Models\User::factory()->create(['role' => 'admin']);
        $site = Site::factory()->create();
        $edge = Device::factory()->create([
            'site_id' => $site->id, 'role' => 'edgeconnect',
            'snmp_community' => null, 'snmp_v3_username' => null,
        ]);
        $iface = DeviceInterface::factory()->create([
            'device_id' => $edge->id, 'if_name' => 'wan0', 'if_index' => 3,
        ]);
        $now = now();
        foreach ([[300, 0], [0, 1_000_000]] as [$ago, $bytes]) {
            InterfaceMetricHistory::create([
                'device_interface_id' => $iface->id, 'recorded_at' => $now->copy()->subSeconds($ago),
                'status' => 'up', 'in_octets_delta' => $bytes, 'out_octets_delta' => 0,
                'in_discards_delta' => 0, 'out_discards_delta' => 0,
            ]);
        }
        $circuit = Circuit::factory()->create([
            'site_id' => $site->id, 'wan_interface' => 'wan0',
            'contract_down_mbps' => 20, 'contract_up_mbps' => 20,
        ]);

        $this->actingAs($user)
            ->postJson("/api/circuits/{$circuit->id}/bandwidth-live")
            ->assertOk()
            ->assertJson(['ok' => false, 'reason' => 'no SNMP credentials on the appliance']);
    }
}
