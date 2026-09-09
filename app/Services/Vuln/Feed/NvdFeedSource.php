<?php

namespace App\Services\Vuln\Feed;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pulls CVEs for the fleet's vendors from the NVD 2.0 API and normalises each into
 * the catalog shape (a CVE record plus one affected-range row per CPE match).
 *
 * NVD is queried per vendor/product via virtualMatchString; affected versions come
 * from cpeMatch bounds (versionStart/EndIncluding/Excluding), which map directly onto
 * our inclusive/exclusive range model. CPE coverage for network gear is uneven
 * (strong for JunOS/FortiOS, sparse for EdgeConnect) — that is a property of NVD, not
 * this parser; every finding still carries its matched range as evidence.
 *
 * The HTTP fetch is injected so the parser is unit-testable against fixtures with no
 * network. Failures are swallowed per target: a feed outage leaves the existing
 * catalog intact rather than wiping it.
 */
class NvdFeedSource
{
    private const ENDPOINT = 'https://services.nvd.nist.gov/rest/json/cves/2.0';

    private const PAGE = 2000; // NVD max resultsPerPage

    private const MAX_PAGES = 8; // safety cap; logged if hit

    /** cpe vendor:product → our internal vendor tag. */
    private const TARGETS = [
        ['cpe' => 'cpe:2.3:o:juniper:junos', 'vendor' => 'juniper', 'product' => 'junos'],
        ['cpe' => 'cpe:2.3:o:fortinet:fortios', 'vendor' => 'fortigate', 'product' => 'fortios'],
        ['cpe' => 'cpe:2.3:o:arubanetworks:edgeconnect_enterprise', 'vendor' => 'silverpeak', 'product' => 'edgeconnect'],
    ];

    /**
     * @param  (callable(string): ?array)|null  $fetcher  url → decoded JSON (null on failure)
     * @param  int  $delayMs  pause between page requests (NVD rate limit); 0 in tests
     */
    public function __construct(private $fetcher = null, private int $delayMs = 6000, private ?string $apiKey = null)
    {
        $this->fetcher ??= fn (string $url): ?array => $this->httpGet($url);
    }

    /**
     * @return array<int, array<string, mixed>> normalized catalog entries
     */
    public function fetch(): array
    {
        $entries = [];

        foreach (self::TARGETS as $target) {
            try {
                foreach ($this->fetchTarget($target) as $cveId => $entry) {
                    // A CVE can surface under multiple targets; merge affect rows.
                    if (isset($entries[$cveId])) {
                        $entries[$cveId]['affects'] = array_merge($entries[$cveId]['affects'], $entry['affects']);
                    } else {
                        $entries[$cveId] = $entry;
                    }
                }
            } catch (Throwable $e) {
                Log::warning("NVD feed: target {$target['vendor']} failed: {$e->getMessage()}");
            }
        }

        return array_values($entries);
    }

    /** @return array<string, array<string, mixed>> cve_id => entry */
    private function fetchTarget(array $target): array
    {
        $out = [];
        $start = 0;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $url = self::ENDPOINT.'?'.http_build_query([
                'virtualMatchString' => $target['cpe'],
                'resultsPerPage' => self::PAGE,
                'startIndex' => $start,
            ]);

            $json = ($this->fetcher)($url);
            if (! $json || ! isset($json['vulnerabilities'])) {
                break;
            }

            foreach ($json['vulnerabilities'] as $item) {
                $entry = $this->normalize($item['cve'] ?? [], $target);
                if ($entry) {
                    $out[$entry['cve_id']] = $entry;
                }
            }

            $total = (int) ($json['totalResults'] ?? 0);
            $start += self::PAGE;
            if ($start >= $total) {
                break;
            }
            if ($page === self::MAX_PAGES - 1) {
                Log::info("NVD feed: {$target['vendor']} hit page cap ({$total} total) — increase MAX_PAGES if this vendor needs deeper history.");
            }

            $this->pause();
        }

