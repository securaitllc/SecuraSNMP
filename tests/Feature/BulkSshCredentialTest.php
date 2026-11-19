<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceHardwareChange;
use App\Models\Site;
use App\Models\SshCredential;
use App\Models\User;
use App\Services\SshCredentialAssigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Re-pointing replaced hardware at a different shared SSH credential.
 *
 * Swapping an appliance swaps its login. Thirty trips through the device editor is
 * slow, and a mistake in that sequence is silent: nothing in the interface fails,
 * the SSH pollers simply stop being able to log in, and tunnel and next-hop data
 * goes stale under pages that still render it. So the new credential is proven
 * against the device before it is written.
 */
class BulkSshCredentialTest extends TestCase
{
    use RefreshDatabase;

    private function device(string $name, ?SshCredential $cred = null): Device
    {
        return Device::factory()->create([
            'site_id' => Site::factory()->create()->id,
            'name' => $name,
            'role' => 'edgeconnect',
            'ip_address' => '10.200.'.random_int(1, 254).'.254',
            'ssh_credential_id' => $cred?->id,
        ]);
    }

    private function creds(): array
    {
        return [
            SshCredential::create(['name' => 'Massey SDWAN', 'username' => 'admin', 'password' => 'old-secret']),
            SshCredential::create(['name' => 'EC-Nodus', 'username' => 'nodus', 'password' => 'new-secret']),
        ];
    }

    /** A prober that accepts only the named password — stands in for the appliance. */
    private function accepting(string $password): callable
    {
        return function (Device $device, string $user, string $pass) use ($password): true {
            if ($pass !== $password) {
                throw new RuntimeException('authentication rejected');
            }

            return true;
        };
    }

    public function test_devices_that_authenticate_are_moved(): void
    {
        [$old, $new] = $this->creds();
        $devices = collect([$this->device('SC203_SDW', $old), $this->device('SC191_SDW', $old)]);

        $out = (new SshCredentialAssigner($this->accepting('new-secret')))
            ->assign($devices, $new, true, null);

        $this->assertSame(2, $out['applied']);
        $this->assertSame(0, $out['failed']);
        foreach ($devices as $d) {
            $this->assertSame($new->id, $d->fresh()->ssh_credential_id);
        }
    }

    public function test_a_device_that_refuses_the_new_credential_keeps_the_old_one(): void
    {
        // The whole safeguard. A device left on a working credential is recoverable;
        // a fleet left on a broken one is an outage nobody is told about.
        [$old, $new] = $this->creds();
        $device = $this->device('SC005_SDW', $old);

        $out = (new SshCredentialAssigner($this->accepting('something-else')))
            ->assign(collect([$device]), $new, true, null);

        $this->assertSame(0, $out['applied']);
        $this->assertSame(1, $out['failed']);
        $this->assertSame($old->id, $device->fresh()->ssh_credential_id);
        $this->assertStringContainsString('SSH login failed', $out['results'][0]['reason']);
    }

    public function test_one_failure_does_not_stop_the_rest_of_the_batch(): void
    {
        [$old, $new] = $this->creds();
        $good = $this->device('GOOD_SDW', $old);
        $bad = $this->device('BAD_SDW', $old);

        $prober = function (Device $d, string $u, string $p) use ($bad): true {
            if ($d->id === $bad->id) {
                throw new RuntimeException('authentication rejected');
            }

            return true;
        };

        $out = (new SshCredentialAssigner($prober))->assign(collect([$bad, $good]), $new, true, null);

        $this->assertSame(1, $out['applied']);
        $this->assertSame(1, $out['failed']);
        $this->assertSame($new->id, $good->fresh()->ssh_credential_id);
        $this->assertSame($old->id, $bad->fresh()->ssh_credential_id);
    }

    public function test_a_device_already_on_the_profile_is_untouched_and_not_counted(): void
    {
        [, $new] = $this->creds();
        $device = $this->device('ALREADY_SDW', $new);

        $out = (new SshCredentialAssigner(fn () => throw new RuntimeException('should not probe')))
            ->assign(collect([$device]), $new, true, null);

        $this->assertSame(0, $out['applied']);
        $this->assertSame(1, $out['unchanged']);
    }

