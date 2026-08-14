<?php

namespace App\Services;

use App\Models\Circuit;
use App\Models\CircuitAlert;
use App\Models\CircuitMetricHistory;
use App\Models\Device;
use App\Models\DeviceNextHop;
use Illuminate\Support\Facades\Log;
use Throwable;

class CircuitMonitor
{
    /**
     * @param  callable(string): (array{loss:int, rtt:?float}|null)  $pinger  Sends
     *         several ICMP probes and returns loss % (0–100) + best RTT, or null on
     *         total failure (treated as 100% loss).
     * @param  (callable(Device, string, string): ?float)|null  $sdwanPinger  Sources a
     *         ping FROM the Silver Peak out a WAN (device, wanIf, target) → RTT|null,
     *         for DHCP circuits behind ISP NAT that can't be ICMP'd directly.
     * @param  (callable(list<string>): array<string, array{loss:int, rtt:?float}|null>)|null  $batchPinger
     *         Pings MANY monitored IPs concurrently and returns ip => result. When
     *         provided, a full sweep measures every direct-ICMP circuit in parallel
     *         (seconds) instead of one-at-a-time (minutes). Falls back to $pinger.
     */
    public function __construct(private $pinger, private $sdwanPinger = null, private $batchPinger = null)
    {
    }

    /**
     * @param  (callable(): void)|null  $onProgress  Called after each circuit so a
     *         long sweep can emit a liveness heartbeat and not be mistaken for a
     *         hung loop (see RunsPollLoop::beat).
     */
    public function checkAll(?callable $onProgress = null): void
    {
        // Skip circuits taken out of monitoring (planned disconnect / maintenance)
        // so they don't ping or raise a false "circuit down".
        $circuits = Circuit::where('monitoring_enabled', true)->get();

        // Measure every direct-ICMP circuit's loss/rtt in ONE parallel batch, so a
        // 240-circuit fleet is swept in seconds rather than minutes and no circuit
        // late in the ordering waits out the whole sweep. SD-WAN circuits (a
        // handful, SSH-sourced) are still measured one at a time inside probe().
        $measured = [];
        if ($this->batchPinger) {
            $ips = $circuits
                ->filter(fn (Circuit $c) => $this->isBatchable($c))
                ->pluck('monitored_ip')
                ->filter()
                ->unique()
                ->values()
                ->all();

            if ($ips) {
                $measured = ($this->batchPinger)($ips);
            }
        }

        $circuits->each(function (Circuit $circuit) use ($measured, $onProgress) {
            try {
                $this->applyResult($circuit, $this->measure($circuit, $measured));
            } catch (Throwable $e) {
                Log::error("Circuit monitor failed for circuit {$circuit->id}: {$e->getMessage()}");
            }

            // Beat AFTER the circuit is done — a healthy sweep keeps the heartbeat
            // fresh; a single circuit stalling past its own timeout still lets it
            // go stale so the supervisor can restart the poller.
            if ($onProgress) {
                $onProgress();
            }
        });
    }

    /** A circuit measured by direct ICMP (so it can go in the parallel batch). */
    private function isBatchable(Circuit $circuit): bool
    {
        return $circuit->monitor_via !== 'sdwan';
    }

    /** Loss/rtt for one circuit: from the parallel batch if it was in it, else a probe. */
    private function measure(Circuit $circuit, array $measured): array
    {
        if ($this->isBatchable($circuit) && array_key_exists($circuit->monitored_ip, $measured)) {
            return $measured[$circuit->monitored_ip] ?? ['loss' => 100, 'rtt' => null];
        }

        return $this->probe($circuit);
    }

    public function check(Circuit $circuit): void
    {
        $this->applyResult($circuit, $this->probe($circuit));
    }

    /** Record a measured result and drive the alarm lifecycle for one circuit. */
    private function applyResult(Circuit $circuit, array $result): void
    {
        ['loss' => $loss, 'rtt' => $responseMs] = $result;
        $isUp = $loss < 100;              // any reply at all = still passing traffic

        // SD-WAN authority: OUR direct ICMP to a public ISP gateway is unreliable from
        // the collector's vantage (routing / ICMP filtering) — it false-DOWNed #113 while
        // the appliance's own next-hop showed wan0 reachable and the site had internet.
        // The appliance is the ground truth for its WAN, so a Nodus timeout is overridden
        // when the appliance reports that circuit's next-hop reachable. A REAL WAN outage
        // still drops the next-hop AND raises the appliance's SNMP alarm, so it isn't masked.
        if (! $isUp && $this->applianceNextHopUp($circuit)) {
            $isUp = true;
            $loss = 0;
            $responseMs = null; // the appliance confirms traffic; we have no RTT of our own
        }

        $wasUp = $circuit->status === 'up';

        $circuit->update([
            'status' => $isUp ? 'up' : 'down',
            'last_loss_pct' => $loss,
            'last_checked_at' => now(),
        ]);

        // One history point per cycle; a null response time is a timeout, which
        // the Response Time graph renders as a gap rather than a zero. loss_pct
        // records the brownout trend even while the circuit is still "up".
        CircuitMetricHistory::create([
            'circuit_id' => $circuit->id,
            'recorded_at' => now(),
            'response_time_ms' => $responseMs,
            'loss_pct' => $loss,
        ]);

        // Sustained loss (median of the recent polls, now including this one) — a
        // single dropped probe is a transient spike that recovers next cycle, so it
        // never moves the median off 0. Only loss that persists reads as degraded.
        $circuit->update(['sustained_loss_pct' => $this->sustainedLoss($circuit)]);

        if ($wasUp && ! $isUp) {
            CircuitAlert::create([
                'circuit_id' => $circuit->id,
                'started_at' => now(),
                // Reason: a brownout of partial loss just before the outage means
                // packet loss took it down; an abrupt jump to 100% is a hard down
                // (link/carrier). Distinguishes "flapping/lossy" from "cable cut".
                'cause' => $this->classifyCause($circuit),
                'detected_loss_pct' => $loss,
            ]);
        }

        // Recovery is level-triggered, not edge-triggered: whenever the circuit is
        // currently passing traffic, close EVERY open alert — not only on the exact
        // down→up poll. An alert opened out-of-band (a seeded/imported legacy alarm
        // with a null cause, or one whose down-status write was later overwritten)
        // never satisfies `! $wasUp`, so an edge-only close left it hanging as a
        // false "circuit down" on the dashboard forever even though every poll read
        // 0% loss. #024 Boca (ticket 35049308) did exactly that for over a day.
        if ($isUp) {
            $circuit->alerts()
                ->whereNull('ended_at')
                ->update(['ended_at' => now()]);
        }
    }

