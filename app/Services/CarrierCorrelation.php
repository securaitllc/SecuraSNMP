<?php

namespace App\Services;

use App\Models\Circuit;
use App\Models\CircuitPathProbe;
use Illuminate\Support\Collection;

/**
 * Circuits degraded together because of something upstream of all of them.
 *
 * Seventeen Lumen circuits were losing packets at once and the app showed seventeen
 * unrelated degraded rows. An operator reading one circuit could never see the shape:
 * it took a fleet-wide query to find that the loss clusters by DESTINATION PREFIX —
 * ten of the fourteen circuits reached through 64.159.0.0/16 lossy, all thirty-four
 * through 4.42.0.0/16 clean, same collector, same minute.
 *
 * WHAT MAKES THAT A FINDING AND NOT A COUNT: the clean circuits. "Seventeen Lumen
 * circuits are lossy" says nothing on its own — Lumen is most of the fleet. The
 * argument the carrier has to answer is the contrast, and the contrast is also what
 * exonerates our own side: every one of these probes leaves through the same firewall
 * and the same head-end port, so a fault there would hit all of them evenly. A split
 * that follows the destination cannot be produced by a shared egress.
 *
 * THE LIMIT, stated because it changes what the finding means: each circuit is measured
 * by pinging the carrier's own gateway address. ICMP addressed TO a router is
 * control-plane traffic and routers police it. This is evidence of something shared
 * upstream; it is NOT proof that customer traffic THROUGH those gateways is dropping.
 * The payload carries the caveat so every surface repeats it.
 */
class CarrierCorrelation
{
    /** Fewer degraded circuits than this on one carrier is not a pattern. */
    private const MIN_AFFECTED = 3;

    /** A prefix at or above this share of its circuits degraded is called hot. */
    private const HOT_PREFIX_PCT = 40;

    /** A prefix group smaller than this proves nothing either way. */
    private const MIN_PREFIX_GROUP = 2;

    /**
     * How far back to look for healthy circuits' paths when testing whether a hop
     * actually discriminates. Long enough that most of the fleet has been traced,
     * short enough that the comparison is to the network as it is now.
     */
    private const CONTROL_WINDOW_HOURS = 12;

    public const CAVEAT = 'Each circuit is measured by pinging the carrier gateway itself. Routers police ICMP addressed to them, so this shows a shared upstream problem — not proof that traffic through those gateways is dropping. A named hop is only worth quoting to a carrier when the healthy circuits crossing it are clean: every probe leaves from the same building, so the first carrier hop is on every path and will always look guilty if you only count the sick ones.';

