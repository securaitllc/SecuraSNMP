<?php

namespace Tests\Feature;

use App\Models\ArpEntry;
use App\Models\Device;
use App\Models\Site;
use App\Models\SubnetReservation;
use App\Models\User;
use App\Services\Ipam;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Holding a whole /24 for a location that has not been built.
 *
 * Every other IPAM signal is occupancy — an address answered, a device is addressed
 * there. A block reserved for a service centre whose kit has not shipped answers
 * nothing at all, so occupancy can never see it and the planner kept offering it as
 * free. Before this, the only way to hold space was to record one address inside the
 * block, which then read as a live subnet with a single host in it for ever.
 */
class SubnetReservationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function occupy(string $ip): void
    {
        $site = Site::factory()->create();
        $device = Device::factory()->create(['site_id' => $site->id, 'role' => 'edgeconnect', 'ip_address' => $ip]);
        ArpEntry::create([
            'device_id' => $device->id,
            'site_id' => $site->id,
            'ip' => $ip,
            'mac' => 'AA:BB:CC:00:00:01',
            'first_seen_at' => now()->subDays(3),
            'last_seen_at' => now(),
        ]);
    }

    private function block(int $octet): array
    {
        return collect((new Ipam)->space()['blocks'])->firstWhere('octet', $octet);
    }

    public function test_a_held_block_is_not_offered_as_free(): void
    {
        // The whole feature. An empty /24 is indistinguishable from an available one
        // unless somebody wrote down that it is spoken for.
        $this->assertSame('free', $this->block(190)['state']);

        SubnetReservation::factory()->create([
            'cidr' => '10.200.190.0/24',
            'site_label' => 'SC220 Ocala',
        ]);

        $this->assertSame('planned', $this->block(190)['state']);
    }

    public function test_a_held_block_says_who_it_is_for(): void
    {
        // An anonymous hold outlives the reason it was made and becomes dead space.
        SubnetReservation::factory()->create([
            'cidr' => '10.200.191.0/24',
            'site_label' => 'SC220 Ocala',
            'planned_for' => '2026-12-01',
        ]);

        $res = $this->block(191)['reservation'];

        $this->assertSame('SC220 Ocala', $res['holder']);
        $this->assertSame('2026-12-01', $res['planned_for']);
    }

    public function test_the_planner_stops_suggesting_a_held_block(): void
    {
        $space = (new Ipam)->space();
        $first = $space['runs'][0]['from'];

        SubnetReservation::factory()->create(['cidr' => "10.200.{$first}.0/24", 'site_label' => 'SC221']);

        $after = (new Ipam)->space();

        $this->assertNotSame("10.200.{$first}.0/24", $after['summary']['suggested']);
        $this->assertSame(1, $after['summary']['planned']);
    }

    public function test_a_hold_over_live_hosts_reads_as_a_conflict_not_as_held(): void
    {
        // Silence here is how the collision ships. Either the hold was written over
        // occupied space or somebody deployed into a block that was spoken for.
        $this->occupy('10.200.192.10');
        SubnetReservation::factory()->create(['cidr' => '10.200.192.0/24', 'site_label' => 'SC222']);

        $block = $this->block(192);

        $this->assertSame('conflict', $block['state']);
        $this->assertSame(1, (new Ipam)->space()['summary']['conflict']);
    }

    public function test_a_released_hold_returns_the_block_to_free(): void
    {
        $hold = SubnetReservation::factory()->create(['cidr' => '10.200.193.0/24', 'site_label' => 'SC223']);
        $this->assertSame('planned', $this->block(193)['state']);

        $this->actingAs($this->admin())
            ->postJson("/api/ipam/subnet-reservations/{$hold->id}/release")
            ->assertOk();

        $this->assertSame('free', $this->block(193)['state']);
        $this->assertNotNull($hold->fresh()->released_at, 'the row survives — which blocks were handed back is the history');
    }

    public function test_a_hold_wider_than_a_slash_24_covers_every_block_it_spans(): void
    {
        // A /23 is two /24s. Claiming only the first would leave the second offered as
        // free, which is exactly the double-allocation this prevents.
        SubnetReservation::factory()->create(['cidr' => '10.200.200.0/23', 'site_label' => 'SC224 large site']);

        $this->assertSame('planned', $this->block(200)['state']);
        $this->assertSame('planned', $this->block(201)['state']);
        $this->assertSame('free', $this->block(202)['state']);
    }

    public function test_an_overlapping_hold_is_refused(): void
    {
        SubnetReservation::factory()->create(['cidr' => '10.200.210.0/23', 'site_label' => 'SC225']);

        $this->actingAs($this->admin())
            ->postJson('/api/ipam/subnet-reservations', [
                'cidr' => '10.200.211.0/24',
                'site_label' => 'SC226',
            ])
            ->assertStatus(422);

        $this->assertSame(1, SubnetReservation::count());
    }

    public function test_a_released_hold_does_not_block_a_new_one(): void
    {
        SubnetReservation::factory()->released()->create(['cidr' => '10.200.212.0/24', 'site_label' => 'SC227']);

        $this->actingAs($this->admin())
            ->postJson('/api/ipam/subnet-reservations', [
                'cidr' => '10.200.212.0/24',
                'site_label' => 'SC228',
            ])
            ->assertStatus(201);
    }

    public function test_the_cidr_is_stored_canonically(): void
    {
        // 10.200.213.37/24 and 10.200.213.9/24 are one block; two rows for it would
        // defeat the overlap check entirely.
        $this->actingAs($this->admin())
            ->postJson('/api/ipam/subnet-reservations', [
                'cidr' => '10.200.213.37/24',
                'site_label' => 'SC229',
            ])
            ->assertStatus(201);

        $this->assertSame('10.200.213.0/24', SubnetReservation::first()->cidr);
    }

    public function test_a_hold_must_name_who_it_is_for(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/ipam/subnet-reservations', ['cidr' => '10.200.214.0/24'])
            ->assertStatus(422);
    }

    public function test_a_single_address_is_refused_as_a_subnet(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/ipam/subnet-reservations', [
                'cidr' => '10.200.215.7/32',
                'site_label' => 'SC230',
            ])
            ->assertStatus(422);
    }

    public function test_an_overdue_hold_is_flagged(): void
    {
        // Deployments slip. An untouched reservation never raises the question of
        // whether it is still needed, and that is how reserved space becomes dead.
        $hold = SubnetReservation::factory()->create([
            'cidr' => '10.200.216.0/24',
            'site_label' => 'SC231',
            'planned_for' => now()->subMonth()->toDateString(),
        ]);

        $this->assertTrue($hold->overdue);
        $this->assertTrue($this->block(216)['reservation']['overdue']);
    }

    public function test_a_viewer_cannot_hold_space(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'viewer']))
            ->postJson('/api/ipam/subnet-reservations', [
                'cidr' => '10.200.217.0/24',
                'site_label' => 'SC232',
            ])
            ->assertForbidden();
    }

    public function test_the_list_hides_released_holds_unless_asked(): void
    {
        SubnetReservation::factory()->create(['cidr' => '10.200.218.0/24', 'site_label' => 'Held']);
        SubnetReservation::factory()->released()->create(['cidr' => '10.200.219.0/24', 'site_label' => 'Gone']);

        $viewer = User::factory()->create(['role' => 'viewer']);

        $this->assertCount(1, $this->actingAs($viewer)->getJson('/api/ipam/subnet-reservations')->json('data'));
        $this->assertCount(2, $this->actingAs($viewer)->getJson('/api/ipam/subnet-reservations?include_released=1')->json('data'));
    }
}
