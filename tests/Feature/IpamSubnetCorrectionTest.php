<?php

namespace Tests\Feature;

use App\Models\ArpEntry;
use App\Models\Device;
use App\Models\IpPrefix;
use App\Models\Site;
use App\Models\User;
use App\Services\Ipam;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * A LAN's mask is the one thing on this page that cannot be discovered.
 *
 * Every other figure in IPAM is observed — ARP, the switch FDB, device addresses. ARP
 * reports who answered and nothing else, so the mask is assumed: each private address
 * is bucketed into the /24 it falls in, which is right for a service centre and wrong
 * for 10.11.0.0, which is a /23. It showed as two half-empty /24s, each claiming 254
 * usable addresses against a real 510, with hosts in the upper half reading as a
 * different LAN.
 *
 * No amount of polling closes that: the mask lives in a device's configuration. So an
 * operator writes it down, and it governs grouping and capacity from then on.
 */
class IpamSubnetCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Device $gateway;

    private function arp(string $ip, string $mac): void
    {
        ArpEntry::create([
            'device_id' => $this->gateway->id, 'site_id' => $this->site->id,
            'ip' => $ip, 'mac' => $mac,
            'first_seen_at' => now()->subDay(), 'last_seen_at' => now(),
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->site = Site::factory()->create(['name' => 'Orlando HQ']);
        $this->gateway = Device::factory()->create(['site_id' => $this->site->id, 'ip_address' => '10.11.0.1']);

        // One /23 worth of hosts: two in the lower half, two in the upper.
        foreach (['10.11.0.10', '10.11.0.11', '10.11.1.10', '10.11.1.11'] as $i => $ip) {
            $this->arp($ip, sprintf('AA:BB:CC:00:00:%02d', $i));
        }
    }

    /** @return array<int, array<string, mixed>> the site's LAN ranges */
    private function lanRanges(): array
    {
        Cache::flush();
        $out = (new Ipam)->ranges($this->site->id);
        $site = $out['sites'][0] ?? ['ranges' => []];

        return array_values(array_filter($site['ranges'], fn ($r) => $r['kind'] === 'lan'));
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

    public function test_without_a_correction_a_slash_23_reads_as_two_slash_24s(): void
    {
        // The behaviour being corrected. Pinned so the fix is visibly a change.
        $cidrs = array_column($this->lanRanges(), 'cidr');

        sort($cidrs);
        $this->assertSame(['10.11.0.0/24', '10.11.1.0/24'], $cidrs);
    }

    public function test_a_recorded_prefix_groups_the_whole_range(): void
    {
        IpPrefix::create(['cidr' => '10.11.0.0/23', 'site_id' => $this->site->id]);

        $ranges = $this->lanRanges();

        $this->assertCount(1, $ranges, 'one LAN, not two halves of one');
        $this->assertSame('10.11.0.0/23', $ranges[0]['cidr']);
        $this->assertSame(5, $ranges[0]['seen'], 'all four ARP hosts plus the device, counted once');
    }

    public function test_capacity_is_measured_against_the_real_size(): void
    {
        IpPrefix::create(['cidr' => '10.11.0.0/23', 'site_id' => $this->site->id]);

        $range = $this->lanRanges()[0];

        $this->assertSame(510, $range['usable'], 'a /23 is 510 usable, not the hard-coded 254');
        $this->assertSame(1, $range['pct'], '5 of 510 — the old denominator would have said 2%');
    }

    public function test_a_more_specific_correction_wins(): void
    {
        // A /28 recorded inside the /23 describes its own addresses better.
        IpPrefix::create(['cidr' => '10.11.0.0/23']);
        IpPrefix::create(['cidr' => '10.11.1.0/28']);

        $cidrs = array_column($this->lanRanges(), 'cidr');

        sort($cidrs);
        $this->assertSame(['10.11.0.0/23', '10.11.1.0/28'], $cidrs);
    }

    public function test_ranges_with_no_correction_keep_the_assumed_slash_24(): void
    {
        // The control: this must not change how the other 130 sites are grouped.
        $this->arp('10.200.77.5', 'AA:BB:CC:00:00:99');
        IpPrefix::create(['cidr' => '10.11.0.0/23']);

        $cidrs = array_column($this->lanRanges(), 'cidr');

        $this->assertContains('10.200.77.0/24', $cidrs);
    }

    public function test_an_admin_can_record_a_correction(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/ipam/prefixes', ['cidr' => '10.11.0.0/23', 'site_id' => $this->site->id])
            ->assertCreated();

        $this->assertDatabaseHas('ip_prefixes', ['cidr' => '10.11.0.0/23']);
    }

    public function test_a_host_address_is_stored_as_its_network(): void
    {
        // 10.11.0.5/23 and 10.11.1.9/23 are the same block. Stored as typed they would
        // be two records of one prefix, and the unique index would not catch it.
        $this->actingAs($this->admin())
            ->postJson('/api/ipam/prefixes', ['cidr' => '10.11.1.9/23'])
            ->assertCreated();

        $this->assertDatabaseHas('ip_prefixes', ['cidr' => '10.11.0.0/23']);
    }

    public function test_the_same_network_cannot_be_recorded_twice(): void
    {
        IpPrefix::create(['cidr' => '10.11.0.0/23']);

        $this->actingAs($this->admin())
            ->postJson('/api/ipam/prefixes', ['cidr' => '10.11.1.9/23'])
            ->assertStatus(422);
    }

    public function test_a_single_address_is_refused(): void
    {
        // That is a reservation, which already has its own record with a purpose.
        $this->actingAs($this->admin())
            ->postJson('/api/ipam/prefixes', ['cidr' => '10.11.0.5/32'])
            ->assertStatus(422);
    }

    public function test_nonsense_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/ipam/prefixes', ['cidr' => 'not-a-network'])
            ->assertStatus(422);
    }

    public function test_a_viewer_cannot_change_a_mask(): void
    {
        // Changing a mask regroups every address inside it and re-bases capacity.
        $this->actingAs(User::factory()->create(['role' => 'viewer', 'is_active' => true]))
            ->postJson('/api/ipam/prefixes', ['cidr' => '10.11.0.0/23'])
            ->assertForbidden();
    }

    public function test_removing_the_correction_puts_the_slash_24s_back(): void
    {
        $prefix = IpPrefix::create(['cidr' => '10.11.0.0/23']);
        $this->assertCount(1, $this->lanRanges());

        $this->actingAs($this->admin())
            ->deleteJson("/api/ipam/prefixes/{$prefix->id}")
            ->assertOk();

        $this->assertCount(2, $this->lanRanges(), 'the correction was the only thing holding them together');
    }
}
