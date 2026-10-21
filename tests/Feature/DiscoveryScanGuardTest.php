<?php

namespace Tests\Feature;

use App\Jobs\RunDiscoveryScan;
use App\Models\SnmpCredential;
use App\Models\User;
use App\Services\DiscoveryScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * SSRF-03 / MISC-07: a scan accepted any CIDR down to /0. One request could make
 * the worker build a 4.29e9-element host array (OOM, alert jobs stall), or SNMP-
 * sweep loopback, link-local metadata or arbitrary internet ranges in the
 * customer's name.
 */
class DiscoveryScanGuardTest extends TestCase
{
    use RefreshDatabase;

    private function launch(User $user, array $subnets, array $extra = []): \Illuminate\Testing\TestResponse
    {
        Queue::fake();

        return $this->actingAs($user)->postJson('/api/discovery/scans', [
            'snmp_credential_id' => SnmpCredential::factory()->create()->id,
            'subnets' => $subnets,
            ...$extra,
        ]);
    }

    public static function refused(): array
    {
        return [
            'slash zero' => ['0.0.0.0/0'],
            'slash eight' => ['10.0.0.0/8'],
            'slash fifteen' => ['10.0.0.0/15'],
            'loopback' => ['127.0.0.0/24'],
            'link-local' => ['169.254.0.0/16'],
            'metadata' => ['169.254.169.254/32'],
            'multicast' => ['224.0.0.0/24'],
            'this network' => ['0.0.0.0/24'],
            'broadcast' => ['255.255.255.255/32'],
        ];
    }

    /**
     * SSRF-03: too wide or never-a-device ranges are refused, even for super_admin.
     *
     * @dataProvider refused
     */
    public function test_unsafe_ranges_are_refused(string $cidr): void
    {
        $super = User::factory()->create(['role' => 'super_admin']);

        $this->launch($super, [$cidr], ['allow_public' => true])->assertUnprocessable();

        Queue::assertNotPushed(RunDiscoveryScan::class);
    }

    /** SSRF-03: private ranges at /16 and narrower still scan. */
    public function test_private_ranges_are_accepted(): void
    {
        $admin = User::factory()->admin()->create();

        $this->launch($admin, ['10.15.0.0/16'])->assertCreated();
        $this->launch($admin, ['172.16.4.0/22', '192.168.1.0/24'])->assertCreated();
        $this->launch($admin, ['100.64.10.0/24'])->assertCreated();
    }

    /** SSRF-03: an admin cannot sweep the internet, opt-in or not. */
    public function test_an_admin_cannot_scan_public_ranges(): void
    {
        $admin = User::factory()->admin()->create();

        $this->launch($admin, ['8.8.8.0/24'])->assertUnprocessable();
        $this->launch($admin, ['8.8.8.0/24'], ['allow_public' => true])->assertUnprocessable();
    }

    /** SSRF-03: super_admin must opt in explicitly to scan a public range. */
    public function test_super_admin_can_scan_public_only_with_the_opt_in(): void
    {
        $super = User::factory()->create(['role' => 'super_admin']);

        $this->launch($super, ['198.51.100.0/24'])->assertUnprocessable();
        $this->launch($super, ['198.51.100.0/24'], ['allow_public' => true])->assertCreated();
    }

    /** MISC-07: the whole scan is capped at 65,536 addresses. */
    public function test_the_total_address_count_is_capped(): void
    {
        $admin = User::factory()->admin()->create();

        $this->launch($admin, ['10.0.0.0/16', '10.1.0.0/24'])->assertUnprocessable();
    }

    /** MISC-07: expansion is lazy and never yields more than the cap. */
    public function test_expansion_is_lazy_and_capped(): void
    {
        // An eager expandSubnets() builds 4.29e9 strings for /0 and SIGKILLs the runner.
        if ((string) (new \ReflectionMethod(DiscoveryScanner::class, 'expandSubnets'))->getReturnType() === 'array') {
            $this->fail('expandSubnets() still materialises the whole host array');
        }

        $hosts = DiscoveryScanner::expandSubnets(['0.0.0.0/0']);

        $this->assertInstanceOf(\Generator::class, $hosts);
        $this->assertSame(DiscoveryScanner::MAX_HOSTS, iterator_count($hosts));
        $this->assertSame(DiscoveryScanner::MAX_HOSTS, DiscoveryScanner::countHosts(['0.0.0.0/0']));
        $this->assertSame(65536, DiscoveryScanner::MAX_HOSTS);
    }
}
