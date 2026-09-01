<?php

namespace App\Console\Commands;

use App\Models\Circuit;
use Illuminate\Console\Command;

/**
 * Set the SLA target across the fleet in one go.
 *
 * The fleet has moved as a block before (a one-shot migration put every circuit on
 * 99.5), so this exists as a command rather than another throwaway migration: the
 * next change is a re-run with a different number instead of a new deploy.
 *
 * Dry-run by default — it reports the spread it is about to collapse, because
 * "everything becomes 99.9" is only safe to confirm once you can see what is there
 * now and whether any circuit was deliberately set to something else.
 */
class SetCircuitSla extends Command
{
    protected $signature = 'circuits:set-sla
        {target : The SLA target percentage, e.g. 99.9}
        {--apply : Write the change. Without this the command only reports what it would do}
        {--type=all : Limit to a circuit type (cable, fiber, lte) — default every circuit}';

    protected $description = 'Set the SLA target percentage on every circuit (or one type).';

    public function handle(): int
    {
        $target = (float) $this->argument('target');

        // decimal(5,2): anything outside this silently truncates or throws on MySQL
        // while SQLite shrugs — the divergence that bites this repo repeatedly.
        if ($target <= 0 || $target > 100 || round($target, 2) !== $target) {
            $this->error('Target must be between 0 and 100 with at most 2 decimal places.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $type = strtolower(trim((string) $this->option('type')));

        $query = Circuit::query()->when($type !== 'all', fn ($q) => $q->where('circuit_type', $type));
        $circuits = (clone $query)->get(['id', 'circuit_type', 'sla_target_pct']);

        if ($circuits->isEmpty()) {
            $this->info('No circuits matched.');

            return self::SUCCESS;
        }

        // Show what is there today before collapsing it — a circuit sitting on its own
        // value may be deliberate, and this is the only moment anyone would notice.
        $spread = $circuits
            ->groupBy(fn ($c) => $c->sla_target_pct === null ? '(not set)' : (string) (float) $c->sla_target_pct)
            ->map->count()
            ->sortDesc();

        $this->table(['Current target', 'Circuits'], $spread->map(fn ($n, $v) => [$v, $n])->values()->all());

        $changing = $circuits->filter(fn ($c) => $c->sla_target_pct === null || (float) $c->sla_target_pct !== $target)->count();
        $already = $circuits->count() - $changing;

        if ($changing === 0) {
            $this->info("Every matched circuit is already on {$target}% — nothing to do.");

            return self::SUCCESS;
        }

        if ($apply) {
            // One statement, so the fleet can never be left half-migrated.
            $written = (clone $query)->update(['sla_target_pct' => $target]);
            $this->info("Set {$written} circuit(s) to {$target}%. ({$already} were already there.)");
        } else {
            $this->info("Would set {$changing} circuit(s) to {$target}%. ({$already} already there.)");
            $this->comment('Dry run — nothing was written. Re-run with --apply to commit.');
        }

        return self::SUCCESS;
    }
}
