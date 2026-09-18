<?php

namespace App\Services;

use App\Models\Circuit;
use App\Models\CircuitMetricHistory;

/**
 * Runs of packet loss in a circuit's history: when each began, when it ended, and how
 * bad it was.
 *
 * The history table has every poll for thirty days and nothing read it back as a
 * story. An operator on the phone with the carrier had to scroll a graph and guess at
 * "since when" and "has it done this before". This walks the polls once and hands back
 * the episodes.
 *
 * An episode is a stretch where at least a quarter of any rolling window of polls lost
 * packets — the same rule CircuitMonitor uses to call a circuit degraded, so the list
 * here and the flag on the row agree. A few clean polls inside a lossy stretch do not
 * end it; that is what "intermittent" means, and splitting #037's day into ninety
 * separate episodes would describe nothing.
 */
class CircuitLossEpisodes
{
    private const WINDOW = 20;

    private const THRESHOLD_PCT = 25;

    /**
     * Fewest polls a rolling window may hold before its lossy share means anything.
     * The monitor's frequency has no floor — it reads whatever the window holds — and
     * this stays close to that so the episode list and the flag on the row agree,
     * while three polls with one bad one do not become a 33% "episode".
     */
    private const MIN_SAMPLES = 8;

    /**
     * @return array<int, array{started_at: string, ended_at: ?string, ongoing: bool, polls: int, lossy_polls: int, lossy_pct: int, peak_pct: int, minutes: int}>
     */
    public function forCircuit(Circuit $circuit, int $days): array
    {
        $rows = CircuitMetricHistory::where('circuit_id', $circuit->id)
            ->where('recorded_at', '>=', now()->subDays($days))
            ->orderBy('recorded_at')
            ->get(['recorded_at', 'loss_pct']);

        if ($rows->count() < self::MIN_SAMPLES) {
            return [];
        }

        $episodes = [];
        $current = null;
        $recent = [];   // rolling window of {loss, at}

        foreach ($rows as $row) {
            $loss = (int) ($row->loss_pct ?? 0);
            $recent[] = ['loss' => $loss, 'at' => $row->recorded_at];
            if (count($recent) > self::WINDOW) {
                array_shift($recent);
            }
            $lossyShare = count(array_filter($recent, fn ($r) => $r['loss'] > 0)) / count($recent) * 100;
            $degraded = count($recent) >= self::MIN_SAMPLES && $lossyShare >= self::THRESHOLD_PCT;

            if ($degraded && $current === null) {
                // The episode began at the first lossy poll in the window that tipped
                // it, not at the poll that made the share cross the line — and its
                // counters start there too, so the 90% at the top of the window is the
                // peak and not a poll that happened before we were counting.
                $firstIdx = 0;
                foreach ($recent as $i => $r) {
                    if ($r['loss'] > 0) {
                        $firstIdx = $i;
                        break;
                    }
                }
                $since = array_slice($recent, $firstIdx);
                $current = [
                    'started_at' => $recent[$firstIdx]['at']->toIso8601String(),
                    'ended_at' => null,
                    'ongoing' => true,
                    'polls' => count($since),
                    'lossy_polls' => count(array_filter($since, fn ($r) => $r['loss'] > 0)),
                    'peak_pct' => max(array_map(fn ($r) => $r['loss'], $since)),
                ];
            } elseif ($current !== null) {
                $current['polls']++;
                if ($loss > 0) {
                    $current['lossy_polls']++;
                    $current['peak_pct'] = max($current['peak_pct'], $loss);
                }
            }

            if ($current !== null) {
                if (! $degraded) {
                    $current['ended_at'] = $row->recorded_at->toIso8601String();
                    $current['ongoing'] = false;
                    $episodes[] = $this->finish($current);
                    $current = null;
                }
            }
        }

        if ($current !== null) {
            $episodes[] = $this->finish($current);
        }

        return array_reverse($episodes);
    }

    /** @param  array<string, mixed>  $e */
    private function finish(array $e): array
    {
        $start = new \DateTimeImmutable($e['started_at']);
        $end = $e['ended_at'] ? new \DateTimeImmutable($e['ended_at']) : now()->toDateTimeImmutable();

        return [
            ...$e,
            'lossy_pct' => $e['polls'] ? (int) round($e['lossy_polls'] / $e['polls'] * 100) : 0,
            'minutes' => (int) round(($end->getTimestamp() - $start->getTimestamp()) / 60),
        ];
    }
}
