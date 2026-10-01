<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TopInterfacesTest extends TestCase
{
    use RefreshDatabase;

    public function test_top_returns_busiest_up_interfaces_first(): void
    {
        $site = Site::factory()->create();
        $device = Device::factory()->create(['site_id' => $site->id]);

        DeviceInterface::create(['device_id' => $device->id, 'if_index' => 1, 'if_name' => 'ge-0/0/0', 'status' => 'up', 'speed_bps' => 1_000_000_000, 'in_util_pct' => 12, 'out_util_pct' => 5]);
        DeviceInterface::create(['device_id' => $device->id, 'if_index' => 2, 'if_name' => 'ge-0/0/1', 'status' => 'up', 'speed_bps' => 1_000_000_000, 'in_util_pct' => 80, 'out_util_pct' => 3]);
        // Down / no-speed interfaces are excluded.
        DeviceInterface::create(['device_id' => $device->id, 'if_index' => 3, 'if_name' => 'ge-0/0/2', 'status' => 'down', 'speed_bps' => 1_000_000_000, 'in_util_pct' => 99, 'out_util_pct' => 99]);

        $viewer = User::factory()->create();
        $response = $this->actingAs($viewer)->getJson('/api/interfaces/top?limit=5');

        $response->assertOk();
        $response->assertJsonPath('0.if_name', 'ge-0/0/1'); // 80% is busiest
        $response->assertJsonPath('0.device.name', $device->name);
        $response->assertJsonCount(2); // the down one is excluded
    }
}
