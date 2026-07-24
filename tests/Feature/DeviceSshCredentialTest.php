<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Site;
use App\Models\SshCredential;
use App\Services\SshVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceSshCredentialTest extends TestCase
{
    use RefreshDatabase;

    public function test_linked_credential_resolves_over_inline_values(): void
    {
        $site = Site::factory()->create();
        $cred = SshCredential::factory()->create(['username' => 'global-user', 'password' => 'global-pw']);

        $device = Device::factory()->create([
            'site_id' => $site->id,
            'ssh_username' => 'inline-user',
            'ssh_credential' => 'inline-pw',
            'ssh_credential_id' => $cred->id,
        ]);

        $this->assertSame('global-user', $device->effectiveSshUsername());
        $this->assertSame('global-pw', $device->effectiveSshCredential());
    }

    public function test_inline_values_are_used_when_no_credential_is_linked(): void
    {
        $site = Site::factory()->create();

        $device = Device::factory()->create([
            'site_id' => $site->id,
            'ssh_username' => 'inline-user',
            'ssh_credential' => 'inline-pw',
            'ssh_credential_id' => null,
        ]);

        $this->assertSame('inline-user', $device->effectiveSshUsername());
        $this->assertSame('inline-pw', $device->effectiveSshCredential());
    }

    public function test_verify_without_a_resolved_ssh_credential_returns_a_clear_error(): void
    {
        $admin = \App\Models\User::factory()->admin()->create();
        $site = Site::factory()->create();
        $device = Device::factory()->create([
            'site_id' => $site->id,
            'role' => 'edgeconnect',
            'ssh_username' => null,
            'ssh_credential' => null,
            'ssh_credential_id' => null,
        ]);

        $this->actingAs($admin)->postJson("/api/devices/{$device->id}/verify")
            ->assertStatus(422)
            ->assertJsonPath('error', fn ($m) => str_contains($m, 'No SSH credential resolved'));
    }

    public function test_config_backup_without_a_resolved_ssh_credential_returns_a_clear_error(): void
    {
        $admin = \App\Models\User::factory()->admin()->create();
        $site = Site::factory()->create();
        $device = Device::factory()->create([
            'site_id' => $site->id,
            'ssh_username' => null,
            'ssh_credential' => null,
            'ssh_credential_id' => null,
        ]);

        $this->actingAs($admin)->postJson("/api/devices/{$device->id}/configs")
            ->assertStatus(422)
            ->assertJsonPath('error', fn ($m) => str_contains($m, 'No SSH credential resolved'));
    }

    public function test_verify_all_includes_devices_that_only_have_a_linked_credential(): void
    {
        $site = Site::factory()->create();
        $cred = SshCredential::factory()->create();

        // No inline SSH creds — only the shared credential link.
        $device = Device::factory()->create([
            'site_id' => $site->id,
            'role' => 'edgeconnect',
            'ssh_username' => null,
            'ssh_credential' => null,
            'ssh_credential_id' => $cred->id,
        ]);

        $seen = [];
        $verifier = new SshVerifier(function (Device $d) use (&$seen): array {
            $seen[] = $d->id;

            return [];
        });

        $verifier->verifyAll();

        $this->assertContains($device->id, $seen);
    }
}
