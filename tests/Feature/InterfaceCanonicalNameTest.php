<?php

namespace Tests\Feature;

use App\Models\Circuit;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\InterfaceMetricHistory;
use App\Models\Site;
use App\Models\User;
use App\Services\CircuitBandwidth;
use App\Services\InterfacePoller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A port has two names and they are not always the same word.
 *
 * On a switch, ifDescr IS the port name (ge-0/0/11). On EdgeConnect it is a
 * free-text comment: the appliance at Massey site 868 reports its wan0 as "BB,"
 * — the label of the broadband circuit plugged into it — and its LAN
 * sub-interfaces as ",Data" / ",Voice". The real names live only in ifName.
 *
 * The circuit records the name an engineer says out loud (wan0), so anything that
 * looks a port up BY NAME has to know both.
 */
class InterfaceCanonicalNameTest extends TestCase
{
    use RefreshDatabase;

    /** ifDescr / ifName pairs as the appliance at 868 actually answers them. */
    private function walker(): callable
    {
        return function (Device $device, string $oid): string {
            return match ($oid) {
                '.1.3.6.1.2.1.2.2.1.2' => "iso.3.6.1.2.1.2.2.1.2.8 = STRING: BB,\niso.3.6.1.2.1.2.2.1.2.9 = STRING: \n",
                '.1.3.6.1.2.1.31.1.1.1.1' => "iso.3.6.1.2.1.31.1.1.1.1.8 = STRING: wan0\niso.3.6.1.2.1.31.1.1.1.1.9 = STRING: wan1\n",
                '.1.3.6.1.2.1.2.2.1.8', '.1.3.6.1.2.1.2.2.1.7' => "iso.3.6.1.2.1.2.2.1.8.8 = INTEGER: up(1)\niso.3.6.1.2.1.2.2.1.8.9 = INTEGER: up(1)\n",
                default => '',
            };
        };
    }

    public function test_the_poller_keeps_the_port_name_even_when_a_label_is_displayed(): void
    {
        $edge = Device::factory()->create(['role' => 'edgeconnect']);

        (new InterfacePoller($this->walker()))->poll($edge);

        $wan0 = DeviceInterface::where('device_id', $edge->id)->where('if_index', 8)->firstOrFail();
        $this->assertSame('BB,', $wan0->if_name, 'the label stays on display — nothing keyed on if_name moves');
        $this->assertSame('wan0', $wan0->if_canonical_name, 'and the real port name is no longer thrown away');

        // A port with no label was never broken: ifName was already the fallback.
        $wan1 = DeviceInterface::where('device_id', $edge->id)->where('if_index', 9)->firstOrFail();
        $this->assertSame('wan1', $wan1->if_name);
        $this->assertSame('wan1', $wan1->if_canonical_name);
    }

    public function test_a_partial_walk_does_not_blank_a_known_port_name(): void
    {
        $edge = Device::factory()->create(['role' => 'edgeconnect']);
        (new InterfacePoller($this->walker()))->poll($edge);

        // Second sweep, ifName came back empty (this gear drops SNMP responses
        // under memory pressure). The name must survive.
        $partial = fn (Device $d, string $oid) => $oid === '.1.3.6.1.2.1.31.1.1.1.1'
            ? ''
            : ($this->walker())($d, $oid);
        (new InterfacePoller($partial))->poll($edge);

        $this->assertSame('wan0', DeviceInterface::where('device_id', $edge->id)->where('if_index', 8)->value('if_canonical_name'));
    }

    /** The bug as reported: #868 passes traffic on wan0 and the app showed nothing. */
    public function test_a_circuit_finds_its_port_by_the_real_name_behind_the_label(): void
    {
        $circuit = $this->circuitOn('BB,', 'wan0');

        $bw = (new CircuitBandwidth)->for($circuit);

        $this->assertTrue($bw['mapped'], $bw['reason'] ?? '');
        $this->assertSame(8.0, $bw['down_mbps']);
    }

    public function test_a_port_merely_labelled_wan0_never_shadows_the_real_wan0(): void
    {
        $circuit = $this->circuitOn('BB,', 'wan0');
        $edgeId = Device::where('site_id', $circuit->site_id)->value('id');

        // Someone typed "wan0" into the comment field of a different port.
        DeviceInterface::factory()->create([
            'device_id' => $edgeId, 'if_index' => 99,
            'if_name' => 'wan0', 'if_canonical_name' => 'lan1',
        ]);

        $bw = (new CircuitBandwidth)->for($circuit);

        $this->assertTrue($bw['mapped']);
        $this->assertSame(8.0, $bw['down_mbps'], 'the labelled decoy carries no traffic — matching it would read 0');
    }

    public function test_an_unresolvable_port_names_the_ports_that_do_exist(): void
    {
        $circuit = $this->circuitOn('BB,', 'wan0');
        $circuit->update(['wan_interface' => 'wan3']);

        $bw = (new CircuitBandwidth)->for($circuit);

        $this->assertFalse($bw['mapped']);
        $this->assertStringContainsString('wan3 not found', $bw['reason']);
        $this->assertStringContainsString('wan0', $bw['reason'], 'a dead end is useless; name what it has');
    }

    public function test_the_wan_port_picker_offers_the_real_name_and_shows_the_label(): void
    {
        $circuit = $this->circuitOn('BB,', 'wan0');

        $ports = $this->actingAs(User::factory()->create())
            ->getJson("/api/sites/{$circuit->site_id}/wan-interfaces")
            ->assertOk()->json('data');

        $this->assertSame('wan0', $ports[0]['if_name'], 'picking "BB," would store a name no lookup resolves');
        $this->assertSame('BB,', $ports[0]['label']);
    }

    /** One edge appliance, one WAN port at 8 Mbps down, one circuit pointed at wan0. */
    private function circuitOn(string $ifName, string $canonical): Circuit
    {
        $site = Site::factory()->create();
        $edge = Device::factory()->create(['site_id' => $site->id, 'role' => 'edgeconnect']);
        $iface = DeviceInterface::factory()->create([
            'device_id' => $edge->id, 'if_index' => 8,
            'if_name' => $ifName, 'if_canonical_name' => $canonical,
        ]);

        $now = now();
        foreach ([[300, 0], [0, (int) (8 * 1_000_000 / 8 * 300)]] as [$ago, $bytes]) {
            InterfaceMetricHistory::create([
                'device_interface_id' => $iface->id, 'recorded_at' => $now->copy()->subSeconds($ago),
                'status' => 'up', 'in_octets_delta' => $bytes, 'out_octets_delta' => 0,
                'in_discards_delta' => 0, 'out_discards_delta' => 0,
            ]);
        }

        return Circuit::factory()->create([
            'site_id' => $site->id, 'wan_interface' => 'wan0',
            'contract_down_mbps' => 100, 'contract_up_mbps' => 10,
        ]);
    }
}
