<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OsintLookup;
use App\Services\Osint\OsintDomainService;
use App\Services\Osint\OsintIpService;
use App\Services\Osint\OsintPhoneService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class OsintController extends Controller
{
    public function __construct(
        private OsintDomainService $domains,
        private OsintIpService $ips,
        private OsintPhoneService $phones,
    ) {}

    public function domain(Request $request): JsonResponse
    {
        $target = (string) $request->validate(['target' => ['required', 'string', 'max:255']])['target'];
        try {
            $result = $this->domains->enrich($target);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Enrich the first resolved IP inline so the operator sees hosting without a second search.
        $firstIp = $result['dns']['a'][0] ?? null;
        if ($firstIp) {
            $result['ip_enrichment'] = $this->ips->enrich($firstIp);
        }

        $iocs = $this->domainIocs($result);
        $this->log($request, 'domain', $result['host'], $result['risk']['verdict'],
            "{$result['risk']['score']}/100 · ".count($result['subdomains']).' subdomains');

        return response()->json(['result' => $result, 'iocs' => $iocs]);
    }

    public function ip(Request $request): JsonResponse
    {
        $target = (string) $request->validate(['target' => ['required', 'string', 'max:64']])['target'];
        try {
            $result = $this->ips->enrich($target);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $verdict = ($result['is_threat'] ?? false) ? 'malicious' : 'clean';
        $this->log($request, 'ip', $result['ip'], $verdict, $result['org'] ?? null);

        return response()->json(['result' => $result, 'iocs' => [
            ['type' => 'ip', 'value' => $result['ip'], 'confidence' => 'high', 'source' => 'ipdata'],
        ]]);
    }

    public function phone(Request $request): JsonResponse
    {
        $target = (string) $request->validate(['target' => ['required', 'string', 'max:32']])['target'];
        try {
            $result = $this->phones->lookup($target);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $this->log($request, 'phone', $result['phone'], $result['verdict'] ?? 'error',
            isset($result['fraud_score']) ? "fraud {$result['fraud_score']}" : null);

        return response()->json(['result' => $result, 'iocs' => [
            ['type' => 'phone', 'value' => $result['phone'], 'confidence' => ($result['fraud_score'] ?? 0) >= 75 ? 'high' : 'medium', 'source' => 'ipqs'],
        ]]);
    }

    private function domainIocs(array $r): array
    {
        $iocs = [
            ['type' => 'domain', 'value' => $r['base'], 'confidence' => 'high', 'source' => 'whois'],
        ];
        if ($r['host'] !== $r['base']) {
            $iocs[] = ['type' => 'host', 'value' => $r['host'], 'confidence' => 'high', 'source' => 'dns'];
        }
        foreach ($r['dns']['a'] as $ip) {
            $iocs[] = ['type' => 'ip', 'value' => $ip, 'confidence' => 'high', 'source' => 'dns'];
        }
        if ($asn = $r['ip_enrichment']['asn'] ?? null) {
            $iocs[] = ['type' => 'asn', 'value' => 'AS'.$asn, 'confidence' => 'medium', 'source' => 'ipdata'];
        }

        return $iocs;
    }

    private function log(Request $request, string $kind, string $target, ?string $verdict, ?string $summary): void
    {
        OsintLookup::create([
            'actor_id' => $request->user()->id,
            'kind' => $kind,
            'target' => $target,
            'verdict' => $verdict,
            'summary' => $summary,
        ]);
    }
}
