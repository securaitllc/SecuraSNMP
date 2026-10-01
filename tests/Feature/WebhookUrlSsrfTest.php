<?php

namespace Tests\Feature;

use App\Support\WebhookUrl;
use Tests\TestCase;

/**
 * An address does not have to look like one.
 *
 * inet_aton — what the HTTP client ends up using — accepts hex, octal and short
 * forms, all of which FILTER_VALIDATE_IP rejects as IPs. They therefore fell to
 * the hostname path, failed to resolve, and were allowed as "not an SSRF target",
 * while the client dialled loopback.
 */
class WebhookUrlSsrfTest extends TestCase
{
    public static function bypassProvider(): array
    {
        return [
            'hex' => ['https://0x7f000001/hook'],
            'octal' => ['https://0177.0.0.1/hook'],
            'decimal' => ['https://2130706433/hook'],
            'short form' => ['https://127.1/hook'],
            'two part' => ['https://127.0.1/hook'],
            'dotted quad' => ['https://127.0.0.1/hook'],
            'cloud metadata' => ['https://169.254.169.254/latest/meta-data/'],
            'hex metadata' => ['https://0xa9fea9fe/latest/meta-data/'],
            'ipv6 loopback' => ['https://[::1]/hook'],
        ];
    }

    /** @dataProvider bypassProvider */
    public function test_numeric_loopback_and_link_local_forms_are_blocked(string $url): void
    {
        $this->assertFalse(WebhookUrl::isSafe($url), "{$url} should be rejected");
    }

    public function test_real_hosts_and_private_lan_are_still_allowed(): void
    {
        // Private LAN is deliberately allowed — an on-prem webhook receiver is the
        // normal case here. 0x0a0b0c0d is 10.11.12.13, so it must survive too.
        $this->assertTrue(WebhookUrl::isSafe('https://10.11.10.57/hook'));
        $this->assertTrue(WebhookUrl::isSafe('https://0x0a0b0c0d/hook'));

        // A hostname whose first label merely looks numeric is a hostname.
        $this->assertTrue(WebhookUrl::isSafe('https://1e100.net/hook'));
    }

    public function test_non_http_schemes_are_still_rejected(): void
    {
        $this->assertFalse(WebhookUrl::isSafe('file:///etc/passwd'));
        $this->assertFalse(WebhookUrl::isSafe('gopher://127.0.0.1/'));
        $this->assertFalse(WebhookUrl::isSafe(null));
    }
}
