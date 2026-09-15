<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Site;
use App\Models\SshCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceImportControllerTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $devices, array $over = []): array
    {
        return array_merge([
            'devices' => $devices,
            'role' => 'switch',
            'vendor' => 'juniper',
            'snmp_version' => '2c',
            'snmp_community' => 'secret-community',
        ], $over);
    }

    public function test_it_creates_a_switch_matched_to_its_site_by_the_sc_number(): void
    {
        $site = Site::factory()->create(['site_number' => '208']);
        $cred = SshCredential::create(['name' => 'Juniper', 'username' => 'admin', 'password' => 'x']);
        $user = User::factory()->admin()->create();

        $res = $this->actingAs($user)->postJson('/api/devices/import', $this->payload(
            [['name' => 'AL0001-SC208SWA001', 'ip' => '10.0.67.10']],
            ['ssh_credential_id' => $cred->id],
        ));

        $res->assertOk()->assertJsonPath('created_count', 1);
        $d = Device::firstWhere('name', 'AL0001-SC208SWA001');
        $this->assertNotNull($d);
        $this->assertSame($site->id, $d->site_id);
        $this->assertSame('10.0.67.10', $d->ip_address);
        $this->assertSame('switch', $d->role);
        $this->assertSame('secret-community', $d->snmp_community);
        $this->assertSame($cred->id, $d->ssh_credential_id);
        // status is enum(active|inactive) on the real DB — must be a valid member.
        $this->assertSame('active', $d->status);
        // snmp_version enum is v2c/v3 — a bare '2c' must be normalised, not stored raw.
        $this->assertSame('v2c', $d->snmp_version);
    }

    public function test_a_bare_snmp_version_is_normalised_to_the_db_enum(): void
    {
        Site::factory()->create(['site_number' => '208']);
        $user = User::factory()->admin()->create();

        $this->actingAs($user)->postJson('/api/devices/import', $this->payload(
            [['name' => 'AL0001-SC208SWA001', 'ip' => '10.0.67.10']],
            ['snmp_version' => '3'],
        ))->assertOk();

        $this->assertSame('v3', Device::firstWhere('name', 'AL0001-SC208SWA001')->snmp_version);
    }

    public function test_an_out_of_enum_vendor_or_role_is_rejected_with_422_not_500(): void
    {
        Site::factory()->create(['site_number' => '208']);
        $user = User::factory()->admin()->create();

        $this->actingAs($user)->postJson('/api/devices/import', $this->payload(
            [['name' => 'AL0001-SC208SWA001', 'ip' => '10.0.67.10']],
            ['vendor' => 'cisco'],
        ))->assertStatus(422);

        $this->actingAs($user)->postJson('/api/devices/import', $this->payload(
            [['name' => 'AL0001-SC208SWA001', 'ip' => '10.0.67.10']],
            ['role' => 'router'],
        ))->assertStatus(422);
    }

    public function test_it_pads_the_sc_number_to_match_a_zero_padded_site(): void
    {
        Site::factory()->create(['site_number' => '013']);
        $user = User::factory()->admin()->create();

        $this->actingAs($user)->postJson('/api/devices/import', $this->payload(
            [['name' => 'FL0079-SC013SWA001', 'ip' => '10.200.54.10']],
        ))->assertJsonPath('created_count', 1);
    }

    public function test_a_line_whose_sc_number_matches_no_site_is_unmatched_not_created(): void
    {
        $user = User::factory()->admin()->create();

        $res = $this->actingAs($user)->postJson('/api/devices/import', $this->payload(
            [['name' => 'TX9999-SC777SWA001', 'ip' => '10.9.9.10']],
        ));

        $res->assertJsonPath('created_count', 0)->assertJsonPath('unmatched_site_count', 1);
        $this->assertDatabaseCount('devices', 0);
    }

    public function test_unmatched_devices_are_parked_in_a_general_site_when_fallback_is_on(): void
    {
        $user = User::factory()->admin()->create();

        $res = $this->actingAs($user)->postJson('/api/devices/import', $this->payload(
            [['name' => 'TX9999-SC777SWA001', 'ip' => '10.9.9.10']],
            ['fallback_general' => true],
        ));

        $res->assertJsonPath('created_count', 0)
            ->assertJsonPath('unmatched_site_count', 0)
            ->assertJsonPath('general_count', 1);

        $general = Site::firstWhere('name', 'General (unassigned)');
        $this->assertNotNull($general);
        $d = Device::firstWhere('name', 'TX9999-SC777SWA001');
        $this->assertNotNull($d);
        $this->assertSame($general->id, $d->site_id);
        $this->assertSame('active', $d->status);
    }

    public function test_an_existing_device_is_skipped_by_ip_or_name(): void
    {
        $site = Site::factory()->create(['site_number' => '208']);
        Device::factory()->create(['site_id' => $site->id, 'name' => 'AL0001-SC208SWA001', 'ip_address' => '10.0.67.10']);
        $user = User::factory()->admin()->create();

        $res = $this->actingAs($user)->postJson('/api/devices/import', $this->payload(
            [['name' => 'AL0001-SC208SWA001', 'ip' => '10.0.67.10']],
        ));

        $res->assertJsonPath('created_count', 0)->assertJsonPath('skipped_existing_count', 1);
        $this->assertDatabaseCount('devices', 1);
    }

    public function test_dry_run_previews_without_writing(): void
    {
        Site::factory()->create(['site_number' => '208']);
        $user = User::factory()->admin()->create();

        $res = $this->actingAs($user)->postJson('/api/devices/import', $this->payload(
            [['name' => 'AL0001-SC208SWA001', 'ip' => '10.0.67.10']],
            ['dry_run' => true],
        ));

        $res->assertJsonPath('dry_run', true)->assertJsonPath('created_count', 1);
        $this->assertDatabaseCount('devices', 0);
    }

    public function test_guest_cannot_import_devices(): void
    {
        $this->postJson('/api/devices/import', $this->payload([['name' => 'X-SC001SWA001', 'ip' => '10.0.0.1']]))
            ->assertUnauthorized();
    }
}
