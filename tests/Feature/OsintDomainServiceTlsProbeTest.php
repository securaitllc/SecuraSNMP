<?php

namespace Tests\Feature;

use App\Services\Osint\OsintDomainService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * SSRF-06: the TLS probe ran `openssl s_client -connect <host>:443` through the
 * container resolver, so a name pointing at 127.0.0.1 / 10.x / 169.254.169.254 made
 * the server open TLS to an internal service and report whether one answered. The
 * probe now connects only to a vetted public address from the public resolver.
 */
class OsintDomainServiceTlsProbeTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: OsintDomainService, 1: \ArrayObject} */
    private function service(string $aRecord): array
    {
        Http::fake(['*' => Http::response([], 200)]);
        $calls = new \ArrayObject;

        $svc = new OsintDomainService(function (array $cmd) use ($aRecord, $calls) {
            $calls->append($cmd);

            return match ($cmd[0]) {
                'dig' => ($cmd[4] ?? '') === 'A' ? $aRecord : '',
                default => '',
            };
        });

        return [$svc, $calls];
    }

    private function opensslCalls(\ArrayObject $calls): array
    {
        return array_values(array_filter($calls->getArrayCopy(), fn ($c) => $c[0] === 'openssl'));
    }

    public static function internalAnswers(): array
    {
        return [
            'loopback' => ['127.0.0.1'],
            'rfc1918' => ['10.1.2.3'],
            'metadata' => ['169.254.169.254'],
            'cgnat' => ['100.64.28.22'],
        ];
    }

    /**
     * SSRF-06: a name resolving internally is never probed.
     *
     * @dataProvider internalAnswers
     */
    public function test_an_internal_answer_is_not_probed(string $ip): void
    {
        [$svc, $calls] = $this->service($ip);

        $result = $svc->enrich('intranet.example.com');

        $this->assertSame([], $this->opensslCalls($calls));
        $this->assertFalse($result['tls']['has_cert']);
    }

    /** SSRF-06: a public answer is probed at the vetted IP, SNI = the hostname. */
    public function test_a_public_answer_is_probed_at_that_ip(): void
    {
        [$svc, $calls] = $this->service('93.184.215.14');

        $svc->enrich('www.example.com');

        $this->assertSame(
            [['openssl', 's_client', '-connect', '93.184.215.14:443', '-servername', 'www.example.com']],
            $this->opensslCalls($calls),
        );
    }

    /** SSRF-06: one internal answer among public ones poisons the set. */
    public function test_a_mixed_answer_is_not_probed(): void
    {
        [$svc, $calls] = $this->service("93.184.215.14\n127.0.0.1");

        $svc->enrich('www.example.com');

        $this->assertSame([], $this->opensslCalls($calls));
    }
}
