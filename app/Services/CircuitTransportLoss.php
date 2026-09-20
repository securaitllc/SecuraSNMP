<?php

namespace App\Services;

use App\Models\Circuit;
use App\Models\Device;

/**
 * Circuit loss measured on the traffic that actually crosses it.
 *
 * Every loss figure in this app came from pinging the carrier's own gateway. A
 * router polices ICMP addressed to itself, so that number measured a rate limiter
 * rather than a circuit. On 20 September, 300 packets to Lumen's provider edge at
 * #037 lost 8% while 300 to our own appliance — one hop FURTHER, through that same
 * gateway — lost none, and the underlay tunnel on that circuit had carried 574,089
 * packets in 23 hours with zero lost. A week of investigation and three rounds with
 * the carrier chased a policer doing its job.
 *
 * The appliance was counting the real thing the whole time. `show tunnel` reports an
 * underlay tunnel per WAN circuit, carrying the packets that crossed it and the ones
 * that did not arrive, and none of it involves ICMP or anything a firewall must be
 * opened for.
 *
 * MATCHING: a tunnel's `Local IP address` is the appliance's own address on the
 * circuit, and the circuit record holds the carrier gateway. On a /30 handoff those
 * are the two usable addresses, so the gateway identifies the circuit without
 * needing anything new collected.
 */
class CircuitTransportLoss
{
    /**
     * Below this many packets in an interval, a percentage is noise. Four packets
     * losing one is not 25% loss, and a standby tunnel carrying nothing is not a
     * healthy circuit — it is an unmeasured one.
     */
    public const MIN_SAMPLE_PKTS = 200;

    /**
     * Record what the appliance measured, for every circuit its underlay tunnels
     * can be matched to.
     *
     * @param  array<string, array<string, mixed>>  $underlays  keyed by tunnel name
     * @return int circuits updated
     */
    public function record(Device $device, array $underlays): int
    {
        if ($underlays === []) {
            return 0;
        }

        $circuits = Circuit::where('site_id', $device->site_id)
            ->whereNotNull('gateway_ip')
            ->get();

        if ($circuits->isEmpty()) {
            return 0;
        }

        // Several underlay tunnels can ride one circuit (one per remote hub), so
        // their counters are summed: the circuit carried all of it.
        $byCircuit = [];

        foreach ($underlays as $tunnel) {
            $local = $tunnel['local_ip'] ?? null;
            if (! $local) {
                continue;
            }

            $circuit = $circuits->first(fn (Circuit $c) => self::sameHandoff($local, (string) $c->gateway_ip));
            if (! $circuit) {
                continue;
            }

            $byCircuit[$circuit->id] ??= ['circuit' => $circuit, 'rx' => 0, 'lost' => 0];
            $byCircuit[$circuit->id]['rx'] += (int) ($tunnel['rx_pkts'] ?? 0);
            $byCircuit[$circuit->id]['lost'] += (int) ($tunnel['lost_pkts'] ?? 0);
        }

        $updated = 0;

        foreach ($byCircuit as $row) {
            $this->apply($row['circuit'], $row['rx'], $row['lost']);
            $updated++;
        }

        return $updated;
    }

    /**
     * Turn two cumulative counters into a loss rate for the interval just elapsed.
     */
    private function apply(Circuit $circuit, int $rx, int $lost): void
    {
        $prevRx = $circuit->transport_rx_pkts;
        $prevLost = $circuit->transport_lost_pkts;

        // A counter that went backwards is an appliance reboot or a counter wrap.
        // Re-baseline rather than report a nonsense negative delta.
        $wrapped = $prevRx !== null && ($rx < $prevRx || $lost < $prevLost);

        $rxDelta = ($prevRx === null || $wrapped) ? 0 : $rx - $prevRx;
        $lostDelta = ($prevLost === null || $wrapped) ? 0 : $lost - $prevLost;
        $sample = $rxDelta + $lostDelta;

        // Null, not zero. A tunnel that carried nothing this interval tells us
        // nothing about the circuit, and a standby path reading 0% loss is the
        // false-healthy pattern this codebase keeps paying for.
        $lossPct = $sample >= self::MIN_SAMPLE_PKTS
            ? round($lostDelta / $sample * 100, 3)
            : null;

        $circuit->forceFill([
            'transport_loss_pct' => $lossPct,
            'transport_rx_pkts' => $rx,
            'transport_lost_pkts' => $lost,
            'transport_sample_pkts' => $sample,
            'transport_measured_at' => now(),
        ])->save();
    }

    /**
     * Are these two addresses the two ends of the same point-to-point handoff?
     *
     * A carrier hands off a /30: one address theirs, one ours. Comparing on the /30
     * means the appliance's own WAN address identifies the circuit from the gateway
     * already on the record, with nothing extra to collect or keep in step.
     */
    public static function sameHandoff(string $a, string $b): bool
    {
        if (! filter_var($a, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            || ! filter_var($b, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        if ($a === $b) {
            return false;   // the gateway is not the appliance
        }

        return (ip2long($a) & ~3) === (ip2long($b) & ~3);
    }
}
