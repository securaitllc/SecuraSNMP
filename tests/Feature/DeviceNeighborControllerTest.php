<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\LldpNeighbor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The device panel needs to answer "what is plugged into port N?" without sending
 * the operator to the topology map to find out.
 */
class DeviceNeighborControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_endpoint_identity_per_port(): void
    {
        $device = Device::factory()->create();
        LldpNeighbor::create([
            'device_id' => $device->id,
            'local_port' => 'ge-0/0/30',
            'remote_sysname' => 'regDN 500206,MINET_6920',
            'remote_port' => 'LAN port',
            'neighbor_type' => 'phone',
            'remote_mgmt_addr' => '10.0.2.54',
            'extension' => '500206',
            'endpoint_model' => 'Mitel 6920',
            'last_seen_at' => now(),
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->getJson("/api/devices/{$device->id}/neighbors");

        $response->assertOk();
        $response->assertJsonPath('0.local_port', 'ge-0/0/30');
        $response->assertJsonPath('0.extension', '500206');
        $response->assertJsonPath('0.endpoint_model', 'Mitel 6920');
        $response->assertJsonPath('0.remote_mgmt_addr', '10.0.2.54');
    }

    public function test_it_only_returns_this_devices_neighbors(): void
    {
        $a = Device::factory()->create();
        $b = Device::factory()->create();
        foreach ([$a, $b] as $d) {
            LldpNeighbor::create([
                'device_id' => $d->id,
                'local_port' => 'ge-0/0/1',
                'remote_sysname' => "on-{$d->id}",
                'last_seen_at' => now(),
            ]);
        }

        $response = $this->actingAs(User::factory()->create())
            ->getJson("/api/devices/{$a->id}/neighbors");

        $response->assertOk();
        $response->assertJsonCount(1);
        $response->assertJsonPath('0.remote_sysname', "on-{$a->id}");
    }

    public function test_a_device_with_no_neighbours_returns_an_empty_list(): void
    {
        $device = Device::factory()->create();

        $this->actingAs(User::factory()->create())
            ->getJson("/api/devices/{$device->id}/neighbors")
            ->assertOk()
            ->assertJsonCount(0);
    }

    public function test_guests_cannot_read_neighbours(): void
    {
        $device = Device::factory()->create();

        $this->getJson("/api/devices/{$device->id}/neighbors")->assertUnauthorized();
    }
}
