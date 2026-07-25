<?php

namespace App\Services;

use App\Models\Circuit;
use App\Models\CircuitAlert;
use App\Models\CircuitMetricHistory;
use App\Models\Device;
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
     */
    public function __construct(private $pinger, private $sdwanPinger = null)
    {
    }

    public function checkAll(): void
    {
        // Skip circuits taken out of monitoring (planned disconnect / maintenance)
        // so they don't ping or raise a false "circuit down".
        Circuit::where('monitoring_enabled', true)->get()->each(function (Circuit $circuit) {
            try {
                $this->check($circuit);
            } catch (Throwable $e) {
                Log::error("Circuit monitor failed for circuit {$circuit->id}: {$e->getMessage()}");
            }
        });
    }

    public function check(Circuit $circuit): void
    {
        ['loss' => $loss, 'rtt' => $responseMs] = $this->probe($circuit);
        $isUp = $loss < 100;              // any reply at all = still passing traffic
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

        if (! $wasUp && $isUp) {
            $circuit->alerts()
                ->whereNull('ended_at')
                ->latest('started_at')
                ->first()
                ?->update(['ended_at' => now()]);
        }
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
