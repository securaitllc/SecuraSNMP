<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPollLoop;
use App\Models\CveRecord;
use App\Services\Vuln\CveCatalogUpdater;
use App\Services\Vuln\Feed\NvdFeedSource;
use App\Services\Vuln\VulnerabilityScanner;
use Database\Seeders\CveCatalogSeeder;
use Illuminate\Console\Command;
use Throwable;

class RefreshVulnerabilities extends Command
{
    use RunsPollLoop;

    protected $signature = 'vuln:refresh {--once : Refresh + assess once and exit} {--no-feed : Skip the live NVD pull, assess against the existing catalog}';

    protected $description = 'Refresh the CVE catalog from the live NVD feed and correlate device firmware against it (no device I/O).';

    public function handle(): int
    {
        $scanner = new VulnerabilityScanner;

        if ($this->option('once')) {
            $this->refreshAndAssess($scanner, null);

            return self::SUCCESS;
        }

        $this->info('Vulnerability refresh started, refreshing daily.');

        // Daily: pull the feed, then correlate. Rides the supervised poll loop.
        $this->pollForever('vuln', 86400, fn () => $this->refreshAndAssess($scanner, fn () => $this->beat()));

        return self::SUCCESS;
    }

    /** One cycle: refresh the catalog from NVD (best-effort), then assess the fleet. */
    private function refreshAndAssess(VulnerabilityScanner $scanner, ?callable $beat): void
    {
        if (config('vuln.feed_enabled') && ! $this->option('no-feed')) {
            $this->pullFeed($beat);
        }

        // Offline / first-boot fallback: never leave the catalog empty.
        if (CveRecord::count() === 0) {
            $this->info('Catalog empty — seeding starter set.');
            (new CveCatalogSeeder)->run();
        }

        $r = $scanner->assessAll($beat);
        $this->info("Assessed {$r['devices_assessed']} devices — {$r['open']} new findings, {$r['resolved']} resolved, {$r['unknown_version']} without a version.");
    }

    /** Pull the live NVD feed into the catalog; failures leave the catalog intact. */
    private function pullFeed(?callable $beat): void
    {
        try {
            $source = new NvdFeedSource(
                fetcher: null,
                delayMs: (int) config('vuln.nvd_delay_ms', 6000),
                apiKey: config('vuln.nvd_api_key'),
            );
            $this->info('Pulling CVE feed from NVD…');
            $entries = $source->fetch();
            if ($beat) {
                $beat();
            }
            $result = (new CveCatalogUpdater)->apply($entries);
            $this->info("NVD feed: {$result['cves']} CVEs, {$result['affects']} affected-ranges upserted.");
        } catch (Throwable $e) {
            $this->error("NVD feed refresh failed ({$e->getMessage()}) — keeping existing catalog.");
        }
    }
}
