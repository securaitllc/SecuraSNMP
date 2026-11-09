<?php

namespace App\Services\Vuln;

use App\Models\CveAffect;
use App\Models\Device;
use Illuminate\Support\Collection;

/**
 * Which parts of the fleet the CVE catalog can actually speak about.
 *
 * This class exists because of a bug that hid for a year. The NVD feed queried
 * `cpe:2.3:o:arubanetworks:edgeconnect_enterprise`, a product name that NVD has never
 * used (EdgeConnect is an application CPE, and HPE has since renamed it again to
 * `hpe:edgeconnect_operating_system`). The query returned zero CVEs every night, the
 * catalog held no EdgeConnect ranges, and so 133 appliances matched nothing — which the
 * dashboard rendered as 133 clean devices.
 *
 * They were clean, as it happened: the fleet sits one patch past every published ECOS
 * range. But nothing had checked. The platform was reporting "no findings" when the
 * truth was "no data", and those are the same pixel and completely different facts.
 * That is the false-healthy pattern this codebase has a standing rule against — absence
 * of a signal is never a signal — and the vulnerability page was committing it fleet-wide.
 *
 * So coverage is now a first-class, queryable thing. A device is only called clean when
 * the catalog holds at least one affected-range for its vendor AND product and none of
 * them matched. With no ranges to compare against, the device is NOT ASSESSED, and every
 * surface says so in those words.
 */
class CatalogCoverage
{
    /**
     * Device vendor → the catalog product its firmware belongs to.
     *
     * Needed because vendor alone is ambiguous now that the catalog carries Orchestrator
     * CVEs: Orchestrator ships under the same `silverpeak` vendor tag as the appliances
     * but is a separate product on its own version line (9.5.9 vs the appliances'
     * 9.5.9.0). Matching on vendor alone would have tested every EdgeConnect appliance
     * against Orchestrator ranges and invented findings out of a coincidence of digits.
     */
    private const PRODUCT_FOR_VENDOR = [
        'juniper' => 'junos',
        'fortigate' => 'fortios',
        'silverpeak' => 'edgeconnect',
    ];

    /** The catalog product for a device, or null when its vendor is unmapped. */
    public static function productFor(?string $vendor): ?string
    {
        return self::PRODUCT_FOR_VENDOR[$vendor] ?? null;
    }

    /**
     * Group key for a vendor/product pair. Used to bucket both affected-ranges and
     * devices so the two sides can only ever be compared like-for-like.
     */
    public static function key(?string $vendor, ?string $product): string
    {
        return ($vendor ?? '?').'|'.($product ?? '*');
    }

    /**
     * The affected-ranges that apply to one device, keyed off vendor + product.
     *
     * A row with a NULL product is vendor-wide and still applies: the starter catalog
     * predates the product column, and dropping those rows here would silently narrow
     * coverage on an offline host.
     *
     * @param  Collection<int, CveAffect>  $all  every affected-range in the catalog
     * @return Collection<int, CveAffect>
     */
    public static function affectsForDevice(Device $device, Collection $all): Collection
    {
        $product = self::productFor($device->vendor);

        return $all->filter(fn (CveAffect $a) => $a->vendor === $device->vendor
            && ($a->product === null || $a->product === $product))->values();
    }

    /**
     * Vendor|product pairs the catalog holds at least one range for.
     *
     * @return array<string, int> key => range count
     */
    public static function covered(): array
    {
        return CveAffect::query()
            ->selectRaw('vendor, product, COUNT(*) as n')
            ->groupBy('vendor', 'product')
            ->get()
            ->reduce(function (array $carry, $row) {
                // A vendor-wide (null product) row covers every product of that vendor,
                // so credit it to each mapped product rather than to a "*" bucket the
                // device side can never ask for.
                $products = $row->product !== null
                    ? [$row->product]
                    : array_values(array_filter(
                        self::PRODUCT_FOR_VENDOR,
                        fn ($p, $v) => $v === $row->vendor,
                        ARRAY_FILTER_USE_BOTH
                    ));

                foreach ($products as $product) {
                    $key = self::key($row->vendor, $product);
                    $carry[$key] = ($carry[$key] ?? 0) + (int) $row->n;
                }

                return $carry;
            }, []);
    }

    /**
     * Mapped vendor/product pairs that have devices in inventory but no catalog ranges.
     *
     * This is the gap the starter catalog exists to backstop. The refresh command used
     * to fall back to the starter set only when the catalog was COMPLETELY empty, so a
     * single product the live feed could not see stayed uncovered indefinitely while the
     * catalog looked healthy on the strength of the other vendors. EdgeConnect sat in
     * exactly that hole.
     *
     * @return array<int, array{vendor: string, product: string}>
     */
    public static function gaps(): array
    {
        $covered = self::covered();

        return Device::query()->distinct()->pluck('vendor')
            ->map(fn ($vendor) => ['vendor' => $vendor, 'product' => self::productFor($vendor)])
            ->filter(fn ($pair) => $pair['product'] !== null
                && ! isset($covered[self::key($pair['vendor'], $pair['product'])]))
            ->values()
            ->all();
    }

    /**
     * Split the fleet into the three honest states.
     *
     * `assessed` means the catalog had something to say and said it. `not_assessed`
     * means the device reports a firmware version but the catalog holds no range for
     * its vendor+product — the EdgeConnect case. `no_version` means the device never
     * told us what it runs. Only the first bucket may ever be described as clean.
     *
     * @return array{assessed: list<int>, not_assessed: list<int>, no_version: list<int>}
     */
    public static function classifyFleet(): array
    {
        $covered = self::covered();
        $out = ['assessed' => [], 'not_assessed' => [], 'no_version' => []];

        Device::query()->get(['id', 'vendor', 'os_version'])->each(function ($d) use ($covered, &$out) {
            if (! $d->os_version) {
                $out['no_version'][] = $d->id;

                return;
            }

            $key = self::key($d->vendor, self::productFor($d->vendor));
            $out[isset($covered[$key]) ? 'assessed' : 'not_assessed'][] = $d->id;
        });

        return $out;
    }
}
