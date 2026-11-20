<?php

namespace Tests\Unit;

use App\Support\WebhookUrl;
use Tests\TestCase;

class WebhookUrlTest extends TestCase
{
    public function test_it_rejects_loopback_and_metadata_and_bad_schemes(): void
    {
        $this->assertFalse(WebhookUrl::isSafe('http://127.0.0.1/x'));
        $this->assertFalse(WebhookUrl::isSafe('http://169.254.169.254/latest/meta-data/'));
        $this->assertFalse(WebhookUrl::isSafe('http://[::1]/x'));
        $this->assertFalse(WebhookUrl::isSafe('ftp://example.com/x'));
        $this->assertFalse(WebhookUrl::isSafe('file:///etc/passwd'));
        $this->assertFalse(WebhookUrl::isSafe(null));
    }

    public function test_it_allows_a_public_ip_and_private_lan(): void
    {
        $this->assertTrue(WebhookUrl::isSafe('https://140.82.112.3/hook'));   // public
        $this->assertTrue(WebhookUrl::isSafe('http://10.0.0.5/hook'));         // RFC1918 (on-prem)
    }

    public function test_an_ipv6_loopback_or_linklocal_literal_is_rejected(): void
    {
        // AAAA-range rebind targets, checked as literals (no DNS dependency).
        $this->assertFalse(WebhookUrl::isSafe('http://[fe80::1]/hook'));   // link-local
        $this->assertFalse(WebhookUrl::isSafe('http://[::1]/hook'));       // loopback
    }
}
