<?php

namespace Tests\Feature;

use App\Models\NotificationChannel;
use App\Models\User;
use App\Support\WebhookUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * SSRF-02: the guard resolved the host, then curl resolved it again on its own. A
 * TTL-0 name could answer public for the check and loopback for the connect. The
 * vetted address is now pinned onto the request, so the check and the connect see
 * the same IP.
 */
class WebhookUrlPinTest extends TestCase
{
    use RefreshDatabase;

    private function requireDns(string $host): void
    {
        if (! gethostbynamel($host)) {
            $this->markTestSkipped("no DNS for {$host} on this host");
        }
    }

    /** SSRF-02: a hostname comes back with the address it was vetted against. */
    public function test_vet_returns_the_resolved_address_to_pin(): void
    {
        $this->requireDns('google.com');

        [$url, $ip] = WebhookUrl::vet('https://google.com/hook');

        $this->assertSame('https://google.com/hook', $url);
        $this->assertNotNull($ip);
        $this->assertContains($ip, gethostbynamel('google.com'));
    }

    /** SSRF-02: a literal IP is what curl dials anyway — nothing to pin. */
    public function test_a_literal_ip_needs_no_pin(): void
    {
        $this->assertSame(['https://10.11.10.57/hook', null], WebhookUrl::vet('https://10.11.10.57/hook'));
    }

    /** SSRF-02: an unsafe URL vets to null, same verdict as isSafe(). */
    public function test_an_unsafe_url_does_not_vet(): void
    {
        $this->assertNull(WebhookUrl::vet('https://169.254.169.254/latest/meta-data/'));
        $this->assertNull(WebhookUrl::vet('https://0x7f000001/hook'));
        $this->assertNull(WebhookUrl::vet('file:///etc/passwd'));
    }

    /** SSRF-02: the outbound request carries CURLOPT_RESOLVE for host:port:vetted-ip. */
    public function test_delivery_pins_the_vetted_address(): void
    {
        $this->requireDns('google.com');

        $resolve = null;
        Http::fake(function ($request, array $options) use (&$resolve) {
            $resolve = $options['curl'][CURLOPT_RESOLVE] ?? null;

            return Http::response('', 204);
        });

        $channel = NotificationChannel::factory()->create([
            'type' => 'webhook',
            'config' => ['url' => 'https://google.com:8443/hook'],
        ]);

        $this->actingAs(User::factory()->admin()->create())
            ->postJson("/api/notification-channels/{$channel->id}/test")
            ->assertJsonPath('status', 'sent');

        $this->assertIsArray($resolve, 'the request must pin the resolved address');
        $this->assertCount(1, $resolve);
        [$host, $port, $ip] = explode(':', $resolve[0], 3);
        $this->assertSame('google.com', $host);
        $this->assertSame('8443', $port);
        $this->assertContains(trim($ip, '[]'), gethostbynamel('google.com'));
    }
}
