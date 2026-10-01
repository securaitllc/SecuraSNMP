<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DiscoveredDevice;
use App\Models\DiscoveryScan;
use App\Models\Site;
use App\Models\SnmpCredential;
use App\Services\DiscoveryScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiscoveryScannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_expand_subnets_excludes_network_and_broadcast(): void
    {
        $this->assertCount(254, DiscoveryScanner::expandSubnets(['10.0.0.0/24']));
        $this->assertCount(2, DiscoveryScanner::expandSubnets(['10.0.0.0/30']));
        $this->assertCount(2, DiscoveryScanner::expandSubnets(['10.0.0.0/31']));
        $this->assertCount(1, DiscoveryScanner::expandSubnets(['10.0.0.5/32']));

        $hosts = DiscoveryScanner::expandSubnets(['10.0.0.0/24']);
        $this->assertSame('10.0.0.1', $hosts[0]);
        $this->assertSame('10.0.0.254', $hosts[253]);
    }

    public function test_expand_subnets_skips_invalid_entries(): void
    {
        $this->assertSame([], DiscoveryScanner::expandSubnets(['not-a-cidr', '10.0.0.1', '10.0.0.0/40']));
    }

    public function test_classify_role_follows_the_hostname_convention(): void
    {
        $this->assertSame('switch', DiscoveryScanner::classifyRole('10.15.3.10'));
        $this->assertSame('edgeconnect', DiscoveryScanner::classifyRole('10.15.3.254'));
        $this->assertNull(DiscoveryScanner::classifyRole('10.15.3.11'));
    }

    public function test_guess_vendor_maps_snmp_identity(): void
    {
        $this->assertSame('juniper', DiscoveryScanner::guessVendor('Juniper Networks, Inc. ex4300', null));
        $this->assertSame('silverpeak', DiscoveryScanner::guessVendor('Silver Peak EdgeConnect', null));
        $this->assertSame('fortigate', DiscoveryScanner::guessVendor('FortiGate-100F FortiOS', null));
        $this->assertSame('juniper', DiscoveryScanner::guessVendor(null, '.1.3.6.1.4.1.2636.1.1.1'));
        $this->assertNull(DiscoveryScanner::guessVendor('Cisco IOS Software', null));
    }

    public function test_site_for_creates_then_reuses_a_placeholder_site(): void
    {
        $a = DiscoveryScanner::siteFor('10.20.5.10');
        $b = DiscoveryScanner::siteFor('10.20.5.254');

        $this->assertSame($a->id, $b->id);
        $this->assertSame('10.20.5.0/24', $a->subnet);
        $this->assertSame(1, Site::count());
    }

    public function test_run_records_discovered_devices_and_completes(): void
    {
        $cred = SnmpCredential::factory()->create();
        $scan = DiscoveryScan::factory()->create([
            'snmp_credential_id' => $cred->id,
            'subnets' => ['10.20.5.8/29'], // hosts .9 – .14, includes .10
        ]);

        $probe = function (string $ip) {
            if ($ip === '10.20.5.10') {
                return [
                    'sys_name' => 'sw-tampa-01',
                    'sys_descr' => 'Juniper Networks, Inc. ex4300-48t',
                    'sys_object_id' => '.1.3.6.1.4.1.2636.1.1.1',
                    'serial' => 'JN123456',
                ];
            }

            return null;
        };

        (new DiscoveryScanner)->run($scan, $probe);

        $scan->refresh();
        $this->assertSame('completed', $scan->status);
        $this->assertSame(6, $scan->hosts_total);
        $this->assertSame(1, $scan->hosts_responded);
        $this->assertSame(1, $scan->devices_found);

        $discovered = DiscoveredDevice::where('ip_address', '10.20.5.10')->firstOrFail();
        $this->assertSame('switch', $discovered->suggested_role);
        $this->assertSame('juniper', $discovered->vendor);
        $this->assertSame('ex4300-48t', $discovered->model);
        $this->assertSame('JN123456', $discovered->serial_number);
        $this->assertSame('new', $discovered->status);
        $this->assertNotNull($discovered->suggested_site_id);
    }

    public function test_run_flags_hosts_that_match_an_existing_device(): void
    {
        $site = Site::factory()->create();
        $existing = Device::factory()->create(['site_id' => $site->id, 'ip_address' => '10.20.5.10']);

        $cred = SnmpCredential::factory()->create();
        $scan = DiscoveryScan::factory()->create([
            'snmp_credential_id' => $cred->id,
            'subnets' => ['10.20.5.8/30'], // hosts .9, .10
        ]);

        (new DiscoveryScanner)->run($scan, fn (string $ip) => $ip === '10.20.5.10'
            ? ['sys_name' => 'x', 'sys_descr' => 'Juniper', 'sys_object_id' => null, 'serial' => null]
            : null);

        $discovered = DiscoveredDevice::where('ip_address', '10.20.5.10')->firstOrFail();
        $this->assertSame('existing', $discovered->status);
        $this->assertSame($existing->id, $discovered->matched_device_id);
    }
}