        return $out;
    }

    /** One NVD `cve` object → normalized entry (or null if no relevant range). */
    private function normalize(array $cve, array $target): ?array
    {
        $id = $cve['id'] ?? null;
        if (! $id) {
            return null;
        }

        $affects = [];
        foreach ($cve['configurations'] ?? [] as $config) {
            foreach ($config['nodes'] ?? [] as $node) {
                foreach ($node['cpeMatch'] ?? [] as $match) {
                    $row = $this->rangeFromMatch($match, $target);
                    if ($row) {
                        // Dedupe identical ranges (NVD repeats them across nodes).
                        $key = ($row['from'] ?? '').'|'.($row['fixed'] ?? '').'|'.($row['exact'] ? 'p' : 'r');
                        $affects[$key] = $row;
                    }
                }
            }
        }

        if ($affects === []) {
            return null; // CVE mentions the vendor but not a concrete affected range
        }
        $affects = array_values($affects);

        return [
            'cve_id' => $id,
            'cvss' => $this->cvss($cve),
            'summary' => $this->description($cve),
            'published_at' => $cve['published'] ?? null,
            'source' => 'nvd',
            'affects' => $affects,
        ];
    }

    /** A vulnerable cpeMatch for this target → an affected-range row, else null. */
    private function rangeFromMatch(array $match, array $target): ?array
    {
        if (! ($match['vulnerable'] ?? false)) {
            return null;
        }

        $criteria = $match['criteria'] ?? '';
        if (! str_starts_with($criteria, $target['cpe'].':') && $criteria !== $target['cpe']) {
            return null; // a different product cited in the same CVE
        }

        $from = $match['versionStartIncluding'] ?? $match['versionStartExcluding'] ?? null;
        $fromIncl = isset($match['versionStartIncluding']);
        $fixed = $match['versionEndExcluding'] ?? $match['versionEndIncluding'] ?? null;
        $fixedIncl = isset($match['versionEndIncluding']);

        // No range bounds → an exact affected release enumerated in the CPE. Rebuild
        // the full version from the version + update segments (JunOS puts the release
        // in the update segment: 20.4 + r3-s2), matched by canonical release equality
        // (build/spin ignored) so 20.4R3-S2.6 matches but patched 20.4R3-S9 does not.
        if ($from === null && $fixed === null) {
            $parts = explode(':', $criteria);
            $base = $parts[5] ?? '*';
            $update = $parts[6] ?? '*';
            if ($base === '*' || $base === '-' || $base === '') {
                return null; // "all versions" with no bound — too broad to assert
            }
            $full = ($update !== '*' && $update !== '-' && $update !== '') ? "{$base}{$update}" : $base;

            return [
                'vendor' => $target['vendor'],
                'product' => $target['product'],
                'from' => $full,
                'from_incl' => true,
                'fixed' => null,
                'fixed_incl' => false,
                'exact' => true,
                'label' => "= {$full}",
            ];
        }

        return [
            'vendor' => $target['vendor'],
            'product' => $target['product'],
            'from' => $from,
            'from_incl' => $fromIncl,
            'fixed' => $fixed,
            'fixed_incl' => $fixedIncl,
            'exact' => false,
            'label' => $this->label($from, $fromIncl, $fixed, $fixedIncl),
        ];
    }

    private function label(?string $from, bool $fromIncl, ?string $fixed, bool $fixedIncl): string
    {
        if ($from !== null && $from === $fixed && $fromIncl && $fixedIncl) {
            return "= {$from}";
        }
        $lo = $from !== null ? ($fromIncl ? "≥ {$from}" : "> {$from}") : '';
        $hi = $fixed !== null ? ($fixedIncl ? "≤ {$fixed}" : "< {$fixed}") : '';

        return trim("{$lo} {$hi}") ?: 'all versions';
    }

    private function cvss(array $cve): ?float
    {
        $metrics = $cve['metrics'] ?? [];
        foreach (['cvssMetricV31', 'cvssMetricV30', 'cvssMetricV2'] as $key) {
            $score = $metrics[$key][0]['cvssData']['baseScore'] ?? null;
            if ($score !== null) {
                return (float) $score;
            }
        }

        return null;
    }

    private function description(array $cve): ?string
    {
        foreach ($cve['descriptions'] ?? [] as $d) {
            if (($d['lang'] ?? '') === 'en') {
                return $d['value'] ?? null;
            }
        }

        return null;
    }

    private function pause(): void
    {
        if ($this->delayMs > 0) {
            usleep($this->delayMs * 1000);
        }
    }

    private function httpGet(string $url): ?array
    {
        try {
            $req = Http::timeout(30)->acceptJson();
            if ($this->apiKey) {
                $req = $req->withHeaders(['apiKey' => $this->apiKey]);
            }
            $res = $req->get($url);

            return $res->successful() ? $res->json() : null;
        } catch (Throwable $e) {
            Log::warning("NVD feed HTTP error: {$e->getMessage()}");

            return null;
        }
    }
}
