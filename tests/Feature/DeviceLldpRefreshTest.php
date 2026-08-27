<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceLldpRefreshTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_cannot_pull_lldp_neighbors(): void
    {
        $device = Device::factory()->create();
        $viewer = User::factory()->create();

        $this->actingAs($viewer)->postJson("/api/devices/{$device->id}/lldp/refresh")->assertForbidden();
    }

    public function test_pull_lldp_requires_an_snmp_path(): void
    {
        // No community and no v3 user → nothing to read LLDP over → 422, before any walk.
        $device = Device::factory()->create(['snmp_community' => null, 'snmp_v3_username' => null]);
        $analyst = User::factory()->analyst()->create();

        $this->actingAs($analyst)->postJson("/api/devices/{$device->id}/lldp/refresh")->assertStatus(422);
    }
}
