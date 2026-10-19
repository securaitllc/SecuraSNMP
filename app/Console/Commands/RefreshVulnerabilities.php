<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPollLoop;
use App\Models\VulnFeedTarget;
use App\Services\Vuln\CatalogCoverage;
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
        // Starter catalog FIRST, every cycle, not only into an empty catalog.
        //
        // Two reasons, both learned the hard way. It used to run only when the catalog
        // was completely empty, which made it useless for the gap it was meant to cover:
        // EdgeConnect had no ranges from the feed and none from the starter set, yet the
        // catalog was far from empty because JunOS and FortiOS filled it.
        //
        // And it has to run BEFORE the feed, because some of its entries are things the
        // feed can never supply — CVE-2025-37123 and CVE-2025-37125 are published by NVD
        // with no CPE applicability at all, so no CPE query finds them and hand-seeding
        // is their only coverage. Seeding after a successful pull would have skipped them
        // on exactly the hosts where the feed works. Running first also preserves the
        // documented precedence: CveCatalogUpdater replaces a CVE's ranges wholesale, so
        // a live NVD entry still supersedes the starter version of the same CVE.
        //
        // The seeder is idempotent, so a fully-covered catalog is left as it was.
        (new CveCatalogSeeder)->run();

        if (config('vuln.feed_enabled') && ! $this->option('no-feed')) {
            $this->pullFeed($beat);
        }

        // Diagnostic, after everything has had its say: a mapped product with devices and
        // still no ranges means nothing in the pipeline can speak about those devices.
        foreach (CatalogCoverage::gaps() as $gap) {
            $this->warn("No catalog ranges for {$gap['vendor']}/{$gap['product']} — those devices cannot be assessed.");
        }

        $r = $scanner->assessAll($beat);
        $this->info("Assessed {$r['devices_assessed']} devices — {$r['open']} new findings, {$r['resolved']} resolved, {$r['unknown_version']} without a version.");

        if ($r['failed'] > 0) {
            $this->error("{$r['failed']} device(s) could not be assessed — their findings still describe whatever firmware they last matched. See laravel.log for the exception.");
        }
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

            $updater = new CveCatalogUpdater;
            $cves = 0;
            $affects = 0;

            // One vendor at a time. Holding the whole feed before writing any of it
            // peaked at 126 MB, which the pollers' 128 MB limit killed every cycle —
            // the catalog write and the assessment after it were never reached. Per
            // vendor, the peak is one group and whatever completes is already durable.
            $source->fetchByVendor(function (array $entries, array $reports) use ($updater, &$cves, &$affects, $beat) {
                // Record and announce each target BEFORE writing the catalog. A
                // fleet-wide total hides a dead target completely — that is how a wrong
                // EdgeConnect CPE survived every nightly run for a year while the
                // summary line looked healthy. Recording first also means a failure in
                // the catalog write (a lock, a constraint) cannot take the drift signal
                // down with it: the thing built to survive failures has to be written
                // before the thing that fails.
                foreach ($reports as $report) {
                    VulnFeedTarget::record($report);
                    $this->announceTarget($report);
                }

                $result = $updater->apply($entries);
                $cves += $result['cves'];
                $affects += $result['affects'];

                if ($beat) {
                    $beat();
                }
            });

            $this->info("NVD feed: {$cves} CVEs, {$affects} affected-ranges upserted.");
        } catch (Throwable $e) {
            // Vendors already applied keep their rows, and their statuses are already
            // recorded, so a failure part-way through costs only the vendors after it.
            $this->error("NVD feed refresh failed ({$e->getMessage()}) — keeping existing catalog.");
        }
    }

    /** @param  array<string, mixed>  $report */
    private function announceTarget(array $report): void
    {
        $line = sprintf(
            '  %-56s %-9s %4d CVEs (%d known)',
            $report['cpe'],
            $report['status'],
            $report['cve_count'],
            $report['total_results'],
        );

        if ($report['status'] === 'ok') {
            $this->line($line);
        } else {
            $this->warn($line.' — '.$report['message']);
        }
    }
}
