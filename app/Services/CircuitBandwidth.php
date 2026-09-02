<?php

namespace App\Services;

use App\Models\Circuit;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\DeviceNextHop;
use App\Models\InterfaceMetricHistory;
use Illuminate\Support\Collection;

/**
 * How much of a circuit's contracted bandwidth is actually being used.
 *
 * A circuit is monitored by ICMP, which only ever yields loss and latency — there
 * has never been a throughput number for one. But every circuit records the
 * EdgeConnect WAN port it lands on (wan0/wan1), and that interface is polled over
 * SNMP, so the traffic is already being collected; it just was not attributed back
 * to the circuit.
 *
 * TWO THINGS THIS DELIBERATELY DOES NOT DO:
 *
 * 1. It does not use `speed_bps` as the denominator. The EdgeConnect WAN port is
 *    physically 1 Gbps (and reports 40 Gbps on some units — a bogus negotiated
 *    value) while the circuit behind it is sold at 20 or 100 Mbps. A 20 Mbps
 *    circuit running at 18 Mbps is 90% consumed; measured against the port it
 *    reads 1.8% and looks idle. The contract is the only denominator that answers
 *    "are we running out of circuit?".
 *
 * 2. It does not use the interface's stored `in_util_pct` either, for the same
 *    reason — that figure is a share of `speed_bps`, so it inherits the bad speed.
 *    Throughput is recomputed from the raw octet deltas and the real elapsed time
 *    between polls, which depends on nothing but the counters themselves.
 *
 * Download and upload are kept separate throughout because they exhaust
 * separately: a 100/10 circuit sitting at 62% down and 94% up is in trouble, and
 * any single averaged number hides exactly that.
 */
class CircuitBandwidth
{
    /** Fallback when only one sample exists (POLL_INTERFACE_SECONDS default). */
    private const ASSUMED_POLL_SECONDS = 300;

    /** Samples older than this are stale — a dead poller must not read as idle. */
    private const FRESH_MINUTES = 20;

    /**
     * Per-circuit bandwidth for a whole list, in a fixed number of queries.
     *
     * The circuits page loads 250 rows; doing this per circuit would be 250×3
     * queries. Everything is batched and joined in PHP instead.
     *
     * @param  Collection<int,Circuit>  $circuits
     * @return array<int,array> keyed by circuit id
     */
    public function forMany(Collection $circuits): array
    {
        $siteIds = $circuits->pluck('site_id')->filter()->unique()->all();
        if ($siteIds === []) {
            return [];
        }

        // The WAN-facing appliance at each site. A site can hold more than one; the
        // circuit's wan_interface names a port on whichever one has it.
        $edges = Device::query()
            ->whereIn('site_id', $siteIds)
            ->whereIn('role', ['edgeconnect', 'firewall'])
            ->get(['id', 'site_id']);

        $edgeIdsBySite = $edges->groupBy('site_id')->map(fn ($g) => $g->pluck('id')->all())->all();

        $interfaces = DeviceInterface::query()
            ->whereIn('device_id', $edges->pluck('id'))
            ->get(['id', 'device_id', 'if_name', 'if_canonical_name']);

        $ifaceByDeviceName = self::nameIndex($interfaces);

        $samples = $this->latestSamples($interfaces->pluck('id')->all());

        // Fallback mapping: the appliance's own next-hop table already records which
        // port each WAN gateway is reached through, so a circuit that never had its
        // wan_interface filled in can still be resolved from its gateway IP.
        $hops = DeviceNextHop::query()
            ->whereIn('device_id', $edges->pluck('id'))
            ->whereNotNull('interface')
            ->get(['device_id', 'ip_address', 'interface']);

        $portByDeviceGateway = [];
        foreach ($hops as $hop) {
            $portByDeviceGateway[$hop->device_id][strtolower(trim((string) $hop->ip_address))]
                = strtolower(trim((string) $hop->interface));
        }

        $out = [];
        foreach ($circuits as $circuit) {
            $out[$circuit->id] = $this->build($circuit, $edgeIdsBySite, $ifaceByDeviceName, $samples, $portByDeviceGateway);
        }

        return $out;
    }

