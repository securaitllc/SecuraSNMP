<?php

namespace App\Services\Vuln;

use App\Models\Device;
use Illuminate\Support\Facades\DB;

/**
 * The CVE catalog's affected-ranges, deduplicated to distinct constraints.
 *
 * Correlation used to load `CveAffect::all()` as Eloquent models and test every row
 * against every device. That was affordable while the catalog held ~1,100 ranges. Fixing
 * the EdgeConnect CPE and adding the Orchestrator target took it to **65,180 rows**, and
 * at that size the old shape is not a slow path, it is a hazard:
 *
 *   CveAffect::all()  →  130 MB resident, 170 MB peak
 *   304 devices × 65,180 rows  →  19.8 million version comparisons per sweep
 *
 * A background catalog job must never be able to allocate like that. The last time
 * something in this codebase did — decoding a 2000-record NVD page — it starved the
 * circuit poller badly enough that 232 of 255 circuits reported down. The vulnerability
 * sweep runs beside those same pollers.
 *
 * The saving is that the rows are enormously repetitive. JunOS CVEs enumerate exact
 * releases, and the same release appears under CVE after CVE, so the catalog's 65,180
 * rows collapse to **1,639 distinct constraints** — a 40× reduction. Evaluating each
 * distinct constraint once per device and then fanning the hit out to the CVEs that share
 * it is exactly equivalent and two orders of magnitude cheaper.
 *
 * `constraint_label` is a pure function of the range, verified against the live catalog
 * (1,639 distinct keys, 1,639 distinct key+label pairs), so one label per constraint is
 * exact rather than an approximation.
 */
class ConstraintSet
{
    /** Rows pulled per chunk while building. Bounds peak memory during construction. */
    private const CHUNK = 5000;

    /** @var array<string, array<int, array<string, mixed>>> bucket key => distinct constraints */
    private array $byBucket = [];

    /** @var array<string, array<int, string>> constraint key => cve ids sharing it */
    private array $cves = [];

    private function __construct() {}

    /** Constraints for every vendor/product pair that has devices in inventory. */
    public static function forFleet(): self
    {
        return self::build(null);
    }

    /** Constraints for just this device's vendor — used by the single-device re-assess. */
    public static function forDevice(Device $device): self
    {
        return self::build($device->vendor);
    }

    private static function build(?string $vendor): self
    {
        $set = new self;

        DB::table('cve_affects')
            ->when($vendor !== null, fn ($q) => $q->where('vendor', $vendor))
            ->orderBy('id')
            // Chunked so the peak is one chunk plus the deduplicated result, never the
            // whole table. Plain stdClass rows, not Eloquent models: hydrating 65,180
            // models is most of the 130 MB this class exists to avoid.
            ->chunk(self::CHUNK, function ($rows) use ($set) {
                foreach ($rows as $r) {
                    $key = implode('|', [
                        $r->vendor,
                        $r->product ?? '',
                        $r->version_introduced ?? '',
                        $r->version_fixed ?? '',
                        (int) $r->introduced_inclusive,
                        (int) $r->fixed_inclusive,
                        (int) $r->exact_match,
                        $r->platforms ?? '',
                    ]);

                    if (! isset($set->cves[$key])) {
                        // A null product is vendor-wide: the starter catalog predates the
                        // product column and its ranges must keep applying.
                        $set->byBucket[CatalogCoverage::key($r->vendor, $r->product)][] = [
                            'key' => $key,
                            'vendor' => $r->vendor,
                            'introduced' => $r->version_introduced,
                            'fixed' => $r->version_fixed,
                            'introduced_inclusive' => (bool) $r->introduced_inclusive,
                            'fixed_inclusive' => (bool) $r->fixed_inclusive,
                            'exact' => (bool) $r->exact_match,
                            // Stored as JSON by the model cast; this query is toBase()
                            // so it arrives as a raw string and has to be decoded here.
                            'platforms' => $r->platforms ? json_decode($r->platforms, true) : null,
                            'label' => $r->constraint_label,
                        ];
                        $set->cves[$key] = [];
                    }

                    $set->cves[$key][] = $r->cve_id;
                }
            });

        return $set;
    }

    /**
     * Every CVE whose affected range covers this device's firmware.
     *
     * @return array<string, string> cve_id => the constraint that matched, as evidence
     */
    public function match(Device $device): array
    {
        $version = $device->os_version;
        if (! $version) {
            return [];
        }

        $product = CatalogCoverage::productFor($device->vendor);
        $buckets = [
            CatalogCoverage::key($device->vendor, $product),
            // vendor-wide rows, stored under a null product
            CatalogCoverage::key($device->vendor, null),
        ];

        $matched = [];

        foreach ($buckets as $bucket) {
            foreach ($this->byBucket[$bucket] ?? [] as $c) {
                // Hardware gate first: it is a cheap array check and it is what keeps an
                // SRX-only CVE off an EX switch.
                if (! PlatformMatcher::applies($c['platforms'], $device->model)) {
                    continue;
                }

                $hit = $c['exact']
                    ? VersionMatcher::matchesExact($device->vendor, $version, (string) $c['introduced'])
                    : VersionMatcher::inRange(
                        $device->vendor,
                        $version,
                        $c['introduced'],
                        $c['fixed'],
                        $c['introduced_inclusive'],
                        $c['fixed_inclusive'],
                    );

                if (! $hit) {
                    continue;
                }

                $evidence = $c['label'] ?? trim(($c['introduced'] ?? '').'–'.($c['fixed'] ?? ''), '–');
                foreach ($this->cves[$c['key']] as $cveId) {
                    $matched[$cveId] = $evidence;
                }
            }
        }

        return $matched;
    }

    /** Distinct constraints held, for logging and tests. */
    public function count(): int
    {
        return count($this->cves);
    }
}
