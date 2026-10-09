<?php

namespace App\Services;

use App\Models\Circuit;
use App\Models\CircuitAlert;
use App\Models\Device;
use App\Models\DeviceAlarm;
use App\Models\DeviceNextHop;
use App\Models\NextHopAlert;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Tells the TRUE story of a circuit's WAN outage.
 *
 * A single WAN failure surfaces as several alarms that start at DIFFERENT times: the
 * IP-SLA monitor trips when the link first degrades, then (often hours later) the
 * next-hop goes unreachable and the tunnels drop when it hard-fails. Showing any one
 * of those clocks — usually the loud critical one — hides how long the WAN has really
 * been down. Reproduced on #063 Baton Rouge: IP-SLA on wan0 down since the previous
 * night, next-hop + tunnels only ~5h old, so the board read "5h" for a >15h outage.
 *
 * This resolver gathers every down-signal that maps to ONE circuit (circuit ping,
 * EdgeConnect gateway / IP-SLA / edge-unreachable, next-hop), MERGES them into outage
 * segments (concurrent alarms = one event; repeated on/off = separate segments), and
 * reports:
 *   - the OUTAGE ENVELOPE — down since the earliest still-open signal, with the later
 *     hard-down escalation kept as a second line, nothing hidden;
 *   - BOUNCE — how many distinct outage segments in a recent window, so a flapping
 *     WAN is called out instead of reading as a short, misleading single outage;
 *   - HISTORY — every related alert, time-sorted, for one drill-down instead of
 *     hunting across the circuit, device, next-hop and tunnel views.
 *
 * Mapping reuses AlarmCircuitResolver — the same vocabulary the alarm grouping uses.
 */
class CircuitOutageResolver
{
    /** Alerts closer than this are treated as one continuous outage, not two flaps. */
    private const MERGE_GAP_MIN = 2;

    /** Bounce window + the segment count that reads as "flapping". */
    public const BOUNCE_WINDOW_HOURS = 6;

    public const BOUNCE_MIN_SEGMENTS = 3;

    /**
     * Full picture for one circuit: time-sorted related alerts + envelope + bounce.
     *
     * @return array{items: array<int, array>, envelope: array, bounce: array}
     */
    public function history(Circuit $circuit, int $days = 7): array
    {
        $since = CarbonImmutable::now()->subDays(max(1, $days));
        $items = $this->relatedAlerts($circuit, $since);

        // Newest first for display; the envelope/bounce math re-sorts as it needs.
        usort($items, fn ($a, $b) => strcmp((string) $b['started_at'], (string) $a['started_at']));

        return [
            'items' => $items,
            'envelope' => $this->envelope($items),
            'bounce' => $this->bounce($items),
        ];
    }

    /**
     * Batch envelope + bounce for a set of circuits, for the Circuits list payload.
     * Only the circuits that are actually impacted get a lookup, so the list stays cheap.
     *
     * @param  Collection<int, Circuit>  $circuits
     * @return array<int, array{outage: array, bounce: array}>  circuit id => summary
     */
    public function summarize(Collection $impacted): array
    {
        $out = [];
        foreach ($impacted as $circuit) {
            $h = $this->history($circuit, self::BOUNCE_WINDOW_HOURS > 0 ? 7 : 7);
            if ($h['envelope']['down_since'] !== null || $h['bounce']['flapping']) {
                $out[$circuit->id] = ['outage' => $h['envelope'], 'bounce' => $h['bounce']];
            }
        }

        return $out;
    }

