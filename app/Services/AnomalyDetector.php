<?php

namespace App\Services;

use App\Models\Anomaly;
use App\Models\Circuit;
use App\Models\CircuitMetricHistory;
use App\Models\Device;
use App\Models\DeviceHealthHistory;
use App\Models\DeviceInterface;
use App\Models\InterfaceMetricHistory;
use App\Support\Maintenance;
use App\Support\OpsTime;
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
    // Clamp: a near-flat (idle/zero) baseline makes (value−median)/mad explode. Capping at
    // 100 keeps a "from-zero" breach obviously off-scale WITHOUT the meaningless "+1000σ"
    // that read as noise on the wall — a genuine breach on a real baseline is well under it.
    private const MAX_Z = 100.0;
    private const SUSTAIN = 3;             // consecutive samples that must breach it
    private const MIN_SAMPLES = 12;        // baseline needs this many points to be trusted
    private const LOOKBACK_DAYS = 7;
    private const THROUGHPUT_IDLE_UTIL = 0.005; // baseline below ~0.5% util = idle, no throughput baseline
    private const NOMINAL_POLL_SECONDS = 300;   // interface poll cadence, for the util gate
    private const DISCARD_FLOOR = 50;      // ignore trivial discard blips (a few dropped frames)
    private const ERROR_FLOOR = 10;        // ignore trivial error blips (CRC/frame noise); a real error rate is sustained
    private const LOSS_FLOOR = 1.0;        // ignore sub-1% packet-loss blips on a circuit
    private const CPU_FLOOR = 55.0;        // % — a real CPU anomaly runs hot, not a 5→18% wobble
    private const MEM_FLOOR = 75.0;        // % — memory pressure worth surfacing
    private const TEMP_FLOOR = 45.0;       // °C — ignore cool-running gear's baseline noise

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

    /**
     * Distance between two hours of the day, the short way round the clock.
     *
     * A plain subtraction makes 23:00 and 00:00 twenty-three hours apart, so between
     * midnight and 01:00 the hour-matched baseline threw away every sample from the
     * previous evening and kept only the minutes since midnight — which are the
     * spike being judged. The comparison then had nothing to deviate from and
     * throughput anomalies could not fire for an hour every night. A detector that
     * goes quiet at midnight is indistinguishable from a quiet network.
     */
    private static function hoursApart(int $a, int $b): int
    {
        $d = abs($a - $b) % 24;

        return min($d, 24 - $d);
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

    /**
     * Scan every up interface's throughput + discards. Note: NO speed_bps filter — a
     * port whose speed SNMP never reported (speed_bps 0) still discards packets, and
     * discard/error detection doesn't need speed. Throughput is gated on speed inside
     * scanInterface (idle-util check), so a speed-unknown port just skips throughput.
     */
    /**
     * Interfaces that never forward customer traffic, so their counters are noise.
     *
     * On Juniper, bme0 is the internal bridge to the Routing Engine and lo0 is the
     * loopback the control plane talks to itself on — plus their logical units
     * (bme0.0, lo0.16385, which the RE creates on its own). Discards and errors on
     * those say nothing about the network an operator can act on, and they were
     * filling the anomaly list with findings nobody can fix.
     */
    private const INTERNAL_INTERFACES = ['/^bme\\d/i', '/^lo\\d/i', '/^vme\\d/i', '/^pfe-/i', '/^pfh-/i'];

    /** Is this an internal control-plane interface rather than a forwarding port? */
    public static function isInternalInterface(?string $ifName): bool
    {
        $name = trim((string) $ifName);
        if ($name === '') {
            return false;
        }

        foreach (self::INTERNAL_INTERFACES as $re) {
            if (preg_match($re, $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A planned cutover is not an anomaly. Alarms already respect these windows
     * (AlertNotifier) and availability reporting subtracts them, but the baseline
     * detector did not know about them at all — so every planned change raised
     * throughput and discard findings that an operator then had to dismiss by hand,
     * which is how a watch-list stops being read.
     *
     * This used to be two arrays memoised on this instance, with a comment claiming
     * they were "resolved once per sweep". Nothing ever reset them, and the detector
     * is constructed ONCE by anomaly:monitor and then reused for the life of the
     * process — so the answer was really resolved once per container. A window opened
     * after the poller booted was never seen; one that closed suppressed findings
     * until somebody restarted the container. The device-to-site map had the same
     * fault, so a device added later resolved to no site at all.
     *
     * App\Support\Maintenance answers it now, off a memo that expires by wall clock,
     * and it is the same answer the dashboard, alarms and topology give.
     */
    private function underMaintenance(?int $deviceId, ?int $siteId): bool
    {
        // covers() already folds in site-scoped and fleet-wide windows for the device,
        // resolved against the live device table — so no local site map is needed.
        return Maintenance::covers($deviceId) || Maintenance::coversSite($siteId);
    }

    public function scanInterfaces(?callable $onProgress = null): void
    {
        DeviceInterface::where('status', 'up')
            ->select('id', 'if_name')->orderBy('id')->chunkById(200, function (Collection $ifaces) use ($onProgress) {
                foreach ($ifaces as $if) {
                    // Control-plane interfaces forward nothing — skip before any work.
                    if (self::isInternalInterface($if->if_name)) {
                        continue;
                    }
                    $this->scanInterface($if->id);
                    if ($onProgress) {
                        $onProgress();
                    }
                }
            });
    }

    public function scanInterface(int $interfaceId): void
    {
        // Guard here as well as in the sweep: this is called directly (single-port
        // rescan after a poll), and an internal port must not raise an anomaly by
        // that route either. Reconciling with no hit also RETIRES anything already
        // open against it, so the existing bme0/lo0 findings clear themselves on the
        // next pass instead of needing a migration.
        $iface = DeviceInterface::whereKey($interfaceId)->first(['if_name', 'device_id']);
        $ifName = $iface?->if_name;

        // A planned change is not an anomaly. Skip outright rather than reconcile:
        // that leaves anything raised BEFORE the window open, instead of quietly
        // retiring a real finding because maintenance happened to start.
        if ($this->underMaintenance($iface?->device_id, null)) {
            return;
        }

        if (self::isInternalInterface($ifName)) {
            foreach (['throughput', 'discards', 'errors'] as $metric) {
                $this->reconcile('interface', $interfaceId, $metric, null);
            }

            return;
        }

        $since = now()->subDays(self::LOOKBACK_DAYS);
        $hist = InterfaceMetricHistory::where('device_interface_id', $interfaceId)
            ->where('recorded_at', '>=', $since)->orderBy('recorded_at')
            ->get(['recorded_at', 'in_octets_delta', 'out_octets_delta', 'in_discards_delta', 'out_discards_delta', 'in_errors_delta', 'out_errors_delta']);

        if ($hist->count() < self::MIN_SAMPLES + self::SUSTAIN) {
            return;
        }

        // Throughput (busiest direction) — hour-of-day aware, since it is diurnal.
        $thr = $hist->map(fn ($h) => ['t' => $h->recorded_at, 'v' => (float) max($h->in_octets_delta, $h->out_octets_delta)]);
        // Hour of day on the OPERATOR's clock. Massey's traffic is diurnal against
        // Eastern business hours, not UTC — and because the offset changes at DST,
        // bucketing by UTC hour files the same 3pm twice a year under two different
        // hours and compares an afternoon against a morning.
        $hour = OpsTime::hour();
        $baseThr = $thr->filter(fn ($r) => self::hoursApart(OpsTime::hourOf($r['t']), $hour) <= 1)->pluck('v')->all();
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

        // Errors (in+out) — like discards, should be ~0, so a flat baseline; spikes only.
        // A sustained CRC/frame/alignment error rate above the port's own baseline is a
        // strong bad-cable / bad-optic / duplex-mismatch signal the NOC wants surfaced.
        $err = $hist->map(fn ($h) => (float) ($h->in_errors_delta + $h->out_errors_delta));
        $errHit = self::sustained($err->all(), $err->slice(-self::SUSTAIN)->all(), spikesOnly: true);
        if ($errHit !== null && $errHit['observed'] < self::ERROR_FLOOR) {
            $errHit = null;
        }
        $this->reconcile('interface', $interfaceId, 'errors', $errHit);
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
        // Same rule as interfaces: a circuit inside a maintenance window is expected
        // to misbehave, so its latency and loss are not findings.
        $circuitSite = Circuit::whereKey($circuitId)->value('site_id');
        if ($this->underMaintenance(null, $circuitSite)) {
            return;
        }

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

    /** Scan every device's health (CPU / memory / temperature) against its own baseline. */
    public function scanDevices(?callable $onProgress = null): void
    {
        Device::select('id')->orderBy('id')
            ->chunkById(200, function (Collection $devices) use ($onProgress) {
                foreach ($devices as $d) {
                    $this->scanDevice($d->id);
                    if ($onProgress) {
                        $onProgress();
                    }
                }
            });
    }

    public function scanDevice(int $deviceId): void
    {
        // Same rule as interfaces and circuits: a device being worked on is expected to
        // run hot and swap memory, so its CPU, memory and temperature are not findings.
        // covers() folds in site-scoped and fleet-wide windows.
        if ($this->underMaintenance($deviceId, null)) {
            return;
        }

        $since = now()->subDays(self::LOOKBACK_DAYS);
        $rows = DeviceHealthHistory::where('device_id', $deviceId)
            ->where('recorded_at', '>=', $since)->orderBy('recorded_at')
            ->get(['cpu_pct', 'mem_pct', 'temperature_c']);

        if ($rows->count() < self::MIN_SAMPLES + self::SUSTAIN) {
            return;
        }

        // CPU / memory / temperature are spikes-only (going UP is the concern) and each
        // carries a floor so a device that idles cool doesn't alarm on statistical wobble —
        // only a genuinely hot CPU, memory pressure, or a rising temperature is worth a row.
        foreach ([
            ['cpu', 'cpu_pct', self::CPU_FLOOR],
            ['memory', 'mem_pct', self::MEM_FLOOR],
            ['temperature', 'temperature_c', self::TEMP_FLOOR],
        ] as [$metric, $col, $floor]) {
            $vals = $rows->pluck($col)->filter(fn ($v) => $v !== null)->map(fn ($v) => (float) $v)->values();
            if ($vals->count() < self::MIN_SAMPLES + self::SUSTAIN) {
                $this->reconcile('device', $deviceId, $metric, null);

                continue;
            }
            $hit = self::sustained($vals->all(), $vals->slice(-self::SUSTAIN)->all(), spikesOnly: true);
            if ($hit !== null && $hit['observed'] < $floor) {
                $hit = null;
            }
            $this->reconcile('device', $deviceId, $metric, $hit);
        }
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
        // An impossible z is a corrupt row, not an observation. It goes regardless of
        // anything below — MAX_Z inlined (a trusted constant) because a bound float
        // binds as TEXT under SQLite, and SQLite sorts every REAL below every TEXT, so
        // ABS(z) > '1000' would always be false. A numeric literal compares correctly
        // on SQLite + MySQL.
        $retired = Anomaly::open()->whereRaw('ABS(z_score) > '.self::MAX_Z)
            ->update(['resolved_at' => now()]);

        $excluded = $this->maintenanceExclusions();
        if ($excluded === null) {
            return $retired;   // fleet-wide window: nothing is being observed at all
        }

        // Everything else here infers "it stopped being reported, so it is over". That
        // inference is only valid for an entity we are still looking at. The scans skip
        // anything inside a maintenance window, so its last_seen_at stops advancing and
        // a finding that was open BEFORE the window would be retired ~30 minutes into a
        // 30-day one — exactly what skipping rather than reconciling was meant to avoid.
        // Absence of a signal is never a signal.
        $query = Anomaly::open()->where('last_seen_at', '<', now()->subSeconds($staleSeconds));
        foreach ($excluded as $type => $ids) {
            if ($ids === []) {
                continue;
            }
            $query->where(fn ($w) => $w->where('entity_type', '!=', $type)->orWhereNotIn('entity_id', $ids));
        }

        return $retired + $query->update(['resolved_at' => now()]);
    }

    /**
     * Entity ids the scans are currently NOT looking at, per entity type.
     *
     * Null means a fleet-wide window — every entity, so nothing may be aged out.
     *
     * @return array<string, array<int, int>>|null
     */
    private function maintenanceExclusions(): ?array
    {
        $sites = Maintenance::siteIds();
        if ($sites['global']) {
            return null;
        }

        $deviceIds = array_keys(Maintenance::deviceIds());
        $siteIds = array_keys($sites['sites']);

        return [
            'device' => $deviceIds,
            'interface' => $deviceIds === []
                ? []
                : DeviceInterface::whereIn('device_id', $deviceIds)->pluck('id')->all(),
            // Circuits hang off a site, not a device: a window on one switch says
            // nothing about the site's uplinks, so only a site window covers them.
            'circuit' => $siteIds === []
                ? []
                : Circuit::whereIn('site_id', $siteIds)->pluck('id')->all(),
        ];
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