    /**
     * One finding per carrier that has several circuits degraded at once.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findings(): array
    {
        $circuits = Circuit::query()
            ->where('monitoring_enabled', true)
            ->whereNotNull('monitored_ip')
            ->with('site:id,name,site_number')
            ->get();

        $degradedAll = $circuits->filter(fn (Circuit $c) => self::isDegraded($c));

        $out = [];
        foreach ($circuits->groupBy('isp_name') as $isp => $group) {
            if ($isp === null || $isp === '') {
                continue;
            }
            $degraded = $group->filter(fn (Circuit $c) => self::isDegraded($c));
            if ($degraded->count() < self::MIN_AFFECTED) {
                continue;
            }

            $prefixes = $this->prefixBreakdown($group);

            $out[] = [
                'isp_name' => $isp,
                'circuits_total' => $group->count(),
                'circuits_degraded' => $degraded->count(),
                'degraded_pct' => (int) round($degraded->count() / $group->count() * 100),
                'peak_loss_pct' => (int) $degraded->max('loss_peak_pct'),
                // Null until the poller stamps a crossing — a fleet that has just
                // restarted has none, and that must not read as "started just now".
                'since' => optional($degraded->whereNotNull('degraded_since')->min('degraded_since'))?->toIso8601String(),
                'suspect_hop' => $this->suspectHop($degraded, $group),
                'prefixes' => $prefixes,
                'hot_prefixes' => array_values(array_filter($prefixes, fn ($p) => $p['hot'])),
                'clean_prefixes' => array_values(array_filter($prefixes, fn ($p) => $p['degraded'] === 0 && $p['total'] >= self::MIN_PREFIX_GROUP)),
                // The rest of the fleet, so the reader can see this carrier is the
                // outlier and not just the biggest group.
                'fleet' => [
                    'circuits_total' => $circuits->count(),
                    'circuits_degraded' => $degradedAll->count(),
                    'other_isps_degraded' => $degradedAll->count() - $degraded->count(),
                ],
                'circuits' => $degraded->sortByDesc('loss_polls_pct')->map(fn (Circuit $c) => [
                    'id' => $c->id,
                    'circuit_id' => $c->circuit_id,
                    // The carrier's own two references. A Lumen tech asks for the LEC
                    // circuit ID, not ours, and half the fleet has one — so both go
                    // out, and neither is invented when it is missing.
                    'lec_circuit_id' => $c->lec_circuit_id,
                    'account_number' => $c->account_number,
                    'site_name' => $c->site?->name,
                    'site_number' => $c->site?->site_number,
                    'monitored_ip' => $c->monitored_ip,
                    'prefix' => self::prefix16($c->monitored_ip),
                    'loss_polls_pct' => (int) ($c->loss_polls_pct ?? 0),
                    'loss_peak_pct' => (int) ($c->loss_peak_pct ?? 0),
                    'degraded_since' => optional($c->degraded_since)?->toIso8601String(),
                    'isp_ticket' => $c->isp_ticket,
                ])->values()->all(),
                'caveat' => self::CAVEAT,
            ];
        }

        // Most degraded circuits first — that is the one to work.
        usort($out, fn ($a, $b) => $b['circuits_degraded'] <=> $a['circuits_degraded']);

        return $out;
    }

    /**
     * How one carrier's circuits split by the /16 of the address we measure.
     *
     * The /16 is a stand-in for "reached the same way inside the carrier". It is not
     * routing truth and does not claim to be: two prefixes can share a path, one prefix
     * can split across two. It is the coarsest grouping that separated the signal on
     * this fleet, and the suspect hop is what actually names the box.
     *
     * Every prefix is returned, hot and clean alike — the clean ones ARE the argument.
     *
     * @param  Collection<int, Circuit>  $circuits
     * @return array<int, array<string, mixed>>
     */
    private function prefixBreakdown(Collection $circuits): array
    {
        $rows = [];
        foreach ($circuits->groupBy(fn (Circuit $c) => self::prefix16($c->monitored_ip)) as $prefix => $group) {
            if ($prefix === '') {
                continue;
            }
            $degraded = $group->filter(fn (Circuit $c) => self::isDegraded($c))->count();
            $pct = (int) round($degraded / $group->count() * 100);
            $rows[] = [
                'prefix' => $prefix,
                'total' => $group->count(),
                'degraded' => $degraded,
                'degraded_pct' => $pct,
                // A group of one is noise in either direction, so it is never called hot.
                'hot' => $degraded > 0 && $pct >= self::HOT_PREFIX_PCT && $group->count() >= self::MIN_PREFIX_GROUP,
            ];
        }

        // Hot groups lead. Without this a lone circuit at 100% outranks ten of fourteen
        // at 71% and the loudest chip on the card is the one that proves nothing.
        usort($rows, fn ($a, $b) => [$b['hot'], $b['degraded_pct'], $b['total']] <=> [$a['hot'], $a['degraded_pct'], $a['total']]);

        return $rows;
    }