    /**
     * Every WAN-facing interface on the fleet, resolved the same way a circuit's
     * bandwidth is: the port named on the circuit, else the port the appliance's own
     * next-hop table says that gateway is reached through.
     *
     * This exists so a fleet-wide throughput figure can be WAN-ONLY. Summing every
     * polled interface counts LAN ports, trunks and uplinks too, so a single packet
     * crossing an access port and then an uplink is counted twice — a number that
     * moves with the fleet but means nothing in bytes.
     *
     * @return list<int> device_interface ids
     */
    public function wanInterfaceIds(): array
    {
        $circuits = Circuit::query()
            ->whereNotNull('site_id')
            ->get(['id', 'site_id', 'wan_interface', 'gateway_ip']);
        if ($circuits->isEmpty()) {
            return [];
        }

        $edges = Device::query()
            ->whereIn('site_id', $circuits->pluck('site_id')->unique()->all())
            ->whereIn('role', ['edgeconnect', 'firewall'])
            ->get(['id', 'site_id']);
        $edgeIdsBySite = $edges->groupBy('site_id')->map(fn ($g) => $g->pluck('id')->all())->all();

        $ifaceByDeviceName = self::nameIndex(
            DeviceInterface::whereIn('device_id', $edges->pluck('id'))->get(['id', 'device_id', 'if_name', 'if_canonical_name'])
        );

        $portByDeviceGateway = [];
        foreach (DeviceNextHop::whereIn('device_id', $edges->pluck('id'))->whereNotNull('interface')->get(['device_id', 'ip_address', 'interface']) as $hop) {
            $portByDeviceGateway[$hop->device_id][strtolower(trim((string) $hop->ip_address))]
                = strtolower(trim((string) $hop->interface));
        }

        $ids = [];
        foreach ($circuits as $circuit) {
            $edgeIds = $edgeIdsBySite[$circuit->site_id] ?? [];
            $wan = strtolower(trim((string) $circuit->wan_interface));

            if ($wan === '') {
                $gw = strtolower(trim((string) ($circuit->gateway_ip ?: '')));
                foreach ($edgeIds as $deviceId) {
                    if ($gw !== '' && isset($portByDeviceGateway[$deviceId][$gw])) {
                        $wan = $portByDeviceGateway[$deviceId][$gw];
                        break;
                    }
                }
            }
            if ($wan === '') {
                continue;
            }

            foreach ($edgeIds as $deviceId) {
                if (isset($ifaceByDeviceName[$deviceId][$wan])) {
                    $ids[$ifaceByDeviceName[$deviceId][$wan]] = true;
                    break;
                }
            }
        }

        return array_map('intval', array_keys($ids));
    }

    /**
     * (device_id, lowercased name) => interface id, under BOTH names a port answers to.
     *
     * `if_name` is what the device calls the port in ifDescr, which on EdgeConnect is
     * a human label ("BB," on the wan0 that carries a broadband circuit), while
     * `if_canonical_name` is the port's own ifName. A circuit records the port name an
     * engineer would say out loud — wan0 — so a lookup that only knew the label
     * missed every EdgeConnect port that had one.
     *
     * @param  Collection<int,DeviceInterface>  $interfaces
     * @return array<int,array<string,int>>
     */
    private static function nameIndex(Collection $interfaces): array
    {
        $index = [];

        // Labels first, real port names second: a port LABELLED "wan0" must never
        // shadow the port actually NAMED wan0, whichever order they were polled in.
        foreach (['if_name', 'if_canonical_name'] as $column) {
            foreach ($interfaces as $if) {
                $key = strtolower(trim((string) $if->{$column}));
                if ($key !== '') {
                    $index[$if->device_id][$key] = $if->id;
                }
            }
        }

        return $index;
    }

    /**
     * The interface a circuit's WAN port names, by either of its names.
     *
     * Shared by the history chart and the live counter probe so all three readings
     * can never disagree about which port they describe.
     */
    public function resolveInterface(Circuit $circuit, string $wan): ?DeviceInterface
    {
        $wan = strtolower(trim($wan));
        if ($wan === '') {
            return null;
        }

        $edgeIds = Device::where('site_id', $circuit->site_id)
            ->whereIn('role', ['edgeconnect', 'firewall'])->pluck('id');

        return DeviceInterface::whereIn('device_id', $edgeIds)
            ->where(fn ($q) => $q->whereRaw('LOWER(if_name) = ?', [$wan])
                ->orWhereRaw('LOWER(if_canonical_name) = ?', [$wan]))
            // A canonical match is the real port; a label match is a fallback.
            ->orderByRaw('LOWER(if_canonical_name) = ? desc', [$wan])
            ->first();
    }