    public function test_an_inline_credential_is_cleared_when_a_profile_is_assigned(): void
    {
        // A leftover inline password would win back the moment the profile link was
        // cleared, resurrecting a credential the operator believed was replaced.
        [, $new] = $this->creds();
        $device = $this->device('INLINE_SDW');
        $device->forceFill(['ssh_username' => 'legacy', 'ssh_credential' => 'legacy-pass'])->save();

        (new SshCredentialAssigner($this->accepting('new-secret')))
            ->assign(collect([$device]), $new, true, null);

        $fresh = $device->fresh();
        $this->assertSame($new->id, $fresh->ssh_credential_id);
        $this->assertNull($fresh->ssh_credential);
        $this->assertNull($fresh->ssh_username);
    }

    public function test_verification_can_be_waived_for_an_unreachable_device(): void
    {
        [$old, $new] = $this->creds();
        $device = $this->device('DOWN_SDW', $old);

        $out = (new SshCredentialAssigner(fn () => throw new RuntimeException('unreachable')))
            ->assign(collect([$device]), $new, false, null);

        $this->assertSame(1, $out['applied']);
        $this->assertSame($new->id, $device->fresh()->ssh_credential_id);
    }

    public function test_a_device_with_no_address_cannot_be_verified(): void
    {
        // The column is NOT NULL, so the reachable bad state is a blank string —
        // a device added for inventory before anyone recorded how to reach it.
        [$old, $new] = $this->creds();
        $device = $this->device('NOIP_SDW', $old);
        $device->forceFill(['ip_address' => ''])->save();

        $out = (new SshCredentialAssigner($this->accepting('new-secret')))
            ->assign(collect([$device]), $new, true, null);

        $this->assertSame(1, $out['failed']);
        $this->assertSame($old->id, $device->fresh()->ssh_credential_id);
    }

    public function test_the_change_is_written_to_the_device_history_without_the_secret(): void
    {
        [$old, $new] = $this->creds();
        $device = $this->device('TRAIL_SDW', $old);
        $user = User::factory()->create(['role' => 'admin']);

        (new SshCredentialAssigner($this->accepting('new-secret')))
            ->assign(collect([$device]), $new, true, $user);

        $row = DeviceHardwareChange::where('device_id', $device->id)->where('field', 'ssh_credential')->first();

        $this->assertNotNull($row);
        $this->assertSame('Massey SDWAN', $row->old_value);
        $this->assertSame('EC-Nodus', $row->new_value);
        $this->assertSame($user->name, $row->user_name);
        $this->assertStringNotContainsString('secret', strtolower($row->old_value.$row->new_value));
    }

    public function test_the_replaced_list_is_the_devices_whose_serial_changed(): void
    {
        [$old] = $this->creds();
        $swapped = $this->device('SWAPPED_SDW', $old);
        $renamed = $this->device('RENAMED_SDW', $old);
        $untouched = $this->device('UNTOUCHED_SDW', $old);

        DeviceHardwareChange::record($swapped, 'serial_number', '001BBC2F3368', '001BBC3EBEA0', 'snmp');
        // A rename is not a replacement, and must not be offered as one.
        DeviceHardwareChange::record($renamed, 'name', 'OLD-NAME', 'NEW-NAME', 'snmp');

        $ids = SshCredentialAssigner::recentlyReplaced(30)->pluck('id');

        $this->assertTrue($ids->contains($swapped->id));
        $this->assertFalse($ids->contains($renamed->id));
        $this->assertFalse($ids->contains($untouched->id));
    }

    public function test_an_old_serial_change_falls_out_of_the_window(): void
    {
        [$old] = $this->creds();
        $device = $this->device('LONGAGO_SDW', $old);
        DeviceHardwareChange::record($device, 'serial_number', 'AAA', 'BBB', 'snmp');
        DeviceHardwareChange::query()->update(['detected_at' => now()->subDays(90)]);

        $this->assertFalse(SshCredentialAssigner::recentlyReplaced(30)->pluck('id')->contains($device->id));
        $this->assertTrue(SshCredentialAssigner::recentlyReplaced(180)->pluck('id')->contains($device->id));
    }

