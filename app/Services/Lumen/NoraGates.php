<?php

namespace App\Services\Lumen;

use App\Models\Circuit;
use App\Models\DeviceAlarm;
use Illuminate\Support\Facades\DB;

/**
 * Whether a circuit's trouble is worth telling the carrier about.
 *
 * Phase 1 is a human clicking Send, so these are warnings on the preview rather than
 * hard stops. They exist because of what the app got wrong this week: twenty circuits
 * graded lossy that the carrier could not have found a fault on, then 232 graded down
 * because ICMP was being steered on our own side. Every one of those would have been
 * a ticket. NORA filters senders it judges suspicious; a burst like that would get the
 * NOC mailbox flagged and nothing from Nodus would reach Lumen again.
 *
 * Three questions, in order of weight:
 *
 *   corroborated  — does the appliance on that WAN agree? ICMP to a carrier gateway is
 *                   policed, steerable and fooled by anything on our side; the
 *                   EdgeConnect terminating the circuit is not. SNMP is the authority.
 *   sustained     — has it been down long enough to not be a flap?
 *   breaker       — is a large share of this carrier's circuits down at once? Then it
 *                   is not N carrier faults, it is us, and no email should go out.
 */
class NoraGates
{
    /**
     * @return array{corroborated: bool, sustained: bool, breaker: bool, warnings: list<string>, carrier_down: int, carrier_total: int}
     */
    public function evaluate(Circuit $circuit): array
    {
        $corroborated = $this->applianceCorroborates($circuit);
        $sustained = $this->sustained($circuit);
        [$down, $total] = $this->carrierCounts($circuit);
        $pct = $total > 0 ? $down / $total * 100 : 0;
        $breaker = $down >= (int) config('nora.breaker_min') && $pct >= (int) config('nora.breaker_pct');

        $warnings = [];
        if ($breaker) {
            $warnings[] = "{$down} of {$total} ".($circuit->isp_name ?: 'carrier')." circuits are down right now. That is not {$down} separate carrier faults — check our side before emailing Lumen. A burst of tickets can get this mailbox flagged by NORA.";
        }
        if (! $corroborated) {
            $warnings[] = 'The appliance on this WAN reports nothing wrong. Only our ICMP to the carrier gateway is failing — that can be the carrier policing pings, not the circuit. Consider "Ask Lumen to check" first.';
        }
        if (! $sustained) {
            $warnings[] = 'Trouble seen for under '.config('nora.sustained_minutes').' minutes, or no start time recorded. Could be a flap.';
        }

        return compact('corroborated', 'sustained', 'breaker', 'warnings') + ['carrier_down' => $down, 'carrier_total' => $total];
    }

    /** Any open appliance alarm naming this circuit's WAN or its gateway. */
    private function applianceCorroborates(Circuit $circuit): bool
    {
        if ($circuit->site_id === null) {
            return false;
        }
        $wan = strtolower(trim((string) $circuit->wan_interface));
        $gw = trim((string) $circuit->gateway_ip);
        $deviceIds = DB::table('devices')->where('site_id', $circuit->site_id)->where('role', 'edgeconnect')->pluck('id');
        if ($deviceIds->isEmpty()) {
            return false;
        }

        return DeviceAlarm::query()
            ->whereIn('device_id', $deviceIds)
            ->whereNull('cleared_at')
            ->get(['alarm_id', 'description'])
            ->contains(function (DeviceAlarm $a) use ($wan, $gw) {
                $text = strtolower($a->alarm_id.' '.$a->description);
                if ($gw !== '' && str_contains($text, 'gw:'.strtolower($gw))) {
                    return true;
                }

                return $wan !== '' && preg_match('/\b'.preg_quote($wan, '/').'\b/', $text) === 1;
            });
    }

    private function sustained(Circuit $circuit): bool
    {
        $since = $circuit->alerts()->whereNull('ended_at')->latest('started_at')->value('started_at') ?? $circuit->degraded_since;
        if ($since === null) {
            return false;
        }

        // Carbon 3 returns a SIGNED float: now()->diffInMinutes($past) is NEGATIVE, so
        // the naive form is always false and nothing ever reads as sustained. Diff from
        // the earlier instant forward, which is unambiguous.
        return $since->diffInMinutes(now()) >= (int) config('nora.sustained_minutes');
    }

    /** @return array{0:int,1:int} [down, total] for this circuit's carrier */
    private function carrierCounts(Circuit $circuit): array
    {
        if (! $circuit->isp_name) {
            return [0, 0];
        }
        $q = Circuit::where('isp_name', $circuit->isp_name)->where('monitoring_enabled', true);

        return [(clone $q)->where('status', 'down')->count(), $q->count()];
    }
}
