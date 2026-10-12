<?php

namespace App\Services\Osint;

use App\Models\OsintIntegration;
use Illuminate\Support\Facades\Http;

/** IP enrichment via ipdata.co (geo, ASN, proxy/VPN/threat flags). Key stored encrypted. */
class OsintIpService
{
    public function enrich(string $rawIp): array
    {
        $ip = OsintValidator::ip($rawIp);
        $key = OsintIntegration::keyFor('ipdata');
        if (! $key) {
            return ['ip' => $ip, 'configured' => false];
        }

        try {
            $r = Http::timeout(10)->acceptJson()->get("https://api.ipdata.co/{$ip}", ['api-key' => $key]);
        } catch (\Throwable $e) {
            return ['ip' => $ip, 'configured' => true, 'error' => 'ipdata request failed'];
        }
        if (! $r->ok()) {
            return ['ip' => $ip, 'configured' => true, 'error' => "ipdata {$r->status()}"];
        }
        $d = $r->json();
        $threat = $d['threat'] ?? [];

        return [
            'ip' => $ip,
            'configured' => true,
            'city' => $d['city'] ?? null,
            'country' => $d['country_name'] ?? null,
            'asn' => $d['asn']['asn'] ?? null,
            'org' => $d['asn']['name'] ?? null,
            'route' => $d['asn']['route'] ?? null,
            'is_proxy' => (bool) ($threat['is_proxy'] ?? false),
            'is_vpn' => (bool) ($threat['is_anonymous'] ?? false),
            'is_tor' => (bool) ($threat['is_tor'] ?? false),
            'is_threat' => (bool) ($threat['is_threat'] ?? false),
        ];
    }
}
