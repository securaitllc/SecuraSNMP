<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\InterfaceMetricHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InterfaceMetricControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_can_list_metrics_for_an_interface(): void
    {
        $interface = DeviceInterface::factory()->create();
        InterfaceMetricHistory::create([
            'device_interface_id' => $interface->id,
            'recorded_at' => now(),
            'status' => 'up',
            'in_octets_delta' => 100,
            'out_octets_delta' => 200,
            'in_discards_delta' => 0,
            'out_discards_delta' => 0,
        ]);
        $viewer = User::factory()->create();

        $response = $this->actingAs($viewer)->getJson("/api/interfaces/metrics?interface_id={$interface->id}");

        $response->assertOk();
        $response->assertJsonCount(1);
    }

    public function test_metrics_can_be_filtered_by_device(): void
    {
        $deviceA = Device::factory()->create();
        $deviceB = Device::factory()->create();
        $interfaceA = DeviceInterface::factory()->create(['device_id' => $deviceA->id]);
        $interfaceB = DeviceInterface::factory()->create(['device_id' => $deviceB->id]);
        InterfaceMetricHistory::create([
            'device_interface_id' => $interfaceA->id, 'recorded_at' => now(), 'status' => 'up',
            'in_octets_delta' => 1, 'out_octets_delta' => 1, 'in_discards_delta' => 0, 'out_discards_delta' => 0,
        ]);
        InterfaceMetricHistory::create([
            'device_interface_id' => $interfaceB->id, 'recorded_at' => now(), 'status' => 'up',
            'in_octets_delta' => 1, 'out_octets_delta' => 1, 'in_discards_delta' => 0, 'out_discards_delta' => 0,
        ]);
        $viewer = User::factory()->create();

        $response = $this->actingAs($viewer)->getJson("/api/interfaces/metrics?device_id={$deviceA->id}");

        $response->assertOk();
        $response->assertJsonCount(1);
    }

    public function test_metrics_outside_the_requested_range_are_excluded(): void
    {
        $interface = DeviceInterface::factory()->create();
        InterfaceMetricHistory::create([
            'device_interface_id' => $interface->id, 'recorded_at' => now()->subHours(2), 'status' => 'up',
            'in_octets_delta' => 1, 'out_octets_delta' => 1, 'in_discards_delta' => 0, 'out_discards_delta' => 0,
        ]);
        $viewer = User::factory()->create();

        $response = $this->actingAs($viewer)->getJson("/api/interfaces/metrics?interface_id={$interface->id}&range=1h");

        $response->assertOk();
        $response->assertJsonCount(0);
    }

    public function test_guest_cannot_list_interface_metrics(): void
    {
        $this->getJson('/api/interfaces/metrics')->assertStatus(401);
    }
}