    /**
     * Does the site's SD-WAN appliance report THIS circuit's WAN next-hop reachable?
     * That is the appliance's own view of its uplink (from `show system nexthops`) — the
     * authoritative signal when our direct gateway ping disagrees. Matched by the
     * circuit's wan_interface (wan0/wan1) or the gateway IP the next-hop table lists.
     */
    private function applianceNextHopUp(Circuit $circuit): bool
    {
        if ($circuit->site_id === null) {
            return false;
        }
        $edgeIds = Device::where('site_id', $circuit->site_id)->where('role', 'edgeconnect')->pluck('id');
        if ($edgeIds->isEmpty()) {
            return false;
        }

        $wan = strtolower(trim((string) $circuit->wan_interface));
        $gw = strtolower(trim((string) ($circuit->gateway_ip ?: $circuit->monitored_ip)));

        return DeviceNextHop::whereIn('device_id', $edgeIds)
            ->where('status', '!=', 'down')
            ->get(['interface', 'ip_address'])
            ->contains(function ($nh) use ($wan, $gw) {
                $iface = strtolower(trim((string) $nh->interface));
                $ip = strtolower(trim((string) $nh->ip_address));

                return ($wan !== '' && $iface === $wan) || ($gw !== '' && $ip === $gw);
            });
    }

    /**
     * Median loss over the last few polls. The median (not the average) rejects a
     * lone spike: [0,0,0,0,20] → 0, while genuinely lossy [0,20,20,20,40] → 20.
     */
    private function sustainedLoss(Circuit $circuit): int
    {
        $recent = $circuit->metricHistory()
            ->latest('recorded_at')
            ->take(5)
            ->pluck('loss_pct')
            ->filter(fn ($l) => $l !== null)
            ->map(fn ($l) => (int) $l)
            ->sort()
            ->values();

        if ($recent->isEmpty()) {
            return 0;
        }

        $mid = intdiv($recent->count(), 2);

        return $recent->count() % 2
            ? (int) $recent[$mid]
            : (int) round(($recent[$mid - 1] + $recent[$mid]) / 2);
    }

    /**
     * Was this outage a hard down or the tail of a packet-loss brownout? Look at
     * the cycles just BEFORE this one (the point recording 100% is already saved):
     * any recent partial loss (1–99%) means loss was building → 'packet_loss';
     * otherwise the circuit dropped straight from clean to dead → 'hard_down'.
     */
    private function classifyCause(Circuit $circuit): string
    {
        $recent = $circuit->metricHistory()
            ->latest('recorded_at')
            ->skip(1)->take(3)          // skip the just-saved 100% point
            ->pluck('loss_pct');

        $brownout = $recent->contains(fn ($l) => $l !== null && $l > 0 && $l < 100);

        return $brownout ? 'packet_loss' : 'hard_down';
    }

    /**
     * Reachability probe for a circuit. Default = direct ICMP to the monitored
     * public IP, measuring packet loss. For a DHCP circuit behind ISP NAT
     * (unreachable public IP), the "sdwan" method sources a ping FROM the site's
     * Silver Peak out the circuit's WAN — real proof the circuit passes traffic,
     * but a single reply, so loss is only 0 (up) or 100 (down).
     *
     * @return array{loss:int, rtt:?float}
     */
    private function probe(Circuit $circuit): array
    {
        $direct = fn (): array => ($this->pinger)($circuit->monitored_ip) ?? ['loss' => 100, 'rtt' => null];

        if ($circuit->monitor_via !== 'sdwan' || ! $this->sdwanPinger) {
            return $direct();
        }

        $edge = Device::where('site_id', $circuit->site_id)
            ->where('role', 'edgeconnect')
            ->first();

        // Misconfigured (no Silver Peak or no WAN chosen) — fall back to ICMP so
        // the circuit is still watched rather than silently unmonitored.
        if (! $edge || ! $circuit->wan_interface) {
            return $direct();
        }

        $rtt = ($this->sdwanPinger)($edge, $circuit->wan_interface, $circuit->ping_target ?: '8.8.8.8');

        return ['loss' => $rtt !== null ? 0 : 100, 'rtt' => $rtt];
    }
}
