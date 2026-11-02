<?php

namespace App\Services\Vuln\Feed;

use App\Services\Vuln\PlatformMatcher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
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

    /**
     * Records per request.
     *
     * NVD's maximum is 2000, and asking for it is what took the collector down on
     * 15 September. A single NVD CVE object carries hundreds of cpeMatch rows; two
     * thousand of them decode into a PHP structure several times the size of the
     * already-large JSON, and the spike starved the circuit poller badly enough that
     * its pings blew their timeout and 232 of 255 circuits reported down.
     *
     * Five hundred keeps the same coverage across more requests, each one small
     * enough that the decode cannot threaten anything else on the host. A background
     * catalog refresh must never be able to do that — the same isolation rule the
     * pollers already follow.
     */
    private const PAGE = 500;

    /** Safety cap; logged if hit. Raised with PAGE so total coverage is unchanged. */
    private const MAX_PAGES = 32;

    /** Decoded pages above this are refused outright rather than normalised. */
    private const MAX_PAGE_BYTES = 24 * 1024 * 1024;

    /**
     * cpe vendor:product → our internal vendor tag.
     *
     * EdgeConnect needs four entries because HPE has renamed the product twice and NVD
     * keeps each generation's CPE alive with its own CVEs. The original single entry here
     * was `cpe:2.3:o:arubanetworks:edgeconnect_enterprise`, which matches nothing at all:
     * EdgeConnect is an application (`:a:`) CPE, not an OS (`:o:`) one. That one wrong
     * character meant the nightly pull returned zero EdgeConnect CVEs for the life of the
     * feature while reporting success, and 133 appliances were scored against an empty
     * catalog. Verified against the live API when this was fixed:
     *
     *   o:arubanetworks:edgeconnect_enterprise ............  0 CVEs  (what we asked for)
     *   o:hpe:edgeconnect_operating_system ................ 34 CVEs  (current ECOS name)
     *   a:arubanetworks:edgeconnect_enterprise ............ 25 CVEs  (previous name)
     *   o:silver-peak:unity_edgeconnect_sd-wan_firmware ...  7 CVEs  (original name)
     *   a:arubanetworks:edgeconnect_sd-wan_orchestrator ... 38 CVEs  (Orchestrator)
     *
     * None of these supersedes the others — NVD never back-fills an old CPE — so all of
     * them have to be asked. Orchestrator is carried under its own `orchestrator` product
     * so its ranges can never be tested against appliance firmware: the two share the
     * `silverpeak` vendor tag and have near-identical version numbering (9.5.9 against
     * 9.5.9.0), which is precisely the coincidence that would manufacture false findings.
     * Nothing maps to that product until the Orchestrator is a device in inventory.
     */
    private const TARGETS = [
        ['cpe' => 'cpe:2.3:o:juniper:junos', 'vendor' => 'juniper', 'product' => 'junos'],
        ['cpe' => 'cpe:2.3:o:fortinet:fortios', 'vendor' => 'fortigate', 'product' => 'fortios'],
        ['cpe' => 'cpe:2.3:o:hpe:edgeconnect_operating_system', 'vendor' => 'silverpeak', 'product' => 'edgeconnect'],
        ['cpe' => 'cpe:2.3:a:arubanetworks:edgeconnect_enterprise', 'vendor' => 'silverpeak', 'product' => 'edgeconnect'],
        ['cpe' => 'cpe:2.3:o:silver-peak:unity_edgeconnect_sd-wan_firmware', 'vendor' => 'silverpeak', 'product' => 'edgeconnect'],
        ['cpe' => 'cpe:2.3:a:arubanetworks:edgeconnect_sd-wan_orchestrator', 'vendor' => 'silverpeak', 'product' => 'orchestrator'],
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
     * Per-target outcome of the last fetch(), for the drift guard.
     *
     * A CPE string is a guess about someone else's naming, and the vendors change those
     * names. When one goes stale the request still succeeds — HTTP 200, zero results —
     * so the only way the staleness ever surfaces is if somebody records that a target
     * came back empty and treats that as a fault rather than as good news.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $reports = [];

    /** @return array<int, array<string, mixed>> */
    public function reports(): array
    {
        return $this->reports;
    }

    /**
     * Every target's entries at once.
     *
     * Convenient for tests and for callers that want the whole normalized set, but it
     * holds all of it in memory — 126 MB at the catalog's current size. Production
     * uses fetchByVendor() instead.
     *
     * @return array<int, array<string, mixed>> normalized catalog entries
     */
    public function fetch(): array
    {
        $all = [];

        $this->fetchByVendor(function (array $entries) use (&$all) {
            foreach ($entries as $entry) {
                $all[] = $entry;
            }
        });

        return $all;
    }

    /**
     * Fetch one vendor at a time, handing each vendor's entries and target reports to
     * $sink before moving on.
     *
     * fetch() accumulated all six targets before returning anything, which peaked at
     * 126 MB — inside a 128 MB process that had already run the starter seeder and
     * still had to write the catalog. It was killed every cycle, so the catalog write
     * and the assessment that follows it were never reached at all.
     *
     * Vendor is the right grouping unit, not target: CveCatalogUpdater replaces a CVE's
     * ranges wholesale, so two targets that share a CVE must be applied together or the
     * second wipes the first's rows. The only CVEs shared across targets are the
     * EdgeConnect generations and the Orchestrator, which are all one vendor — so
     * grouping by vendor preserves the merge exactly while making the peak one vendor's
     * worth instead of the whole feed's.
     *
     * @param  callable(array<int, array<string, mixed>>, array<int, array<string, mixed>>): void  $sink
     *                                                                                                    receives (entries, reports) for one vendor
     */
    public function fetchByVendor(callable $sink): void
    {
        $this->reports = [];

        foreach ($this->targetsByVendor() as $targets) {
            $entries = [];
            $reports = [];

            foreach ($targets as $target) {
                $report = $this->fetchOne($target, $entries);
                $reports[] = $report;
                $this->reports[] = $report;
            }

            $sink(array_values($entries), $reports);

            // Release this vendor's decoded entries before the next vendor's pages are
            // decoded, so the peak is one group and not the running total.
            unset($entries);
        }
    }

    /** @return array<string, array<int, array<string, string>>> vendor => its targets */
    private function targetsByVendor(): array
    {
        $grouped = [];

        foreach (self::TARGETS as $target) {
            $grouped[$target['vendor']][] = $target;
        }

        return $grouped;
    }

    /**
     * One target, merged into $entries. Returns its report; never throws.
     *
     * @param  array<string, array<string, mixed>>  $entries  keyed by cve_id, mutated
     * @return array<string, mixed>
     */
    private function fetchOne(array $target, array &$entries): array
    {
        $report = [
            'cpe' => $target['cpe'],
            'vendor' => $target['vendor'],
            'product' => $target['product'],
            'status' => 'ok',
            'cve_count' => 0,
            'total_results' => 0,
            'message' => null,
        ];

        try {
            $found = $this->fetchTarget($target, $report);
            foreach ($found as $cveId => $entry) {
                // A CVE can surface under multiple targets; merge affect rows. This
                // is how a CVE that hits both ECOS and the Orchestrator keeps one
                // record with a range row per product.
                if (isset($entries[$cveId])) {
                    $entries[$cveId]['affects'] = array_merge($entries[$cveId]['affects'], $entry['affects']);
                } else {
                    $entries[$cveId] = $entry;
                }
            }
            $report['cve_count'] = count($found);
            $report['status'] = $this->statusFor($report);
            $report['message'] = $this->messageFor($report);
        } catch (Throwable $e) {
            Log::warning("NVD feed: target {$target['cpe']} failed: {$e->getMessage()}");
            $report['status'] = 'error';
            $report['message'] = $e->getMessage();
        }

        if ($report['status'] !== 'ok') {
            Log::warning("NVD feed: target {$target['cpe']} — {$report['status']}: {$report['message']}");
        }

        return $report;
    }

    /**
     * Classify a finished target.
     *
     * `empty` is the one that matters: the API answered normally and said this product
     * has no CVEs at all, which for a maintained network product means the CPE string is
     * wrong, not that the product is flawless. `no_ranges` is the softer cousin — NVD
     * knows the CVEs but has not attached version applicability to any of them, which is
     * its actual current state for the 2025 EdgeConnect disclosures. Neither can be
     * allowed to read as a clean bill of health.
     */
    private function statusFor(array $r): string
    {
        if ($r['total_results'] === 0) {
            return 'empty';
        }

        return $r['cve_count'] === 0 ? 'no_ranges' : 'ok';
    }

    private function messageFor(array $r): ?string
    {
        return match ($r['status']) {
            'empty' => 'NVD returned no CVEs for this CPE — the product name is almost certainly stale.',
            'no_ranges' => "NVD knows {$r['total_results']} CVEs for this CPE but none carry an affected-version range, so none can be matched.",
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $report  mutated with the target's totalResults
     * @return array<string, array<string, mixed>> cve_id => entry
     */
    private function fetchTarget(array $target, array &$report): array
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
                // A failed first page is a transport fault, not an empty product. Saying
                // otherwise would make an NVD outage look like a stale CPE and send
                // whoever reads the warning after the wrong thing.
                if ($page === 0) {
                    throw new RuntimeException('request failed or returned an unexpected body');
                }
                break;
            }

            foreach ($json['vulnerabilities'] as $item) {
                $entry = $this->normalize($item['cve'] ?? [], $target);
                if ($entry) {
                    $out[$entry['cve_id']] = $entry;
                }
            }

            $total = (int) ($json['totalResults'] ?? 0);
            $report['total_results'] = $total;
            // Release the decoded page before requesting the next one. Without this the
            // peak is however many pages the GC has not yet reclaimed, not one.
            unset($json);

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
            // A configuration with "operator": "AND" means the OS is only vulnerable on
            // the hardware its sibling node names. Reading that gate is the difference
            // between an SRX firewall CVE staying on SRX firewalls and landing on every
            // EX access switch in the estate.
            $platforms = $this->platformsFor($config);

            foreach ($config['nodes'] ?? [] as $node) {
                foreach ($node['cpeMatch'] ?? [] as $match) {
                    $row = $this->rangeFromMatch($match, $target);
                    if ($row) {
                        $row['platforms'] = $platforms;
                        // Dedupe identical ranges (NVD repeats them across nodes). The
                        // platform gate is part of the identity: the same range may be
                        // cited once unrestricted and once per hardware family, and the
                        // unrestricted row has to survive so it keeps applying to all.
                        $key = ($row['from'] ?? '').'|'.($row['fixed'] ?? '').'|'
                            .($row['exact'] ? 'p' : 'r').'|'.implode(',', $platforms ?? []);
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

    /**
     * Hardware families an AND-gated configuration restricts its OS match to.
     *
     * Returns null for a configuration that carries no hardware gate — the common case,
     * and the one that must keep applying to every device.
     *
     * @return array<int, string>|null
     */
    private function platformsFor(array $config): ?array
    {
        if (($config['operator'] ?? null) !== 'AND') {
            return null;
        }

        $families = [];
        foreach ($config['nodes'] ?? [] as $node) {
            foreach ($node['cpeMatch'] ?? [] as $match) {
                $criteria = $match['criteria'] ?? '';
                // Hardware CPEs carry part `h`. The vulnerable OS node is part `o`/`a`
                // and is what the gate applies TO, not part of the gate.
                if (! str_starts_with($criteria, 'cpe:2.3:h:')) {
                    continue;
                }
                $family = PlatformMatcher::familyOfCpeProduct(explode(':', $criteria)[4] ?? '');
                if ($family !== null) {
                    $families[$family] = true;
                }
            }
        }

        // An AND configuration that names no hardware restricts nothing we can act on.
        return $families === [] ? null : array_keys($families);
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
            // A JunOS page is ~10 MB. The old 30-second budget was not enough to receive
            // it on a slow link, and the drift guard caught it on its first run:
            //
            //   cURL error 28: Operation timed out after 30000 milliseconds with
            //   3677832 out of 10046258 bytes received
            //
            // Before the guard existed that failure was a silent `break` — the target
            // simply contributed nothing and the run still printed a healthy total, so
            // JunOS coverage quietly depended on whichever past pull had last managed to
            // finish. The timeout is about wall time, not memory; the thing that actually
            // hurt the host on 15 September was decoding a 2000-record page, and that is
            // still bounded by PAGE and MAX_PAGE_BYTES. One retry covers a transient
            // stall without turning a daily job into an unbounded one.
            $req = Http::timeout((int) config('vuln.nvd_timeout', 120))
                ->connectTimeout(10)
                ->retry(2, 2000, throw: false)
                ->acceptJson();
            if ($this->apiKey) {
                $req = $req->withHeaders(['apiKey' => $this->apiKey]);
            }
            $res = $req->get($url);
            if (! $res->successful()) {
                return null;
            }

            // Refuse an oversized body BEFORE decoding it. json() on a body this large
            // is the allocation that hurt, so the check has to come first — measuring
            // the decoded result would mean having already paid for it.
            $body = $res->body();
            if (strlen($body) > self::MAX_PAGE_BYTES) {
                Log::warning('NVD feed: page of '.strlen($body).' bytes exceeds the cap — skipped rather than decoded.');

                return null;
            }

            return json_decode($body, true) ?: null;
        } catch (Throwable $e) {
            Log::warning("NVD feed HTTP error: {$e->getMessage()}");

            return null;
        }
    }
}
