<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceToolControllerTest extends TestCase
{
    use RefreshDatabase;

    private function device(): Device
    {
        return Device::factory()->create(['site_id' => Site::factory()->create()->id]);
    }

    public function test_viewer_cannot_run_tools(): void
    {
        $viewer = User::factory()->create();
        $this->actingAs($viewer)->postJson("/api/devices/{$this->device()->id}/tools/ping")->assertForbidden();
    }

    public function test_invalid_target_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)
            ->postJson("/api/devices/{$this->device()->id}/tools/ping", ['target' => 'not-an-ip; rm -rf /'])
            ->assertStatus(422);
    }

    public function test_non_numeric_oid_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)
            ->postJson("/api/devices/{$this->device()->id}/tools/snmpwalk", ['oid' => 'abc; cat /etc/passwd'])
            ->assertStatus(422);
    }

    public function test_unknown_tool_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)
            ->postJson("/api/devices/{$this->device()->id}/tools/nmap")
            ->assertStatus(422);
    }
}
