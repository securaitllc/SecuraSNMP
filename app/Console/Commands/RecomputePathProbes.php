<?php

namespace App\Console\Commands;

use App\Models\CircuitPathProbe;
use App\Services\CircuitPathProber;
use Illuminate\Console\Command;

/**
 * Re-derives worst_hop from the hop data already stored on each path probe.
 *
 * The raw per-hop readings are the measurement and are never touched. What gets
 * rewritten is the CONCLUSION drawn from them — worst_hop, its host and its loss —
 * because that conclusion is what the carrier correlation reads and what ends up
 * quoted in a ticket.
 *
 * Existing rows were written by an earlier worstHop(), so a probe whose stored
 * verdict disagrees with the current rule is carrying a verdict nobody would draw
 * today. Recomputing is honest in a way deleting is not: the probes stay, the
 * history stays, only the interpretation is brought up to date.
 *
 *   php artisan circuits:recompute-path-probes --dry-run
 *   php artisan circuits:recompute-path-probes
 */
class RecomputePathProbes extends Command
{
    protected $signature = 'circuits:recompute-path-probes
        {--dry-run : Show what would change without writing}
        {--days=90 : Only probes from the last N days}';

    protected $description = 'Re-derive worst_hop on stored path probes from their own hop data.';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $since = now()->subDays(max(1, (int) $this->option('days')));

        $changed = 0;
        $cleared = 0;
        $scanned = 0;
        $examples = [];

        CircuitPathProbe::query()
            ->where('ran_at', '>=', $since)
            ->orderBy('id')
            ->chunkById(500, function ($probes) use (&$changed, &$cleared, &$scanned, &$examples, $dry) {
                foreach ($probes as $probe) {
                    $scanned++;
                    $hops = (array) $probe->hops;

                    if ($hops === []) {
                        continue;
                    }

                    $worst = CircuitPathProber::worstHop($hops);

                    $host = $worst['host'] ?? $worst['ip'] ?? null;
                    $hop = $worst['hop'] ?? null;
                    $loss = isset($worst['loss_pct']) ? (int) $worst['loss_pct'] : null;

                    $same = (string) $probe->worst_hop_host === (string) $host
                        && (string) $probe->worst_hop === (string) $hop;

                    if ($same) {
                        continue;
                    }

                    $changed++;
                    if ($host === null) {
                        $cleared++;
                    }

                    if (count($examples) < 12) {
                        $examples[] = sprintf(
                            '  circuit %-5s %s  %s  →  %s',
                            $probe->circuit_id,
                            substr((string) $probe->ran_at, 0, 19),
                            str_pad((string) ($probe->worst_hop_host ?? '—'), 42),
                            $host ?? 'none (no loss persisted to the target)'
                        );
                    }

                    if (! $dry) {
                        $probe->forceFill([
                            'worst_hop' => $hop,
                            'worst_hop_host' => $host,
                            'worst_hop_loss_pct' => $loss,
                        ])->saveQuietly();
                    }
                }
            });

        foreach ($examples as $line) {
            $this->line($line);
        }

        $this->line('');
        $this->info(sprintf(
            '%s %d of %d probes (%d had their hop cleared — no loss actually persisted to the target).',
            $dry ? 'Would rewrite' : 'Rewrote',
            $changed,
            $scanned,
            $cleared,
        ));

        if ($dry) {
            $this->comment('Dry run — nothing written. Re-run without --dry-run to apply.');
        }

        return self::SUCCESS;
    }
}
