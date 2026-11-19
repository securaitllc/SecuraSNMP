<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Support\SshSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use phpseclib3\Net\SSH2;
use RuntimeException;
use Tests\TestCase;

/**
 * SSRF-04: SSH logins never checked the server's host key. Re-pointing a device at
 * an attacker's sshd (or sitting on-path) handed over the fleet credential. Now the
 * first key seen is pinned per device, and a different key aborts BEFORE any
 * credential is sent.
 */
class SshHostKeyPinTest extends TestCase
{
    use RefreshDatabase;

    /** An SSH2 that presents a fixed host key and records whether login() was called. */
    private function fakeSsh(string|false $hostKey): SSH2
    {
        return new class('192.0.2.1', $hostKey) extends SSH2
        {
            public array $logins = [];

            public function __construct(string $host, private string|false $presented)
            {
                parent::__construct($host);
            }

            public function getServerPublicHostKey()
            {
                return $this->presented;
            }

            public function login($username, ...$args)
            {
                $this->logins[] = [$username, ...$args];

                return true;
            }
        };
    }

    public function test_devices_have_a_nullable_host_key_column(): void
    {
        $this->assertTrue(Schema::hasColumn('devices', 'ssh_host_key'));
    }

    /** SSRF-04: first contact stores the key and logs in. */
    public function test_first_use_pins_the_key(): void
    {
        $device = Device::factory()->create(['ip_address' => '10.9.9.9']);
        $ssh = $this->fakeSsh('ssh-ed25519 AAAAFIRST');

        $this->assertTrue(SshSession::login($ssh, $device, 'noc', 'pw'));

        $this->assertSame('ssh-ed25519 AAAAFIRST', $device->fresh()->ssh_host_key);
        $this->assertCount(1, $ssh->logins);
    }

    /** SSRF-04: the pinned key matching lets the login through. */
    public function test_a_matching_key_logs_in(): void
    {
        $device = Device::factory()->create(['ip_address' => '10.9.9.9']);
        $device->forceFill(['ssh_host_key' => 'ssh-ed25519 AAAAFIRST'])->save();
        $ssh = $this->fakeSsh('ssh-ed25519 AAAAFIRST');

        $this->assertTrue(SshSession::login($ssh, $device, 'noc', 'pw'));
        $this->assertCount(1, $ssh->logins);
    }

    /** SSRF-04: a different key aborts without sending the credential. */
    public function test_a_changed_key_aborts_before_sending_credentials(): void
    {
        $device = Device::factory()->create(['ip_address' => '10.9.9.9']);
        $device->forceFill(['ssh_host_key' => 'ssh-ed25519 AAAAFIRST'])->save();
        $ssh = $this->fakeSsh('ssh-ed25519 AAAAEVIL');

        try {
            SshSession::login($ssh, $device, 'noc', 'pw');
            $this->fail('a changed host key must abort the login');
        } catch (RuntimeException $e) {
            $this->assertSame('SSH host key changed for 10.9.9.9', $e->getMessage());
        }

        $this->assertSame([], $ssh->logins, 'no credential may be sent to the new key');
        $this->assertSame('ssh-ed25519 AAAAFIRST', $device->fresh()->ssh_host_key, 'the pin must not be overwritten');
    }

    /** SSRF-04: a server whose key cannot be read/validated is not trusted either. */
    public function test_an_unreadable_key_aborts(): void
    {
        $device = Device::factory()->create(['ip_address' => '10.9.9.9']);
        $ssh = $this->fakeSsh(false);

        $this->expectException(RuntimeException::class);

        try {
            SshSession::login($ssh, $device, 'noc', 'pw');
        } finally {
            $this->assertSame([], $ssh->logins);
            $this->assertNull($device->fresh()->ssh_host_key);
        }
    }
}
