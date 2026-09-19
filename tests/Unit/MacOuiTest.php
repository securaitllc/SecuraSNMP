<?php

namespace Tests\Unit;

use App\Services\MacPoller;
use App\Support\MacOui;
use Tests\TestCase; // boots the app so resource_path() (OUI file lookup) works

class MacOuiTest extends TestCase
{
    public function test_it_resolves_a_registered_vendor(): void
    {
        $this->assertStringContainsString('Cisco', (string) MacOui::vendor('00:00:0C:11:22:33'));
        $this->assertStringContainsString('Juniper', (string) MacOui::vendor('3c6104aabbcc'));
    }

    public function test_it_normalizes_any_format(): void
    {
        $this->assertSame('00:00:0C:11:22:33', MacOui::normalize('00000c112233'));
        $this->assertSame('00:00:0C:11:22:33', MacOui::normalize('00-00-0c-11-22-33'));
    }

    public function test_unknown_or_short_returns_null(): void
    {
        $this->assertNull(MacOui::vendor('ZZ'));
        $this->assertNull(MacOui::vendor(null));
    }

    public function test_parse_fdb_pulls_vlan_mac_port(): void
    {
        $out = ".1.3.6.1.2.1.17.7.1.2.2.1.2.100.0.0.12.17.34.51 = INTEGER: 5\n"
             .".1.3.6.1.2.1.17.7.1.2.2.1.2.100.60.97.4.170.187.204 = INTEGER: 6\n"
             .".1.3.6.1.2.1.17.7.1.2.2.1.2.1.0.0.0.0.0.0 = INTEGER: 0\n"; // port 0 dropped
        $rows = MacPoller::parseFdb($out);
        $this->assertCount(2, $rows);
        $this->assertSame(['vlan' => 100, 'mac' => '00:00:0C:11:22:33', 'port' => 5], $rows[0]);
        $this->assertSame('3C:61:04:AA:BB:CC', $rows[1]['mac']);
    }

    public function test_parse_port_ifindex(): void
    {
        $map = MacPoller::parsePortIfIndex(".1.3.6.1.2.1.17.1.4.1.2.5 = INTEGER: 514\n.1.3.6.1.2.1.17.1.4.1.2.6 = INTEGER: 515\n");
        $this->assertSame([5 => 514, 6 => 515], $map);
    }
}