    /** Bandwidth for a single circuit. */
    public function for(Circuit $circuit): array
    {
        return $this->forMany(collect([$circuit]))[$circuit->id];
    }

    /**
     * Throughput over time for one circuit, for the history chart.
     *
     * Each sample's bits/sec comes from its own octet delta divided by the real gap
     * since the previous sample — so an interval that drifted (a slow sweep, a
     * skipped poll) is measured as it actually happened rather than assumed.
     *
     * @return array{points:array<int,array{t:string,down_mbps:float,up_mbps:float}>,peak_down:?float,peak_up:?float,contract_down_mbps:?int,contract_up_mbps:?int,mapped:bool,reason:?string}
     */
    public function history(Circuit $circuit, int $hours = 24): array
    {
        $meta = $this->for($circuit);
        $empty = [
            'points' => [], 'peak_down' => null, 'peak_up' => null,
            'contract_down_mbps' => $circuit->contract_down_mbps,
            'contract_up_mbps' => $circuit->contract_up_mbps,
            'mapped' => false, 'reason' => $meta['reason'],
        ];

        if (! $meta['mapped']) {
            return $empty;
        }

        // Resolve the same port the live reading used, so chart and row can never
        // disagree about which interface they are describing.
        $iface = $this->resolveInterface($circuit, $meta['wan_interface']);

        if (! $iface) {
            return $empty;
        }

        $rows = InterfaceMetricHistory::where('device_interface_id', $iface->id)
            ->where('recorded_at', '>=', now()->subHours($hours))
            ->orderBy('recorded_at')
            ->get(['recorded_at', 'in_octets_delta', 'out_octets_delta']);

        $points = [];
        $peakDown = 0.0;
        $peakUp = 0.0;
        $prev = null;

        foreach ($rows as $row) {
            // The first sample has no measurable window; skip rather than invent one.
            if ($prev !== null) {
                $seconds = max(1, $prev->recorded_at->diffInSeconds($row->recorded_at));
                $down = round(((int) $row->in_octets_delta * 8) / $seconds / 1_000_000, 2);
                $up = round(((int) $row->out_octets_delta * 8) / $seconds / 1_000_000, 2);
                $points[] = ['t' => $row->recorded_at->toIso8601String(), 'down_mbps' => $down, 'up_mbps' => $up];
                $peakDown = max($peakDown, $down);
                $peakUp = max($peakUp, $up);
            }
            $prev = $row;
        }

        return [
            'points' => $points,
            'peak_down' => $points ? $peakDown : null,
            'peak_up' => $points ? $peakUp : null,
            'contract_down_mbps' => $circuit->contract_down_mbps,
            'contract_up_mbps' => $circuit->contract_up_mbps,
            'mapped' => true,
            'reason' => null,
        ];
    }

    /**
     * The last two samples per interface — a delta plus the gap it was measured
     * over. Two rows are needed because the delta alone has no time base: the
     * elapsed seconds come from the previous sample's timestamp.
     *
     * @param  array<int>  $interfaceIds
     * @return array<int,array{0:InterfaceMetricHistory,1:?InterfaceMetricHistory}>
     */
    private function latestSamples(array $interfaceIds): array
    {
        if ($interfaceIds === []) {
            return [];
        }

        // One windowed read rather than a query per interface. The window is wide
        // enough to hold two polls even if one was skipped.
        $rows = InterfaceMetricHistory::query()
            ->whereIn('device_interface_id', $interfaceIds)
            ->where('recorded_at', '>=', now()->subMinutes(self::FRESH_MINUTES + 15))
            ->orderBy('device_interface_id')
            ->orderByDesc('recorded_at')
            ->get(['device_interface_id', 'recorded_at', 'in_octets_delta', 'out_octets_delta']);

        $byIface = [];
        foreach ($rows->groupBy('device_interface_id') as $ifaceId => $group) {
            $byIface[(int) $ifaceId] = [$group[0], $group[1] ?? null];
        }

        return $byIface;
    }

