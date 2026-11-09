<?php

namespace Tests\Feature;

use App\Models\ArpEntry;
use App\Models\Device;
use App\Models\Site;
use App\Services\Ipam;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One MAC answering for half a /24 is not 151 hosts.
 *
 * Massey's Dell fleet duplicates MACs: D4:A2:CD:4E:99:F4 answers on 151 addresses
 * of 10.200.77.0/24, every one of them learned on a SINGLE access port. Counting
 * those as occupancy painted that range — and 10.200.54.0/24 — amber "Filling up"
 * at 81% and 72% while their identifiable occupancy is 54 of 254.
 *
 * Colour is severity in this app, and "we cannot identify 151 addresses" is a
 * data-quality problem, not a capacity one. The unverified count is still
 * reported in full, so a range thick with them is never read as empty either.
 */
class IpamUnverifiedOccupancyTest extends TestCase
{
    use RefreshDatabase;

    private function range(int $realHosts, int $sharedMacHosts): array
    {
        $site = Site::factory()->create();
        $poller = Device::factory()->create(['site_id' => $site->id, 'ip_address' => '172.16.0.1']);

        $n = 1;
        for ($i = 0; $i < $realHosts; $i++, $n++) {
            ArpEntry::create([
                'device_id' => $poller->id, 'site_id' => $site->id,
                'ip' => "10.200.77.{$n}", 'mac' => sprintf('aa:bb:cc:00:%02x:%02x', intdiv($i, 256), $i % 256),
                'first_seen_at' => now(), 'last_seen_at' => now(),
            ]);
        }
        for ($i = 0; $i < $sharedMacHosts; $i++, $n++) {
            ArpEntry::create([
                'device_id' => $poller->id, 'site_id' => $site->id,
                'ip' => "10.200.77.{$n}", 'mac' => 'd4:a2:cd:4e:99:f4',
                'first_seen_at' => now(), 'last_seen_at' => now(),
            ]);
        }

        foreach ((new Ipam)->ranges()['sites'] as $siteRow) {
            foreach ($siteRow['ranges'] as $r) {
                if ($r['cidr'] === '10.200.77.0/24') {
                    return $r;
                }
            }
        }

        return [];
    }

    public function test_a_duplicate_mac_does_not_fill_a_range(): void
    {
        $r = $this->range(realHosts: 54, sharedMacHosts: 151);

        $this->assertSame(54, $r['seen'], 'capacity is what can be identified');
        $this->assertSame(205, $r['answered'], 'everything that replied is still reported');
        $this->assertSame(151, $r['unverified']);
        $this->assertSame(21, $r['pct']);
        $this->assertSame('ok', $r['state'], 'a duplicate MAC is a data-quality problem, not a full subnet');
        $this->assertStringContainsString('no host identified', $r['note']);
    }

    public function test_a_genuinely_full_range_is_still_flagged(): void
    {
        // 220 distinct MACs, no duplicate-MAC artefact: this one really is filling.
        $r = $this->range(realHosts: 220, sharedMacHosts: 0);

        $this->assertSame(220, $r['seen']);
        $this->assertSame(0, $r['unverified']);
        $this->assertSame('critical', $r['state']);
    }

    public function test_the_range_detail_does_not_spend_unidentified_addresses_out_of_free(): void
    {
        // The ranges LIST was fixed to read 54 of 254; the detail panel was not, so it
        // still showed "49 free" of 254 and read as almost full. Same evidence, two
        // answers. Occupancy is what is accounted for, and the unidentified addresses
        // are reported as their own bucket with the ceiling they imply.
        $this->range(realHosts: 54, sharedMacHosts: 151);

        $s = (new Ipam)->detail('10.200.77.0/24')['summary'];

        $this->assertSame(151, $s['unverified']);
        $this->assertSame(54, $s['confirmed'], 'how full the range is = what is accounted for');
        $this->assertSame(49, $s['free'], 'still not offered for allocation — something produced those entries');
        $this->assertSame(200, $s['free_max'], 'but the ceiling has to be visible, or 49 reads as almost full');
        $this->assertSame(
            $s['usable'],
            $s['confirmed'] + $s['unverified'] + $s['free'],
            'the buckets must still partition the range',
        );
    }


    public function test_every_view_of_a_range_agrees_on_how_full_it_is(): void
    {
        // The bug class, not the bug: "how full is this range" was implemented three
        // times — the range list, the range detail and the supernet map — so the same
        // 10.200.77.0/24 read 21% green in one, "49 free of 254" in another and amber
        // "busy" at 81% in the third. Fixing one never fixed the others, and the map
        // was still painting duplicate-MAC ranges orange two releases later.
        //
        // They now share one definition. This test fails the moment any view starts
        // deciding occupancy for itself again.
        $this->range(realHosts: 54, sharedMacHosts: 151);

        $list = collect((new Ipam)->ranges()['sites'])
            ->flatMap(fn ($s) => $s['ranges'])
            ->firstWhere('cidr', '10.200.77.0/24');
        $detail = (new Ipam)->detail('10.200.77.0/24')['summary'];
        $block = collect((new Ipam)->space()['blocks'])->firstWhere('cidr', '10.200.77.0/24');

        $this->assertSame(54, $list['seen'], 'range list');
        $this->assertSame(54, $detail['confirmed'], 'range detail');
        $this->assertSame(54, $block['seen'], 'supernet map');

        $this->assertSame(151, $list['unverified']);
        $this->assertSame(151, $detail['unverified']);
        $this->assertSame(151, $block['unverified']);

        $this->assertSame(21, $list['pct']);
        $this->assertSame(21, $block['pct']);
        $this->assertSame('used', $block['state'], 'a duplicate MAC must never paint a block busy');
    }

    public function test_a_block_nothing_can_identify_is_never_offered_as_free(): void
    {
        // The opposite error, and the more dangerous one: discounting the unidentified
        // addresses must not turn the block green and invite a new site into it.
        $this->range(realHosts: 0, sharedMacHosts: 151);

        $block = collect((new Ipam)->space()['blocks'])->firstWhere('cidr', '10.200.77.0/24');

        $this->assertSame(0, $block['seen'], 'nothing here can be confirmed');
        $this->assertSame(151, $block['unverified']);
        $this->assertNotSame('free', $block['state'], 'something replied — it is not allocatable space');
    }

}
