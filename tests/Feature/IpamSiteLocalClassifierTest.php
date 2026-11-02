<?php

namespace Tests\Feature;

use App\Models\ArpEntry;
use App\Models\Device;
use App\Models\Site;
use App\Services\Ipam;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * "Seen at several sites" is not the same as "belongs to several sites".
 *
 * 10.11.0.0/23 is HQ's corporate LAN — 271 hosts on the core switches' irb.4. Eleven
 * other gateways each held a single stray ARP entry for an address in it, resolved
 * across the tunnel. The site-count test counted those strays as evidence of a
 * locally-significant block, which exempted the range from the ownership collapse and
 * scattered HQ's LAN across twelve sites: #007 Daytona appeared to own a /23 it has
 * one address in.
 *
 * A block that is genuinely reused per site spreads evenly — 192.168.255.0/24 is one
 * or two addresses at each of 127 sites, and no site has any equipment addressed in
 * it. A routed range has an owner: equipment inside it, or the overwhelming majority
 * of its addresses, at one site.
 */
class IpamSiteLocalClassifierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function siteWithArp(string $name, array $ips, bool $deviceInside = false): Site
    {
        $site = Site::factory()->create(['name' => $name]);
        $gw = Device::factory()->create([
            'site_id' => $site->id, 'role' => 'edgeconnect',
            'ip_address' => $deviceInside ? $ips[0] : '172.31.'.$site->id.'.1',
        ]);
        foreach ($ips as $i => $ip) {
            ArpEntry::create([
                'device_id' => $gw->id, 'site_id' => $site->id, 'ip' => $ip,
                'mac' => sprintf('AA:BB:%02X:%02X:00:%02X', $site->id, $i >> 8, $i & 0xFF),
                'interface' => 'lan0',
                'first_seen_at' => now()->subDay(), 'last_seen_at' => now(),
            ]);
        }

        return $site;
    }

    /** @return array<string, array<string, mixed>> cidr => the row for that site */
    private function rangesFor(Site $site): array
    {
        Cache::flush();
        $out = (new Ipam)->ranges($site->id);

        return collect($out['sites'][0]['ranges'] ?? [])->keyBy('cidr')->all();
    }

    public function test_one_site_with_the_hosts_owns_the_range(): void
    {
        // HQ holds the LAN; eleven others hold a stray apiece.
        $hq = $this->siteWithArp('#893 HQ Orlando FL', array_map(fn ($n) => "10.11.0.{$n}", range(10, 120)), true);
        $strays = [];
        for ($i = 1; $i <= 11; $i++) {
            $strays[] = $this->siteWithArp("#0{$i} Branch", ['10.11.0.'.(200 + $i)]);
        }

        $this->assertArrayHasKey('10.11.0.0/24', $this->rangesFor($hq), 'HQ keeps its own LAN');

        foreach ($strays as $branch) {
            $this->assertArrayNotHasKey(
                '10.11.0.0/24',
                $this->rangesFor($branch),
                "{$branch->name} has one address in HQ's LAN — that is not a range it owns",
            );
        }
    }

    public function test_a_block_reused_evenly_everywhere_stays_site_local(): void
    {
        // The control, and the case the exemption exists for: every site has its own
        // cable modem at the same address, and none owns the others.
        $sites = [];
        for ($i = 1; $i <= 8; $i++) {
            $sites[] = $this->siteWithArp("#1{$i} Branch", ['172.20.50.1']);
        }

        foreach ($sites as $site) {
            $row = $this->rangesFor($site)['172.20.50.0/24'] ?? null;
            $this->assertNotNull($row, "{$site->name} lost its own copy of a site-local block");
            $this->assertSame('site-local', $row['scope']);
            $this->assertSame(1, $row['seen'], 'its own host only');
        }
    }

    public function test_a_declared_192_168_block_is_always_site_local(): void
    {
        // Declared by prefix, regardless of shape: these are never routed here.
        $sites = [];
        for ($i = 1; $i <= 8; $i++) {
            $sites[] = $this->siteWithArp("#2{$i} Branch", ['192.168.100.1']);
        }

        foreach ($sites as $site) {
            $this->assertSame('site-local', $this->rangesFor($site)['192.168.100.0/24']['scope']);
        }
    }

    public function test_a_range_at_a_handful_of_sites_is_still_shared_not_local(): void
    {
        // Co-located service centres genuinely share one LAN. Under the threshold, the
        // existing behaviour is unchanged.
        $a = $this->siteWithArp('#041', ['10.200.56.10', '10.200.56.11'], true);
        $this->siteWithArp('#056', ['10.200.56.12']);
        $this->siteWithArp('#209', ['10.200.56.13']);

        $row = $this->rangesFor($a)['10.200.56.0/24'] ?? null;

        $this->assertNotNull($row);
        $this->assertSame('routed', $row['scope']);
    }

    public function test_the_classifier_itself(): void
    {
        // Direct, so the rule is readable without building a fleet.
        $even = array_fill(0, 10, 2);
        $dominated = array_merge([271], array_fill(0, 11, 1));

        $this->assertTrue(Ipam::isSiteLocalRange('10.50.0.0/24', $even));
        $this->assertFalse(Ipam::isSiteLocalRange('10.11.0.0/23', $dominated), 'one site holds almost all of it');
        $this->assertFalse(Ipam::isSiteLocalRange('10.50.0.0/24', $even, [7]), 'a site has equipment inside it');
        $this->assertTrue(Ipam::isSiteLocalRange('192.168.1.0/24', $dominated), 'declared blocks are unconditional');
        $this->assertFalse(Ipam::isSiteLocalRange('10.50.0.0/24', [5, 5]), 'two sites is sharing, not reuse');
    }
}