    private function build(Circuit $circuit, array $edgeIdsBySite, array $ifaceByDeviceName, array $samples, array $portByDeviceGateway = []): array
    {
        $blank = [
            'mapped' => false,
            'down_mbps' => null, 'up_mbps' => null,
            'down_pct' => null, 'up_pct' => null,
            'contract_down_mbps' => $circuit->contract_down_mbps,
            'contract_up_mbps' => $circuit->contract_up_mbps,
            'measured_at' => null,
            'reason' => null,
        ];

        $edgeIds = $edgeIdsBySite[$circuit->site_id] ?? [];
        $wan = strtolower(trim((string) $circuit->wan_interface));
        $inferred = false;

        // Not recorded on the circuit? Ask the appliance. Its next-hop table maps each
        // WAN gateway to the port it is reached through, which is the same fact the
        // wan_interface field holds — just collected automatically instead of typed.
        if ($wan === '') {
            $gw = strtolower(trim((string) ($circuit->gateway_ip ?: '')));
            if ($gw !== '') {
                foreach ($edgeIds as $deviceId) {
                    if (isset($portByDeviceGateway[$deviceId][$gw])) {
                        $wan = $portByDeviceGateway[$deviceId][$gw];
                        $inferred = true;
                        break;
                    }
                }
            }
        }

        if ($wan === '') {
            return array_merge($blank, ['reason' => 'no WAN port mapped']);
        }
        if (! $circuit->contract_down_mbps && ! $circuit->contract_up_mbps) {
            return array_merge($blank, ['reason' => 'no contract speed on file']);
        }

        $ifaceId = null;
        foreach ($edgeIds as $deviceId) {
            if (isset($ifaceByDeviceName[$deviceId][$wan])) {
                $ifaceId = $ifaceByDeviceName[$deviceId][$wan];
                break;
            }
        }
        if ($ifaceId === null) {
            // Say what the appliance DOES have. "port wan0 not found" is a dead end;
            // the names it answers to are the whole fix.
            $known = [];
            foreach ($edgeIds as $deviceId) {
                $known = array_merge($known, array_keys($ifaceByDeviceName[$deviceId] ?? []));
            }
            sort($known);
            $hint = $known === [] ? 'no ports are polled on it' : 'it has '.implode(', ', array_slice($known, 0, 12));

            return array_merge($blank, ['reason' => "port {$wan} not found on the appliance — {$hint}"]);
        }

        [$latest, $previous] = $samples[$ifaceId] ?? [null, null];
        if ($latest === null) {
            return array_merge($blank, ['reason' => 'no recent traffic samples']);
        }
        if ($latest->recorded_at->lt(now()->subMinutes(self::FRESH_MINUTES))) {
            return array_merge($blank, ['reason' => 'traffic data is stale']);
        }

        // Elapsed time the counters accumulated over. Falling back to the nominal
        // poll interval keeps a first-ever sample usable rather than showing nothing.
        $seconds = $previous
            ? max(1, $previous->recorded_at->diffInSeconds($latest->recorded_at))
            : self::ASSUMED_POLL_SECONDS;

        // Counters are per-interface: traffic INTO the WAN port is the circuit's
        // download, traffic OUT of it is the upload.
        $downMbps = round(((int) $latest->in_octets_delta * 8) / $seconds / 1_000_000, 2);
        $upMbps = round(((int) $latest->out_octets_delta * 8) / $seconds / 1_000_000, 2);

        return [
            'mapped' => true,
            'wan_interface' => $wan,
            // Inferred from the appliance rather than recorded on the circuit — worth
            // showing, so an operator knows the mapping was derived, not entered.
            'inferred' => $inferred,
            'down_mbps' => $downMbps,
            'up_mbps' => $upMbps,
            'down_pct' => self::pct($downMbps, $circuit->contract_down_mbps),
            'up_pct' => self::pct($upMbps, $circuit->contract_up_mbps),
            'contract_down_mbps' => $circuit->contract_down_mbps,
            'contract_up_mbps' => $circuit->contract_up_mbps,
            'measured_at' => $latest->recorded_at,
            'reason' => null,
        ];
    }

    /**
     * Share of contract. Deliberately NOT capped at 100: a circuit measuring over
     * its contract is real and worth seeing (burst allowance, or a contract figure
     * that is simply wrong), and clamping it would hide that.
     */
    private static function pct(float $mbps, ?int $contractMbps): ?float
    {
        if (! $contractMbps) {
            return null;
        }

        return round($mbps / $contractMbps * 100, 1);
    }
}
