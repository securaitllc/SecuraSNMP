<?php

namespace Tests\Unit;

use App\Models\Circuit;
use App\Models\DeviceNextHop;
use App\Services\AlarmCircuitResolver;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

/**
 * Correlation is driven by the real #024 Boca alarm strings pulled from prod:
 *   ec:196625:gw:63.212.186.49                     → Lumen wan1
 *   ec:262189:...Port wan1 ... label DIA1          → Lumen wan1
 *   ec:65537:Tunnel  (rollup) / ec:262153:System   → site-wide (null)
 */
class AlarmCircuitResolverTest extends TestCase
{
    private AlarmCircuitResolver $r;

    private Collection $circuits;

    private Collection $nextHops;

    protected function setUp(): void
    {
        parent::setUp();
        $this->r = new AlarmCircuitResolver;

        $lumen = new Circuit(['isp_name' => 'Lumen', 'wan_interface' => 'wan1', 'gateway_ip' => '63.212.186.49']);
        $lumen->id = 56;
        $att = new Circuit(['isp_name' => 'AT&T', 'wan_interface' => 'wan0', 'gateway_ip' => '23.127.131.134']);
        $att->id = 175;
        $this->circuits = collect([$lumen, $att]);
        $this->nextHops = collect([new DeviceNextHop(['ip_address' => '63.212.186.49', 'interface' => 'wan1'])]);
    }

    public function test_next_hop_gateway_maps_to_its_circuit(): void
    {
        $c = $this->r->resolve('ec:196625:gw:63.212.186.49', 'Next-hop unreachable — gw:63.212.186.49', $this->circuits, $this->nextHops);
        $this->assertSame(56, $c?->id);
    }

    public function test_ip_sla_port_wan_maps_to_its_circuit(): void
    {
        $desc = 'An IP SLA monitor is in the Down state — Ping for sp-ipsla.silverpeak.cloud,8.8.8.8 on Port wan1 tunnel N/A label DIA1';
        $c = $this->r->resolve('ec:262189:Ping for sp-ipsla.silverpeak.cloud,8.8.8.8 on Port wan1 tunnel N/A label DIA1', $desc, $this->circuits, $this->nextHops);
        $this->assertSame(56, $c?->id);
    }

    public function test_wan0_alarm_does_not_match_wan1_circuit(): void
    {
        $c = $this->r->resolve('ec:196625:gw:23.127.131.134', 'Next-hop unreachable — gw:23.127.131.134', $this->circuits, $this->nextHops);
        $this->assertSame(175, $c?->id); // AT&T, not Lumen
    }

    public function test_tunnel_rollup_and_system_are_site_wide(): void
    {
        $this->assertNull($this->r->resolve('ec:65537:Tunnel', 'Many tunnels to remote sites are down', $this->circuits, $this->nextHops));
        $this->assertNull($this->r->resolve('ec:262153:System', 'All NTP servers are unreachable — System', $this->circuits, $this->nextHops));
    }

    public function test_gateway_resolves_via_nexthop_table_when_circuit_lacks_the_ip(): void
    {
        // Circuit knows only its wan_interface; the gateway IP lives in the next-hop table.
        $c = new Circuit(['isp_name' => 'Lumen', 'wan_interface' => 'wan1', 'gateway_ip' => null]);
        $c->id = 56;
        $got = $this->r->resolve('ec:196625:gw:63.212.186.49', 'Next-hop unreachable — gw:63.212.186.49', collect([$c]), $this->nextHops);
        $this->assertSame(56, $got?->id);
    }

    public function test_source_extraction_keeps_the_colon_in_gw(): void
    {
        $this->assertSame('gw:63.212.186.49', AlarmCircuitResolver::sourceOf('ec:196625:gw:63.212.186.49'));
        $this->assertSame('Tunnel', AlarmCircuitResolver::sourceOf('ec:65537:Tunnel'));
    }
}