    /**
     * Every down-signal that resolves to THIS circuit, normalized to one shape.
     *
     * @return array<int, array>
     */
    private function relatedAlerts(Circuit $circuit, CarbonImmutable $since): array
    {
        $items = [];

        // 1) Circuit ICMP ping alerts — this circuit's own gateway loss.
        $pings = CircuitAlert::where('circuit_id', $circuit->id)
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>=', $since))
            ->get();
        foreach ($pings as $a) {
            $items[] = $this->item('circuit-ping', 'Circuit down — packet loss', 'critical',
                $a->started_at, $a->ended_at, $a->ticket_number, 'cp:'.$a->id);
        }

        if ($circuit->site_id === null) {
            return $items;
        }

        // The site's SD-WAN edge is the source of the transport alarms + next-hops.
        $edge = Device::where('site_id', $circuit->site_id)->where('role', 'edgeconnect')
            ->orderBy('id')->first();
        if ($edge === null) {
            return $items;
        }

        $nextHops = DeviceNextHop::where('device_id', $edge->id)->get();
        $siteCircuits = Circuit::where('site_id', $circuit->site_id)->get();
        $resolver = new AlarmCircuitResolver;

        // 2) EdgeConnect transport alarms (gateway / IP-SLA / WAN) mapped to this circuit,
        //    plus an unreachable edge (which darkens every circuit at the site).
        $alarms = DeviceAlarm::where('device_id', $edge->id)
            ->where(fn ($q) => $q->whereNull('cleared_at')->orWhere('cleared_at', '>=', $since))
            ->get();
        foreach ($alarms as $al) {
            $isEdgeDown = $al->alarm_id === 'device-unreachable';
            if (! $isEdgeDown && ! AlarmCircuitResolver::isLocalTransport($al->alarm_id, (string) $al->description)) {
                continue;
            }
            $c = $isEdgeDown
                ? $circuit
                : $resolver->resolve($al->alarm_id, (string) $al->description, $siteCircuits, $nextHops);
            if ($c === null || $c->id !== $circuit->id) {
                continue;
            }
            $items[] = $this->item(
                $this->sourceLabel($al->alarm_id, $isEdgeDown),
                (string) $al->description,
                $al->severity === 'warning' ? 'warning' : 'critical',
                $al->first_seen_at, $al->cleared_at, $al->ticket_number, 'da:'.$al->id,
            );
        }

        // 3) Next-hop alerts on the gateway this circuit rides.
        $nhIds = $nextHops->filter(function (DeviceNextHop $n) use ($circuit) {
            $ip = strtolower(trim((string) $n->ip_address));
            $iface = strtolower(trim((string) $n->interface));

            return ($ip !== '' && $ip === strtolower(trim((string) $circuit->gateway_ip)))
                || ($iface !== '' && $iface === strtolower(trim((string) $circuit->wan_interface)));
        })->pluck('id');
        if ($nhIds->isNotEmpty()) {
            $nhAlerts = NextHopAlert::where('device_id', $edge->id)
                ->whereIn('device_next_hop_id', $nhIds)
                ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>=', $since))
                ->get();
            foreach ($nhAlerts as $nh) {
                $items[] = $this->item('next-hop', 'Next-hop unreachable', 'critical',
                    $nh->started_at, $nh->ended_at, null, 'nh:'.$nh->id);
            }
        }

        return $items;
    }

    /**
     * The outage envelope: down since the earliest still-open signal, with the later
     * hard-down (first open CRITICAL) kept separate so the escalation stays visible.
     */
    private function envelope(array $items): array
    {
        $now = CarbonImmutable::now();
        $open = array_filter($items, fn ($i) => $i['ended_at'] === null);
        if ($open === []) {
            return ['down_since' => null, 'down_min' => 0, 'hard_down_since' => null, 'hard_down_min' => 0, 'primary' => null];
        }

        $starts = array_map(fn ($i) => CarbonImmutable::parse($i['started_at']), $open);
        $downSince = min($starts);
        $earliest = null;
        foreach ($open as $i) {
            if ($earliest === null || strcmp((string) $i['started_at'], (string) $earliest['started_at']) < 0) {
                $earliest = $i;
            }
        }

        $crit = array_filter($open, fn ($i) => $i['severity'] === 'critical');
        $hardSince = $crit === [] ? null : min(array_map(fn ($i) => CarbonImmutable::parse($i['started_at']), $crit));

        return [
            'down_since' => $downSince->toIso8601String(),
            'down_min' => (int) $downSince->diffInMinutes($now),
            'hard_down_since' => $hardSince?->toIso8601String(),
            // Only surface the escalation line when it's meaningfully later than the start.
            'hard_down_min' => $hardSince !== null ? (int) $hardSince->diffInMinutes($now) : 0,
            'escalated' => $hardSince !== null && $downSince->diffInMinutes($hardSince) >= 5,
            'primary' => $earliest['source'] ?? null,
        ];
    }