    public function test_only_an_admin_can_reassign(): void
    {
        [$old, $new] = $this->creds();
        $device = $this->device('RBAC_SDW', $old);

        foreach (['viewer', 'analyst'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->postJson('/api/devices/bulk-ssh-credential', [
                    'device_ids' => [$device->id],
                    'ssh_credential_id' => $new->id,
                ])->assertForbidden();
        }

        $this->assertSame($old->id, $device->fresh()->ssh_credential_id);
    }

    public function test_the_endpoint_applies_and_reports(): void
    {
        [$old, $new] = $this->creds();
        $device = $this->device('API_SDW', $old);
        $this->app->bind(SshCredentialAssigner::class, fn () => new SshCredentialAssigner($this->accepting('new-secret')));

        $res = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson('/api/devices/bulk-ssh-credential', [
                'device_ids' => [$device->id],
                'ssh_credential_id' => $new->id,
            ])->assertOk();

        $this->assertSame(1, $res->json('applied'));
        $this->assertTrue($res->json('verified'));
        $this->assertSame('EC-Nodus', $res->json('credential.name'));
        $this->assertSame($new->id, $device->fresh()->ssh_credential_id);
    }

    public function test_the_response_never_carries_a_password(): void
    {
        [$old, $new] = $this->creds();
        $device = $this->device('LEAK_SDW', $old);
        $this->app->bind(SshCredentialAssigner::class, fn () => new SshCredentialAssigner($this->accepting('new-secret')));

        $body = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson('/api/devices/bulk-ssh-credential', [
                'device_ids' => [$device->id],
                'ssh_credential_id' => $new->id,
            ])->getContent();

        $this->assertStringNotContainsString('new-secret', $body);
        $this->assertStringNotContainsString('old-secret', $body);
    }

    public function test_the_trail_source_values_fit_the_column(): void
    {
        // SQLite ignores column widths; MySQL rejects. Without this assertion an
        // over-long source passes every test here and then throws SQLSTATE 22001 on
        // the first production run — which is precisely what v0.10.63 did with
        // 'bulk-assign-verified', 20 characters into a varchar(16).
        foreach ([SshCredentialAssigner::SOURCE_VERIFIED, SshCredentialAssigner::SOURCE_UNVERIFIED] as $source) {
            $this->assertLessThanOrEqual(
                DeviceHardwareChange::SOURCE_MAX,
                strlen($source),
                "trail source '{$source}' is too long for device_hardware_changes.source",
            );
        }

        $this->assertLessThanOrEqual(DeviceHardwareChange::FIELD_MAX, strlen('ssh_credential'));
    }

    public function test_a_device_is_not_left_changed_when_its_trail_row_cannot_be_written(): void
    {
        // The v0.10.63 failure exactly: the device row committed, the trail insert
        // threw, and the fleet was left with a device whose credential had changed
        // and no record that it had. Both writes are one transaction now.
        [$old, $new] = $this->creds();
        $device = $this->device('ROLLBACK_SDW', $old);

        // A field longer than the column forces the trail insert to fail the way a
        // too-long source did, without depending on the engine's width checking.
        DB::listen(function ($q) {
            if (str_contains($q->sql, 'insert into "device_hardware_changes"')) {
                throw new RuntimeException('trail write refused');
            }
        });

        $out = (new SshCredentialAssigner($this->accepting('new-secret')))
            ->assign(collect([$device]), $new, true, null);

        $this->assertSame(0, $out['applied']);
        $this->assertSame(1, $out['failed']);
        $this->assertSame($old->id, $device->fresh()->ssh_credential_id, 'the device must not keep a change its trail could not record');
    }

    public function test_a_verified_batch_is_capped_to_fit_the_gateway_timeout(): void
    {
        // nginx gives up at 120s and an unreachable device costs the full connect
        // timeout, so a verified run is bounded by arithmetic, not appetite. The
        // front end chunks larger selections rather than sending one long request.
        [, $new] = $this->creds();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->postJson('/api/devices/bulk-ssh-credential', [
                'device_ids' => range(1, SshCredentialAssigner::VERIFIED_BATCH_MAX + 1),
                'ssh_credential_id' => $new->id,
                'verify' => true,
            ])->assertStatus(422);

        $this->assertLessThanOrEqual(
            120,
            SshCredentialAssigner::VERIFIED_BATCH_MAX * SshCredentialAssigner::CONNECT_TIMEOUT,
            'a full batch of unreachable devices must finish inside nginx fastcgi_read_timeout',
        );
    }

    public function test_an_unverified_batch_may_be_larger(): void
    {
        // Nothing is dialled, so the only cost is the writes.
        [, $new] = $this->creds();

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson('/api/devices/bulk-ssh-credential', [
                'device_ids' => range(1, SshCredentialAssigner::MAX_DEVICES + 1),
                'ssh_credential_id' => $new->id,
                'verify' => false,
            ])->assertStatus(422);
    }
}
