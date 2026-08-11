<?php

namespace App\Services;

use App\Models\Anomaly;
use App\Models\Circuit;
use App\Models\CircuitMetricHistory;
use App\Models\DeviceInterface;
use App\Models\InterfaceMetricHistory;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Flags metrics that deviate from an entity's OWN baseline — the slow drift and the
 * odd spike a static threshold misses. Deliberately conservative (robust z > 3.5,
 * sustained over several polls) and NON-paging: it opens an amber Anomaly row, never
 * a critical alarm. Uses the median + MAD (median absolute deviation), which a single
 * outlier can't drag around the way a mean + stddev can.
 */
class AnomalyDetector
{
    private const Z = 3.5;                 // robust-z threshold
    private const MAX_Z = 1000.0;          // clamp: a near-flat baseline can't yield a 1e15 z
    private const SUSTAIN = 3;             // consecutive samples that must breach it
    private const MIN_SAMPLES = 12;        // baseline needs this many points to be trusted
    private const LOOKBACK_DAYS = 7;
    private const THROUGHPUT_IDLE_UTIL = 0.005; // baseline below ~0.5% util = idle, no throughput baseline
    private const NOMINAL_POLL_SECONDS = 300;   // interface poll cadence, for the util gate
    private const DISCARD_FLOOR = 50;      // ignore trivial discard blips (a few dropped frames)
    private const LOSS_FLOOR = 1.0;        // ignore sub-1% packet-loss blips on a circuit

    /**
     * Robust z-score of $value against a baseline series (median + MAD). Null when the
     * baseline is too small to judge. A MAD floor stops a near-constant series from
     * turning a trivial wobble into a giant z.
     *
     * @param  list<float>  $series
     */
    public static function robustZ(array $series, float $value): ?float
    {
        if (count($series) < self::MIN_SAMPLES) {
            return null;
        }
        $median = self::median($series);
        $mad = self::median(array_map(fn ($x) => abs($x - $median), $series));
        // Floor MAD to 5% of |median| (or a tiny epsilon) so idle/flat ports don't
        // report a spike from statistical noise.
        $mad = max($mad, 0.05 * abs($median), 1e-6);

        // Clamp: when the baseline is near-zero (idle/flat), (value − median)/mad can
        // explode to ~1e15. A genuine breach still reads as MAX_Z; callers gate a
        // zero-baseline metric separately (see the throughput idle-util check).
        $z = 0.6745 * ($value - $median) / $mad;

        return max(-self::MAX_Z, min(self::MAX_Z, $z));
    }

