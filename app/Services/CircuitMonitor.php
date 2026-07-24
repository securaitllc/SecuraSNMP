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
     * @param  callable(string): ?float  $pinger  RTT in ms, or null on timeout.
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
        $responseMs = $this->probe($circuit);
        $isUp = $responseMs !== null;
        $wasUp = $circuit->status === 'up';

        $circuit->update([
            'status' => $isUp ? 'up' : 'down',
            'last_checked_at' => now(),
        ]);

        // One history point per cycle; a null response time is a timeout, which
        // the Response Time graph renders as a gap rather than a zero.
        CircuitMetricHistory::create([
            'circuit_id' => $circuit->id,
            'recorded_at' => now(),
            'response_time_ms' => $responseMs,
        ]);

        if ($wasUp && ! $isUp) {
            CircuitAlert::create([
                'circuit_id' => $circuit->id,
                'started_at' => now(),
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
     * Reachability probe for a circuit. Default = direct ICMP to the monitored
     * public IP. For a DHCP circuit behind ISP NAT (unreachable public IP), the
     * "sdwan" method sources a ping FROM the site's Silver Peak out the circuit's
     * WAN instead — real proof the circuit passes traffic.
     */
    private function probe(Circuit $circuit): ?float
    {
        if ($circuit->monitor_via !== 'sdwan' || ! $this->sdwanPinger) {
            return ($this->pinger)($circuit->monitored_ip);
        }

        $edge = Device::where('site_id', $circuit->site_id)
            ->where('role', 'edgeconnect')
            ->first();

        // Misconfigured (no Silver Peak or no WAN chosen) — fall back to ICMP so
        // the circuit is still watched rather than silently unmonitored.
        if (! $edge || ! $circuit->wan_interface) {
            return ($this->pinger)($circuit->monitored_ip);
        }

        return ($this->sdwanPinger)($edge, $circuit->wan_interface, $circuit->ping_target ?: '8.8.8.8');
    }
}