    /**
     * Bounce: merge all alerts into outage segments (concurrent = one, gaps = separate)
     * and count how many started inside the bounce window. Many segments = flapping.
     */
    private function bounce(array $items): array
    {
        $segments = $this->segments($items);
        $windowStart = CarbonImmutable::now()->subHours(self::BOUNCE_WINDOW_HOURS);
        $recent = array_filter($segments, fn ($s) => $s['start']->gte($windowStart));
        $flaps = count($recent);
        $lastFlap = $segments === [] ? null : max(array_map(fn ($s) => $s['start'], $segments));

        return [
            'flapping' => $flaps >= self::BOUNCE_MIN_SEGMENTS,
            'flaps' => $flaps,
            'window_h' => self::BOUNCE_WINDOW_HOURS,
            'last_flap_at' => $lastFlap?->toIso8601String(),
        ];
    }

    /**
     * Merge alert intervals into outage segments: sort by start, then absorb any interval
     * that begins within MERGE_GAP_MIN of the running segment's end.
     *
     * @return array<int, array{start: CarbonImmutable, end: CarbonImmutable, open: bool}>
     */
    private function segments(array $items): array
    {
        if ($items === []) {
            return [];
        }
        $now = CarbonImmutable::now();
        $intervals = array_map(fn ($i) => [
            'start' => CarbonImmutable::parse($i['started_at']),
            'end' => $i['ended_at'] === null ? $now : CarbonImmutable::parse($i['ended_at']),
            'open' => $i['ended_at'] === null,
        ], $items);
        usort($intervals, fn ($a, $b) => $a['start']->timestamp <=> $b['start']->timestamp);

        $segments = [];
        $cur = null;
        foreach ($intervals as $iv) {
            if ($cur === null) {
                $cur = $iv;

                continue;
            }
            if ($iv['start']->lte($cur['end']->addMinutes(self::MERGE_GAP_MIN))) {
                if ($iv['end']->gt($cur['end'])) {
                    $cur['end'] = $iv['end'];
                }
                $cur['open'] = $cur['open'] || $iv['open'];
            } else {
                $segments[] = $cur;
                $cur = $iv;
            }
        }
        if ($cur !== null) {
            $segments[] = $cur;
        }

        return $segments;
    }

    /** @return array<string, mixed> */
    private function item(string $source, string $title, string $severity, $started, $ended, ?string $ticket, string $key): array
    {
        $now = CarbonImmutable::now();
        $s = CarbonImmutable::parse($started);
        $e = $ended === null ? null : CarbonImmutable::parse($ended);

        return [
            'key' => $key,
            'source' => $source,
            'title' => $title,
            'severity' => $severity,
            'started_at' => $s->toIso8601String(),
            'ended_at' => $e?->toIso8601String(),
            'duration_min' => (int) $s->diffInMinutes($e ?? $now),
            'ongoing' => $e === null,
            'ticket' => $ticket,
        ];
    }

    private function sourceLabel(string $alarmId, bool $edgeDown): string
    {
        if ($edgeDown) {
            return 'edge-unreachable';
        }
        $src = strtolower(AlarmCircuitResolver::sourceOf($alarmId));

        return str_starts_with($src, 'gw:') ? 'gateway' : 'ip-sla';
    }
}