    /**
     * The hop most of these circuits blame — but only if the healthy ones do not
     * cross it happily.
     *
     * Counting how often a hop is named is not evidence on its own. Every probe
     * leaves from the same place, so the first carrier hop out of that building is
     * on EVERY path: ae31-527.bar3.Orlando1.Level3.net was named by 18 of 21
     * degraded Lumen circuits, and it was also hop 5, at 0% loss, on the 141 that
     * were fine — and on a COX circuit in Oklahoma, whose traffic has nothing to do
     * with Lumen's Orlando backbone. Lumen's engineer said their counters were clean
     * and their counters were clean; the correlation was pointing at the door
     * everyone walks through, not at a fault.
     *
     * So a hop must DISCRIMINATE: lossy on the degraded circuits and absent, or
     * clean, on the healthy ones. A hop that every path crosses without trouble is
     * reported as shared transit rather than as a suspect, because sending a carrier
     * after it burns the relationship needed for the ticket that is real.
     *
     * @param  Collection<int, Circuit>  $degraded  circuits currently degraded
     * @param  Collection<int, Circuit>  $group  every circuit on this carrier
     */
    private function suspectHop(Collection $degraded, Collection $group): ?array
    {
        $latest = CircuitPathProbe::query()
            ->whereIn('circuit_id', $degraded->pluck('id'))
            ->whereNotNull('worst_hop_host')
            ->orderByDesc('ran_at')
            ->get(['circuit_id', 'worst_hop_host', 'worst_hop_loss_pct', 'ran_at'])
            ->unique('circuit_id');

        if ($latest->isEmpty()) {
            return null;
        }

        $counts = $latest->groupBy('worst_hop_host')->map->count()->sortDesc();
        $host = (string) $counts->keys()->first();

        [$crossings, $lossy] = $this->controlCrossings($host, $group, $degraded);

        // A hop earns the blame only when it treats the sick circuits differently
        // from the well ones.
        //
        // Lossy for the DEGRADED and clean for the HEALTHY → it discriminates, and
        // the difference is worth a carrier's time.
        //
        // Lossy for BOTH → it is lossy for everyone, and yet the healthy circuits are
        // healthy end to end. That is the proof it is NOT dropping traffic: it is
        // policing the ICMP addressed to it. 18 September, ae31-527: 12 of 14
        // degraded circuits blamed it, and 7 of the 8 healthy circuits that crossed
        // it saw loss there too while staying fine to their destinations. That is a
        // policer, exactly as Lumen said — and the first cut of this check read those
        // 7 as corroboration instead of as the refutation they are.
        //
        // No healthy circuit crossed it → no control group, but the hop is at least
        // specific to the affected paths, so it stands with the counts shown.
        $discriminating = $crossings === 0 || $lossy === 0;

        return [
            'host' => $host,
            'named_by' => $counts->first(),
            'of_probed' => $latest->count(),
            'worst_loss_pct' => (int) $latest->where('worst_hop_host', $host)->max('worst_hop_loss_pct'),
            // Loss that reached the DESTINATION, which is the number a carrier cannot
            // wave away as ICMP policing — loss at a hop is what their policers
            // produce, loss at the far end is not. Lead with this one.
            'end_loss_pct' => (int) CircuitPathProbe::query()
                ->whereIn('circuit_id', $degraded->pluck('id'))
                ->where('ran_at', '>=', now()->subHours(self::CONTROL_WINDOW_HOURS))
                ->max('end_loss_pct'),
            // The control group, stated out loud so the number can be argued with.
            'healthy_crossings' => $crossings,
            'healthy_crossings_lossy' => $lossy,
            'discriminating' => $discriminating,
        ];
    }

    /**
     * How many HEALTHY circuits on this carrier cross $host, and how many of those
     * see loss at it. This is the control group the finding lives or dies by.
     *
     * @param  Collection<int, Circuit>  $group
     * @param  Collection<int, Circuit>  $degraded
     * @return array{0: int, 1: int}
     */
    private function controlCrossings(string $host, Collection $group, Collection $degraded): array
    {
        if ($host === '') {
            return [0, 0];
        }

        $healthyIds = $group->pluck('id')->diff($degraded->pluck('id'));
        if ($healthyIds->isEmpty()) {
            return [0, 0];
        }

        $probes = CircuitPathProbe::query()
            ->whereIn('circuit_id', $healthyIds)
            ->where('ran_at', '>=', now()->subHours(self::CONTROL_WINDOW_HOURS))
            ->orderByDesc('ran_at')
            ->get(['circuit_id', 'hops'])
            ->unique('circuit_id');

        $crossings = 0;
        $lossy = 0;

        foreach ($probes as $probe) {
            foreach ((array) $probe->hops as $hop) {
                if ((string) ($hop['host'] ?? $hop['ip'] ?? '') !== $host) {
                    continue;
                }

                $crossings++;
                if ((int) ($hop['loss_pct'] ?? 0) > 0) {
                    $lossy++;
                }
                break;
            }
        }

        return [$crossings, $lossy];
    }

    private static function isDegraded(Circuit $c): bool
    {
        return (int) ($c->loss_polls_pct ?? 0) >= CircuitMonitor::LOSSY_POLLS_DEGRADED_PCT;
    }

    /** The /16 of an address — 64.159.12.1 becomes 64.159.0.0/16. */
    public static function prefix16(?string $ip): string
    {
        if (! $ip || ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return '';
        }
        [$a, $b] = explode('.', $ip);

        return "{$a}.{$b}.0.0/16";
    }
}
