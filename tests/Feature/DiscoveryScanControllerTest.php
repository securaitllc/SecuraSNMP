<?php

namespace Tests\Feature;

use App\Jobs\RunDiscoveryScan;
use App\Models\DiscoveredDevice;
use App\Models\Device;
use App\Models\DiscoveryScan;
use App\Models\Site;
use App\Models\SnmpCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DiscoveryScanControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_launch_a_scan_which_queues_the_job(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $cred = SnmpCredential::factory()->create();

        $response = $this->actingAs($admin)->postJson('/api/discovery/scans', [
            'name' => 'Loopback sweep',
            'snmp_credential_id' => $cred->id,
            'subnets' => ['10.15.0.0/22'],
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('discovery_scans', ['name' => 'Loopback sweep', 'status' => 'pending']);
        Queue::assertPushed(RunDiscoveryScan::class);
    }

    public function test_scan_rejects_an_invalid_cidr(): void
    {
        $admin = User::factory()->admin()->create();
        $cred = SnmpCredential::factory()->create();

        $this->actingAs($admin)->postJson('/api/discovery/scans', [
            'snmp_credential_id' => $cred->id,
            'subnets' => ['10.15.0.0'],
        ])->assertStatus(422);
    }

    public function test_viewer_cannot_launch_a_scan(): void
    {
        $viewer = User::factory()->create();
        $cred = SnmpCredential::factory()->create();

        $this->actingAs($viewer)->postJson('/api/discovery/scans', [
            'snmp_credential_id' => $cred->id,
            'subnets' => ['10.15.0.0/24'],
        ])->assertForbidden();
    }

    public function test_show_returns_discovered_devices(): void
    {
        $admin = User::factory()->admin()->create();
        $scan = DiscoveryScan::factory()->create();
        DiscoveredDevice::create([
            'discovery_scan_id' => $scan->id,
            'ip_address' => '10.20.5.10',
            'sys_name' => 'sw-01',
            'suggested_role' => 'switch',
            'status' => 'new',
        ]);

        $this->actingAs($admin)->getJson("/api/discovery/scans/{$scan->id}")
            ->assertOk()
            ->assertJsonPath('discovered_devices.0.ip_address', '10.20.5.10');
    }

    public function test_import_creates_devices_reusing_the_scan_credential(): void
    {
        $admin = User::factory()->admin()->create();
        $site = Site::factory()->create();
        $cred = SnmpCredential::factory()->create(['snmp_community' => 'sup3rsecret']);
        $scan = DiscoveryScan::factory()->create(['snmp_credential_id' => $cred->id]);

        $discovered = DiscoveredDevice::create([
            'discovery_scan_id' => $scan->id,
            'ip_address' => '10.20.5.10',
            'sys_name' => 'sw-tampa-01',
            'vendor' => 'juniper',
            'model' => 'ex4300-48t',
            'serial_number' => 'JN999',
            'suggested_role' => 'switch',
            'suggested_site_id' => $site->id,
            'status' => 'new',
        ]);

        $response = $this->actingAs($admin)->postJson("/api/discovery/scans/{$scan->id}/import", [
            'device_ids' => [$discovered->id],
        ]);

        $response->assertOk()->assertJsonPath('imported', 1);

        $this->assertDatabaseHas('devices', [
            'ip_address' => '10.20.5.10',
            'name' => 'sw-tampa-01',
            'vendor' => 'juniper',
            'role' => 'switch',
            'site_id' => $site->id,
        ]);

        $discovered->refresh();
        $this->assertSame('imported', $discovered->status);
        $this->assertNotNull($discovered->imported_device_id);

        // The scan's SNMP community is copied onto the new device.
        $device = Device::where('ip_address', '10.20.5.10')->firstOrFail();
        $this->assertSame('sup3rsecret', $device->snmp_community);
    }

    public function test_import_resolves_a_site_when_suggested_site_is_null(): void
    {
        // A stale discovered row (or a race) can have no suggested_site_id; the
        // import must resolve/create a site from the IP, not 500 on the non-null FK.
        $admin = User::factory()->admin()->create();
        $cred = SnmpCredential::factory()->create();
        $scan = DiscoveryScan::factory()->create(['snmp_credential_id' => $cred->id]);

        $discovered = DiscoveredDevice::create([
            'discovery_scan_id' => $scan->id,
            'ip_address' => '10.0.5.10',
            // FQDN — the imported device should be named by its short hostname.
            'sys_name' => 'CORE-SW01.corp.example.com',
            'vendor' => 'juniper',
            'suggested_role' => 'switch',
            'suggested_site_id' => null,
            'status' => 'new',
        ]);

        $response = $this->actingAs($admin)->postJson("/api/discovery/scans/{$scan->id}/import", [
            'device_ids' => [$discovered->id],
        ]);

        $response->assertOk()->assertJsonPath('imported', 1);
        $device = Device::where('ip_address', '10.0.5.10')->firstOrFail();
        $this->assertNotNull($device->site_id);
        // Resolved via the /24 convention.
        $this->assertDatabaseHas('sites', ['id' => $device->site_id, 'subnet' => '10.0.5.0/24']);
        // FQDN domain stripped for a clean topology label.
        $this->assertSame('CORE-SW01', $device->name);
    }

    public function test_import_skips_devices_that_match_existing_records(): void
    {
        $admin = User::factory()->admin()->create();
        $site = Site::factory()->create();
        $scan = DiscoveryScan::factory()->create();

        $discovered = DiscoveredDevice::create([
            'discovery_scan_id' => $scan->id,
            'ip_address' => '10.20.5.10',
            'suggested_site_id' => $site->id,
            'status' => 'existing',
        ]);

        $this->actingAs($admin)->postJson("/api/discovery/scans/{$scan->id}/import", [
            'device_ids' => [$discovered->id],
        ])->assertOk()->assertJsonPath('imported', 0);

        $this->assertDatabaseMissing('devices', ['ip_address' => '10.20.5.10']);
    }

    public function test_a_discovered_device_can_be_ignored(): void
    {
        $admin = User::factory()->admin()->create();
        $scan = DiscoveryScan::factory()->create();
        $discovered = DiscoveredDevice::create([
            'discovery_scan_id' => $scan->id,
            'ip_address' => '10.20.5.11',
            'status' => 'new',
        ]);

        $this->actingAs($admin)->postJson("/api/discovery/discovered/{$discovered->id}/ignore")
            ->assertOk();

        $this->assertSame('ignored', $discovered->fresh()->status);
    }
}
