<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPollLoop;
use App\Models\CveRecord;
use App\Services\Vuln\VulnerabilityScanner;
use Database\Seeders\CveCatalogSeeder;
use Illuminate\Console\Command;

class RefreshVulnerabilities extends Command
{
    use RunsPollLoop;

    protected $signature = 'vuln:refresh {--once : Assess once and exit instead of looping}';

    protected $description = 'Passively correlate each device firmware version against the CVE catalog (no device I/O).';

    public function handle(): int
    {
        $scanner = new VulnerabilityScanner;

        // Bootstrap the starter catalog on first run so prod has data to match
        // against before the live feed is wired in.
        if (CveRecord::count() === 0) {
            $this->info('Seeding starter CVE catalog…');
            (new CveCatalogSeeder)->run();
        }

        if ($this->option('once')) {
            $r = $scanner->assessAll();
            $this->info("Assessed {$r['devices_assessed']} devices — {$r['open']} new findings, {$r['resolved']} resolved, {$r['unknown_version']} without a version.");

            return self::SUCCESS;
        }

        $this->info('Vulnerability refresh started, assessing daily.');

        // Once a day: the catalog and firmware versions change slowly, and this
        // touches no devices. Rides the supervised poll loop (heartbeat + restart).
        $this->pollForever('vuln', 86400, fn () => $scanner->assessAll(fn () => $this->beat()));

        return self::SUCCESS;
    }
}
