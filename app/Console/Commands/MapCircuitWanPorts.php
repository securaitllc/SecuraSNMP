<?php

namespace App\Console\Commands;

use App\Models\Circuit;
use Illuminate\Console\Command;

/**
 * Backfill the WAN port a circuit lands on, by circuit type.
 *
 * The port drives bandwidth attribution (CircuitBandwidth) as well as SD-WAN
 * sourced pings, but for years it was only reachable in the UI when a circuit was
 * monitored via SD-WAN — so most circuits never got one.
 *
 * At Massey the convention is consistent enough to backfill: of the 214 circuits
 * that already carry a port, 106 cable sit on wan0 and 103 fiber on wan1.
 *
 * It NEVER overwrites a port that is already set. Those same 214 include five
 * deliberate exceptions (2 cable on wan1, 1 fiber on wan0, 1 cable on lan1, 1 lte
 * on wan1) which are real cabling, not mistakes — a blanket update would quietly
 * break exactly the circuits somebody took the trouble to get right. Use --force
 * only if you genuinely mean to re-stamp everything.
 */
class MapCircuitWanPorts extends Command
{
    protected $signature = 'circuits:map-wan-ports
        {--apply : Write the changes. Without this the command only reports what it would do}
        {--fiber=wan1 : Port for fiber circuits}
        {--broadband=wan0 : Port for cable/broadband circuits}
        {--force : Also overwrite circuits that ALREADY have a port set (destroys deliberate exceptions)}';

    protected $description = 'Backfill each circuit\'s EdgeConnect WAN port from its type (fiber → wan1, cable → wan0).';

    public function handle(): int
    {
        $map = [
            'fiber' => (string) $this->option('fiber'),
            'cable' => (string) $this->option('broadband'),
            'broadband' => (string) $this->option('broadband'),
        ];
        $apply = (bool) $this->option('apply');
        $force = (bool) $this->option('force');

        $circuits = Circuit::query()
            ->when(! $force, fn ($q) => $q->where(fn ($w) => $w->whereNull('wan_interface')->orWhere('wan_interface', '')))
            ->orderBy('circuit_id')
            ->get();

        if ($circuits->isEmpty()) {
            $this->info('Nothing to do — every circuit already has a WAN port.');

            return self::SUCCESS;
        }

        $rows = [];
        $changed = 0;
        $skipped = 0;

        foreach ($circuits as $c) {
            $type = strtolower(trim((string) $c->circuit_type));
            $port = $map[$type] ?? null;

            if ($port === null) {
                // An LTE or unknown type has no reliable convention — leave it for a human.
                $rows[] = [$c->circuit_id, $type ?: '—', $c->wan_interface ?: '—', '—', 'skipped: no rule for this type'];
                $skipped++;
                continue;
            }
            if ($force && $c->wan_interface === $port) {
                continue; // already correct, nothing to say
            }

            $rows[] = [$c->circuit_id, $type, $c->wan_interface ?: '(empty)', $port, $apply ? 'set' : 'would set'];
            $changed++;

            if ($apply) {
                $c->wan_interface = $port;
                $c->save();
            }
        }

        $this->table(['Circuit', 'Type', 'Current', 'New', 'Action'], $rows);

        $verb = $apply ? 'Updated' : 'Would update';
        $this->info("{$verb} {$changed} circuit(s); {$skipped} skipped (no rule for their type).");

        if ($force) {
            $this->warn('--force was used: circuits that already had a port were re-stamped, including any deliberate exceptions.');
        }
        if (! $apply) {
            $this->comment('Dry run — nothing was written. Re-run with --apply to commit.');
        }

        return self::SUCCESS;
    }
}