    private static function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 ? (float) $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }

    /**
     * Given the full recent series and the last SUSTAIN samples, decide whether a
     * sustained anomaly is present. Every one of the last SUSTAIN samples must breach
     * the threshold in the SAME direction.
     *
     * @param  list<float>  $baseline  the comparison set (may be hour-filtered)
     * @param  list<float>  $recent    newest SUSTAIN samples, oldest→newest
     * @param  bool  $spikesOnly  discards/latency only care about going UP
     * @return array{direction:string, z:float, baseline:float, observed:float}|null
     */
    public static function sustained(array $baseline, array $recent, bool $spikesOnly = false): ?array
    {
        if (count($recent) < self::SUSTAIN) {
            return null;
        }
        $tail = array_slice($recent, -self::SUSTAIN);
        $dir = null;
        $lastZ = null;
        foreach ($tail as $v) {
            $z = self::robustZ($baseline, (float) $v);
            if ($z === null) {
                return null;
            }
            $d = $z >= self::Z ? 'spike' : ($z <= -self::Z ? 'drop' : null);
            if ($d === null || ($spikesOnly && $d === 'drop')) {
                return null; // not every recent sample breached — not sustained
            }
            if ($dir !== null && $d !== $dir) {
                return null; // flipped direction mid-window
            }
            $dir = $d;
            $lastZ = $z;
        }

        return [
            'direction' => $dir,
            'z' => round($lastZ, 2),
            'baseline' => round(self::median($baseline), 2),
            'observed' => round((float) end($tail), 2),
        ];
    }

    /** Scan every up interface's throughput + discards. */
    public function scanInterfaces(?callable $onProgress = null): void
    {
        DeviceInterface::where('status', 'up')->where('speed_bps', '>', 0)
            ->select('id')->orderBy('id')->chunkById(200, function (Collection $ifaces) use ($onProgress) {
                foreach ($ifaces as $if) {
                    $this->scanInterface($if->id);
                    if ($onProgress) {
                        $onProgress();
                    }
                }
            });
    }

    public function scanInterface(int $interfaceId): void
    {
        $since = now()->subDays(self::LOOKBACK_DAYS);
        $hist = InterfaceMetricHistory::where('device_interface_id', $interfaceId)
            ->where('recorded_at', '>=', $since)->orderBy('recorded_at')
            ->get(['recorded_at', 'in_octets_delta', 'out_octets_delta', 'in_discards_delta', 'out_discards_delta']);

        if ($hist->count() < self::MIN_SAMPLES + self::SUSTAIN) {
            return;
        }

        // Throughput (busiest direction) — hour-of-day aware, since it is diurnal.
        $thr = $hist->map(fn ($h) => ['t' => $h->recorded_at, 'v' => (float) max($h->in_octets_delta, $h->out_octets_delta)]);
        $hour = (int) now()->format('G');
        $baseThr = $thr->filter(fn ($r) => abs(((int) $r['t']->format('G')) - $hour) <= 1)->pluck('v')->all();
        $baseThr = $baseThr ?: $thr->pluck('v')->all();
        $recentThr = $thr->slice(-self::SUSTAIN)->pluck('v')->all();
        // Gate: an interface that is idle for its baseline (near-zero median throughput)
        // has no meaningful "normal" to deviate from — it going busy is normal use, not
        // an anomaly, and a zero baseline is what produced the fleet-wide 1e15 z-scores.
        // Require the baseline to carry real load (≥ ~0.5% of link speed) before judging.
        $speedBps = (float) (DeviceInterface::whereKey($interfaceId)->value('speed_bps') ?? 0);
        $idleFloor = $speedBps > 0 ? self::THROUGHPUT_IDLE_UTIL * $speedBps / 8 * self::NOMINAL_POLL_SECONDS : 0.0;
        $thrHit = self::median($baseThr) > $idleFloor
            ? self::sustained($baseThr, $recentThr)
            : null;
        $this->reconcile('interface', $interfaceId, 'throughput', $thrHit);

        // Discards — should be ~0 whatever the hour, so a flat baseline; spikes only.
        // Ignore trivial blips (a few dropped frames): only a real sustained discard rate
        // is worth an anomaly row.
        $disc = $hist->map(fn ($h) => (float) ($h->in_discards_delta + $h->out_discards_delta));
        $discHit = self::sustained($disc->all(), $disc->slice(-self::SUSTAIN)->all(), spikesOnly: true);
        if ($discHit !== null && $discHit['observed'] < self::DISCARD_FLOOR) {
            $discHit = null;
        }
        $this->reconcile('interface', $interfaceId, 'discards', $discHit);
    }

    /** Scan circuit latency (response time) against its own baseline. */
    public function scanCircuits(?callable $onProgress = null): void
    {
        Circuit::where('monitoring_enabled', true)->select('id')->orderBy('id')
            ->chunkById(200, function (Collection $circuits) use ($onProgress) {
                foreach ($circuits as $c) {
                    $this->scanCircuit($c->id);
                    if ($onProgress) {
                        $onProgress();
                    }
                }
            });
    }

    public function scanCircuit(int $circuitId): void
    {
        $since = now()->subDays(self::LOOKBACK_DAYS);
        $rows = CircuitMetricHistory::where('circuit_id', $circuitId)
            ->where('recorded_at', '>=', $since)->orderBy('recorded_at')
            ->get(['response_time_ms', 'loss_pct']);

        if ($rows->count() < self::MIN_SAMPLES + self::SUSTAIN) {
            return;
        }

        // Latency (response time) is a brownout signal — spikes only.
        $rtt = $rows->whereNotNull('response_time_ms')->pluck('response_time_ms')->map(fn ($v) => (float) $v)->values();
        if ($rtt->count() >= self::MIN_SAMPLES + self::SUSTAIN) {
            $this->reconcile('circuit', $circuitId, 'latency', self::sustained($rtt->all(), $rtt->slice(-self::SUSTAIN)->all(), spikesOnly: true));
        }

        // Packet loss (drops) — normally ~0, so any SUSTAINED loss above the circuit's own
        // baseline is the drop signal the NOC watches. Spikes only; ignore sub-1% blips.
        $loss = $rows->pluck('loss_pct')->map(fn ($v) => (float) ($v ?? 0));
        $lossHit = self::sustained($loss->all(), $loss->slice(-self::SUSTAIN)->all(), spikesOnly: true);
        if ($lossHit !== null && $lossHit['observed'] < self::LOSS_FLOOR) {
            $lossHit = null;
        }
        $this->reconcile('circuit', $circuitId, 'loss', $lossHit);
    }

    /**
     * Resolve open anomalies that a healthy sweep can no longer be maintaining:
     *   - impossible z (|z| > MAX_Z) — a stale row from before the z-clamp landed;
     *   - untouched for $staleSeconds — its entity has dropped out of the scan set
     *     (interface went down / speed_bps 0, circuit un-monitored or deleted), so
     *     reconcile() never revisits it and it would otherwise linger forever.
     * A live anomaly is touched every sweep, so its last_seen_at stays fresh.
     */
    public function resolveStale(int $staleSeconds): int
    {
        return Anomaly::open()
            ->where(function ($q) use ($staleSeconds) {
                // MAX_Z inlined (a trusted constant): a bound float binds as TEXT under
                // SQLite, and SQLite sorts every REAL below every TEXT, so ABS(z) > '1000'
                // is always false. A numeric literal compares correctly on SQLite + MySQL.
                $q->where('last_seen_at', '<', now()->subSeconds($staleSeconds))
                    ->orWhereRaw('ABS(z_score) > '.self::MAX_Z);
            })
            ->update(['resolved_at' => now()]);
    }

    /** Open, refresh, or resolve the single open anomaly for (entity, metric). */
    private function reconcile(string $type, int $id, string $metric, ?array $hit): void
    {
        $open = Anomaly::open()->where('entity_type', $type)->where('entity_id', $id)->where('metric', $metric)->first();

        if ($hit === null) {
            $open?->update(['resolved_at' => now()]);

            return;
        }

        if ($open) {
            $open->update(['observed' => $hit['observed'], 'z_score' => $hit['z'], 'direction' => $hit['direction'], 'last_seen_at' => now()]);

            return;
        }

        Anomaly::create([
            'entity_type' => $type, 'entity_id' => $id, 'metric' => $metric, 'direction' => $hit['direction'],
            'baseline' => $hit['baseline'], 'observed' => $hit['observed'], 'z_score' => $hit['z'],
            'detected_at' => now(), 'last_seen_at' => now(),
        ]);
    }
}
