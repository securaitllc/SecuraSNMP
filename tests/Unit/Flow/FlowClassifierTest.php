<?php

namespace Tests\Unit\Flow;

use App\Services\Flow\FlowClassifier;
use Tests\TestCase;

class FlowClassifierTest extends TestCase
{
    private FlowClassifier $c;

    protected function setUp(): void
    {
        parent::setUp();
        $this->c = new FlowClassifier;
    }

    public function test_protocol_numbers_map_to_names(): void
    {
        $this->assertSame('tcp', $this->c->protocol(6));
        $this->assertSame('udp', $this->c->protocol(17));
        $this->assertSame('esp', $this->c->protocol(50));
        $this->assertSame('proto-99', $this->c->protocol(99));
    }

    public function test_ip_intelligence_names_the_app_before_the_port(): void
    {
        // 52.96.x is Microsoft 365 — named by IP even though the port is a generic 443.
        [$app, $cat] = $this->c->classify('52.96.7.44', '10.86.10.42', 443, 51000, 'tcp');
        $this->assertSame('Microsoft 365', $app);
        $this->assertSame('SaaS', $cat);
    }

    public function test_well_known_port_names_the_app_when_no_ip_match(): void
    {
        [$app] = $this->c->classify('8.8.8.8', '10.86.10.5', 53, 34000, 'udp');
        $this->assertSame('DNS', $app);
    }

    public function test_esp_is_ipsec_vpn_regardless_of_port(): void
    {
        [$app, $cat] = $this->c->classify('203.0.113.9', '10.86.20.8', null, null, 'esp');
        $this->assertSame('VPN (IPsec)', $app);
        $this->assertSame('VPN', $cat);
    }

    public function test_unknown_traffic_is_unclassified(): void
    {
        [$app, $cat] = $this->c->classify('198.51.100.5', '10.86.10.9', 61234, 55000, 'tcp');
        $this->assertSame('Unclassified', $app);
        $this->assertSame('Other', $cat);
    }
}
