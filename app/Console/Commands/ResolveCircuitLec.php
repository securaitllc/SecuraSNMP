<?php

namespace App\Console\Commands;

use App\Models\Circuit;
use App\Services\LecResolver;
use Illuminate\Console\Command;

/**
 * Fill in each circuit's real last-mile carrier from its public IP.
 *
 * Written for the modem-repatriation project: the circuits billed through an
 * aggregator do not record whose coax they actually ride, and that is the carrier
 * you have to phone to get a modem replaced. See LecResolver for the method.
 *
 * Dry-run by default, and it only ever FILLS AN EMPTY lec_name — a value somebody
 * typed after talking to a carrier is worth more than anything inferred from an IP,
 * so it is never overwritten without --force.
 */
class ResolveCircuitLec extends Command
{
    protected $signature = 'circuits:resolve-lec
        {--apply : Write the results. Without this the command only reports what it would do}
        {--type=cable : Circuit type to resolve (cable, fiber, or "all")}
        {--billed= : Only circuits whose recorded ISP is this (e.g. --billed=Lumen for the aggregated ones)}
        {--min-confidence=high : Only write at this confidence or better (high, medium)}
        {--force : Also overwrite a lec_name that is already filled in}';

    protected $description = 'Determine each circuit\'s real last-mile carrier (LEC) from its public IP.';

    public function handle(LecResolver $resolver): int
    {
        $apply = (bool) $this->option('apply');
        $force = (bool) $this->option('force');
        $type = strtolower((string) $this->option('type'));
        $billed = trim((string) $this->option('billed'));
        $minConf = strtolower((string) $this->option('min-confidence'));

        $writable = $minConf === 'medium' ? ['high', 'medium'] : ['high'];

        $circuits = Circuit::query()
            ->when($type !== 'all', fn ($q) => $q->whereIn('circuit_type', $type === 'cable' ? ['cable', 'broadband'] : [$type]))
            ->when($billed !== '', fn ($q) => $q->whereHas('ispProvider', fn ($p) => $p->where('name', $billed)))
            ->orderBy('circuit_id')
            ->get();

        if ($circuits->isEmpty()) {
            $this->info('No circuits matched.');

            return self::SUCCESS;
        }

        $this->info("Resolving {$circuits->count()} circuit(s) — three signals per IP, please wait.");

        $rows = [];
        $written = 0;
        $needsReview = 0;

        foreach ($circuits as $c) {
            $r = $resolver->resolve($c);
            $conf = $r['confidence'];
            $lec = $r['lec'];

            $action = 'skip';
            if ($lec !== null && in_array($conf, $writable, true)) {
                $existing = trim((string) $c->lec_name);
                if ($existing !== '' && ! $force) {
                    $action = 'kept existing';
                } elseif ($existing === $lec) {
                    $action = 'already correct';
                } else {
                    $action = $apply ? 'set' : 'would set';
                    $written++;
                    if ($apply) {
                        $c->lec_name = $lec;
                        $c->save();
                    }
                }
            } elseif ($conf === 'verify') {
                $action = 'REVIEW: signals disagree';
                $needsReview++;
            } elseif ($conf === 'masked') {
                $action = 'aggregator IP — last mile hidden';
            }

            $rows[] = [
                $c->circuit_id,
                $r['ip'] ?? '—',
                $lec ?? '—',
                $conf,
                implode(',', $r['signals']) ?: '—',
                $action,
            ];
        }

        $this->table(['Circuit', 'IP', 'LEC', 'Confidence', 'Signals', 'Action'], $rows);

        $verb = $apply ? 'Wrote' : 'Would write';
        $this->info("{$verb} {$written} lec_name value(s).");

        if ($needsReview > 0) {
            $this->warn("{$needsReview} circuit(s) had signals naming DIFFERENT carriers — check those by hand before calling anyone.");
        }
        if (! $apply) {
            $this->comment('Dry run — nothing was written. Re-run with --apply to commit.');
        }

        return self::SUCCESS;
    }
}
