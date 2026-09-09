<?php

namespace Tests\Feature;

use App\Models\Circuit;
use App\Models\Device;
use App\Models\IpReservation;
use App\Models\Site;
use App\Models\User;
use App\Services\Ipam;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Addresses recorded by hand.
 *
 * A firewall's NAT pools and VIPs consume real public space and appear in no SNMP
 * table — at HQ, 66 usable public addresses with only 12 visible to ipAddrTable. The
 * rest exist solely in firewall policy, so they have to be written down, and once
 * written down they must never be offered as free.
 */
class IpReservationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_an_admin_can_record_an_address_the_firewall_uses(): void
    {
        $site = Site::factory()->create();

        $this->actingAs($this->admin())->postJson('/api/ipam/reservations', [
            'ip' => '131.148.15.220',
            'prefix_len' => 27,
            'site_id' => $site->id,
            'label' => 'All Default - EXT_Spectrum',
            'purpose' => 'nat',
            'assignment' => 'static',
            'note' => 'Source NAT pool, overload',
        ])->assertCreated();

        $row = IpReservation::where('ip', '131.148.15.220')->first();
        $this->assertSame('nat', $row->purpose);
        $this->assertSame('All Default - EXT_Spectrum', $row->label);
    }

    public function test_a_recorded_address_is_never_offered_as_free(): void
    {
        // The whole point. Without the record, 131.148.15.220 reads as available and
        // the next person allocates straight onto a live NAT pool.
        $site = Site::factory()->create();
        Circuit::factory()->create(['site_id' => $site->id, 'subnet' => '131.148.15.192/27']);

        $before = collect((new Ipam)->detail('131.148.15.192/27')['rows'])->firstWhere('ip', '131.148.15.220');
        $this->assertSame('free', $before['state'], 'unrecorded, it looks available');

        IpReservation::create([
            'ip' => '131.148.15.220', 'purpose' => 'nat', 'assignment' => 'static',
            'label' => 'All Default - EXT_Spectrum',
        ]);

        $after = collect((new Ipam)->detail('131.148.15.192/27')['rows'])->firstWhere('ip', '131.148.15.220');
        $this->assertSame('reserved', $after['state']);
        $this->assertSame('All Default - EXT_Spectrum', $after['hostname']);
        $this->assertSame('nat', $after['reservation']['purpose']);
    }

    public function test_recording_the_same_address_twice_is_refused_with_a_clear_message(): void
    {
        // Two people reserving one address is exactly the collision this prevents.
        IpReservation::create(['ip' => '4.18.134.190', 'purpose' => 'nat', 'assignment' => 'static']);

        $this->actingAs($this->admin())->postJson('/api/ipam/reservations', [
            'ip' => '4.18.134.190', 'purpose' => 'nat', 'assignment' => 'static',
        ])->assertStatus(422)->assertJsonPath('errors.ip.0', 'This address is already recorded.');
    }

    public function test_a_reservation_on_an_address_the_wire_already_shows_annotates_it(): void
    {
        // 131.148.15.194 is BOTH a firewall interface address and a NAT pool. It must
        // stay one row carrying both facts, not appear twice.
        $site = Site::factory()->create();
        $fw = Device::factory()->create([
            'site_id' => $site->id, 'role' => 'firewall', 'name' => 'FGT60E-HQFW00',
            'ip_address' => '10.11.107.5',
        ]);
        \App\Models\InterfaceAddress::create([
            'device_id' => $fw->id, 'ip' => '131.148.15.194', 'prefix_len' => 27,
            'is_public' => true, 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
        IpReservation::create([
            'ip' => '131.148.15.194', 'purpose' => 'nat', 'assignment' => 'static',
            'label' => 'Verkada - EXT_Spectrum',
        ]);
        Circuit::factory()->create(['site_id' => $site->id, 'subnet' => '131.148.15.192/27']);

        $rows = collect((new Ipam)->detail('131.148.15.192/27')['rows'])->where('ip', '131.148.15.194');

        $this->assertCount(1, $rows, 'one address, one row');
        $this->assertSame('assigned', $rows->first()['state']);
        $this->assertSame('Verkada - EXT_Spectrum', $rows->first()['reservation']['label']);
    }

    public function test_a_reservation_counts_against_the_blocks_occupancy(): void
    {
        $site = Site::factory()->create();
        Circuit::factory()->create(['site_id' => $site->id, 'subnet' => '4.18.134.160/27']);
        foreach (['4.18.134.173', '4.18.134.189', '4.18.134.190'] as $ip) {
            IpReservation::create(['ip' => $ip, 'purpose' => 'nat', 'assignment' => 'static']);
        }

        $wan = collect((new Ipam)->ranges()['sites'][0]['ranges'])->firstWhere('cidr', '4.18.134.160/27');

        $this->assertSame(3, $wan['seen'], 'the NAT pools consume real space');
    }

    public function test_a_viewer_cannot_record_or_delete_an_address(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);
        $row = IpReservation::create(['ip' => '4.18.134.173', 'purpose' => 'nat', 'assignment' => 'static']);

        $this->actingAs($viewer)->postJson('/api/ipam/reservations', [
            'ip' => '4.18.134.174', 'purpose' => 'nat', 'assignment' => 'static',
        ])->assertForbidden();

        $this->actingAs($viewer)->deleteJson("/api/ipam/reservations/{$row->id}")->assertForbidden();
    }

    public function test_a_bad_address_or_purpose_is_rejected(): void
    {
        $this->actingAs($this->admin())->postJson('/api/ipam/reservations', [
            'ip' => 'not-an-ip', 'purpose' => 'nat', 'assignment' => 'static',
        ])->assertStatus(422);

        $this->actingAs($this->admin())->postJson('/api/ipam/reservations', [
            'ip' => '4.18.134.175', 'purpose' => 'whatever', 'assignment' => 'static',
        ])->assertStatus(422);
    }

    public function test_reservations_can_be_listed_for_one_range(): void
    {
        IpReservation::create(['ip' => '131.148.15.220', 'purpose' => 'nat', 'assignment' => 'static']);
        IpReservation::create(['ip' => '4.18.134.190', 'purpose' => 'nat', 'assignment' => 'static']);

        $rows = $this->actingAs($this->admin())
            ->getJson('/api/ipam/reservations?cidr=131.148.15.192/27')->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('131.148.15.220', $rows[0]['ip']);
    }

    public function test_a_recorded_purpose_outranks_a_bare_arp_sighting(): void
    {
        // A firewall proxy-ARPs for its NAT pools, so those addresses DO carry an ARP
        // sighting. They were reading "discovered" while identical pools that nothing
        // ARPs read "reserved" — the same kind of allocation shown two different ways.
        $site = Site::factory()->create();
        $gw = Device::factory()->create([
            'site_id' => $site->id, 'role' => 'firewall', 'ip_address' => '10.11.9.230',
        ]);
        \App\Models\ArpEntry::create([
            'device_id' => $gw->id, 'site_id' => $site->id, 'ip' => '131.148.15.220',
            'mac' => '00:09:0F:09:01:06', 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
        Circuit::factory()->create(['site_id' => $site->id, 'subnet' => '131.148.15.192/27']);

        $before = collect((new Ipam)->detail('131.148.15.192/27')['rows'])->firstWhere('ip', '131.148.15.220');
        $this->assertSame('discovered', $before['state']);

        IpReservation::create([
            'ip' => '131.148.15.220', 'purpose' => 'nat', 'assignment' => 'static',
            'label' => 'All Default - EXT_Spectrum',
        ]);

        $after = collect((new Ipam)->detail('131.148.15.192/27')['rows'])->firstWhere('ip', '131.148.15.220');
        $this->assertSame('reserved', $after['state'], 'a written-down purpose beats a bare sighting');
        $this->assertSame('All Default - EXT_Spectrum', $after['hostname']);
    }

    public function test_a_device_we_can_see_holding_the_address_still_outranks_a_reservation(): void
    {
        // 131.148.15.194 is both a firewall interface address and a NAT pool. The
        // device fact is the more specific one, so the row stays "assigned" and simply
        // carries the pool name too.
        $site = Site::factory()->create();
        $fw = Device::factory()->create([
            'site_id' => $site->id, 'role' => 'firewall', 'name' => 'FGT60E-HQFW00', 'ip_address' => '10.11.107.5',
        ]);
        \App\Models\InterfaceAddress::create([
            'device_id' => $fw->id, 'ip' => '131.148.15.194', 'prefix_len' => 27,
            'is_public' => true, 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
        IpReservation::create([
            'ip' => '131.148.15.194', 'purpose' => 'nat', 'assignment' => 'static',
            'label' => 'Verkada - EXT_Spectrum',
        ]);
        Circuit::factory()->create(['site_id' => $site->id, 'subnet' => '131.148.15.192/27']);

        $row = collect((new Ipam)->detail('131.148.15.192/27')['rows'])->firstWhere('ip', '131.148.15.194');

        $this->assertSame('assigned', $row['state']);
        $this->assertSame('FGT60E-HQFW00', $row['device_name']);
        $this->assertSame('Verkada - EXT_Spectrum', $row['reservation']['label']);
    }

    public function test_a_conflict_outranks_a_reservation_because_it_is_a_fault(): void
    {
        $site = Site::factory()->create();
        $a = Device::factory()->create(['site_id' => $site->id, 'ip_address' => '10.11.9.230']);
        $b = Device::factory()->create(['site_id' => $site->id, 'ip_address' => '10.11.9.231']);
        foreach ([[$a, 'AA:AA:AA:AA:AA:AA'], [$b, 'BB:BB:BB:BB:BB:BB']] as [$d, $mac]) {
            \App\Models\ArpEntry::create([
                'device_id' => $d->id, 'site_id' => $site->id, 'ip' => '131.148.15.221',
                'mac' => $mac, 'first_seen_at' => now(), 'last_seen_at' => now(),
            ]);
        }
        IpReservation::create(['ip' => '131.148.15.221', 'purpose' => 'nat', 'assignment' => 'static']);
        Circuit::factory()->create(['site_id' => $site->id, 'subnet' => '131.148.15.192/27']);

        $row = collect((new Ipam)->detail('131.148.15.192/27')['rows'])->firstWhere('ip', '131.148.15.221');

        $this->assertSame('conflict', $row['state'], 'a fault must not be masked by a reservation');
    }

    public function test_the_planner_treats_a_recorded_address_as_occupying_its_block(): void
    {
        // Otherwise the planner would offer a /24 somebody has already allocated into.
        IpReservation::create(['ip' => '10.200.199.20', 'purpose' => 'reserved', 'assignment' => 'static']);

        $block = collect((new Ipam)->space()['blocks'])->firstWhere('octet', 199);

        $this->assertSame('used', $block['state']);
    }
}
