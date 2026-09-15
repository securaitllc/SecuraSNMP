<?php

namespace App\Services\Vuln;

use App\Models\CveAffect;
use App\Models\CveRecord;
use Illuminate\Support\Facades\DB;

/**
 * Writes normalized feed entries (see NvdFeedSource) into the catalog.
 *
 * Each CVE's record is upserted and its affected-ranges are REPLACED wholesale, so a
 * corrected or narrowed range from the feed never leaves a stale row behind. A live
 * NVD entry supersedes the starter-catalog version of the same CVE.
 *
 * @phpstan-type Entry array{cve_id:string, cvss:?float, summary:?string, published_at:?string, source:string, affects:array<int, array<string,mixed>>}
 */
class CveCatalogUpdater
{
    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @return array{cves:int, affects:int}
     */
    public function apply(array $entries): array
    {
        $cves = 0;
        $affects = 0;

        foreach ($entries as $entry) {
            if (empty($entry['cve_id']) || empty($entry['affects'])) {
                continue;
            }

            DB::transaction(function () use ($entry, &$cves, &$affects) {
                CveRecord::updateOrCreate(
                    ['cve_id' => $entry['cve_id']],
                    [
                        'cvss_score' => $entry['cvss'] ?? null,
                        'severity' => CveRecord::severityForScore($entry['cvss'] ?? null),
                        'summary' => $entry['summary'] ?? null,
                        'reference_url' => 'https://nvd.nist.gov/vuln/detail/'.$entry['cve_id'],
                        'published_at' => $entry['published_at'] ?? null,
                        'source' => $entry['source'] ?? 'nvd',
                    ]
                );
                $cves++;

                // Replace this CVE's ranges outright.
                CveAffect::where('cve_id', $entry['cve_id'])->delete();
                foreach ($entry['affects'] as $a) {
                    CveAffect::create([
                        'cve_id' => $entry['cve_id'],
                        'vendor' => $a['vendor'],
                        'product' => $a['product'] ?? null,
                        'version_introduced' => $a['from'] ?? null,
                        'introduced_inclusive' => $a['from_incl'] ?? true,
                        'version_fixed' => $a['fixed'] ?? null,
                        'fixed_inclusive' => $a['fixed_incl'] ?? false,
                        'exact_match' => $a['exact'] ?? false,
                        'constraint_label' => $a['label'] ?? null,
                    ]);
                    $affects++;
                }
            });
        }

        return ['cves' => $cves, 'affects' => $affects];
    }
}
