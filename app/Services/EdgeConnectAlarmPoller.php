<?php

namespace App\Services;

use App\Models\Circuit;
use App\Models\Device;
use App\Models\DeviceAlarm;
use App\Models\DeviceNextHop;

/**
 * Polls Silver Peak EdgeConnect active alarms over SNMP (SILVERPEAK-MGMT-MIB,
 * enterprise 23867) instead of SSH — the same data the appliance also emits as
 * traps, but reliably pollable. Alarms are reconciled into DeviceAlarm: active
 * ones are (re)opened, ones no longer present are cleared. A reachability guard
 * (sysUpTime) prevents a failed poll from false-clearing everything.
 *
 * Alarm table columns (23867.3.1.1.2.1.1.<col>.<index>):
 *   .2 severity   .4 name   .5 description   .6 source   .9 active(1)   .13 type-id
 *
 * The snmpwalk mechanics are injected as a callable so the parsing/reconcile is
 * deterministic and unit-testable (same pattern as HealthPoller).
 */
class EdgeConnectAlarmPoller
{
    private const OID_SYS_UPTIME = '.1.3.6.1.2.1.1.3.0'; // reachability guard
    private const OID_ALARM_SEVERITY = '.1.3.6.1.4.1.23867.3.1.1.2.1.1.2';
    private const OID_ALARM_NAME = '.1.3.6.1.4.1.23867.3.1.1.2.1.1.4';
    private const OID_ALARM_DESCR = '.1.3.6.1.4.1.23867.3.1.1.2.1.1.5';
    private const OID_ALARM_SOURCE = '.1.3.6.1.4.1.23867.3.1.1.2.1.1.6';
    private const OID_ALARM_ACTIVE = '.1.3.6.1.4.1.23867.3.1.1.2.1.1.9';
    private const OID_ALARM_TYPEID = '.1.3.6.1.4.1.23867.3.1.1.2.1.1.13';

    private const ALARM_PREFIX = 'ec:';

    /** @param callable(Device, string): string $walker Returns raw snmpwalk stdout for an OID. */
    public function __construct(private $walker)
    {
    }

    public function poll(Device $device): void
    {
        // Reachability guard: no sysUpTime reply means the poll failed — change
        // nothing (never false-clear alarms on an unreachable appliance).
        if (trim(($this->walker)($device, self::OID_SYS_UPTIME)) === '') {
            return;
        }

        $walk = fn (string $oid) => HealthPoller::parseWalk(($this->walker)($device, $oid));

        $severities = $walk(self::OID_ALARM_SEVERITY);
        $names = $walk(self::OID_ALARM_NAME);
        $descrs = $walk(self::OID_ALARM_DESCR);
        $sources = $walk(self::OID_ALARM_SOURCE);
        $active = $walk(self::OID_ALARM_ACTIVE);
        $typeIds = $walk(self::OID_ALARM_TYPEID);

        // Circuits paused for maintenance (monitoring_enabled = false) must not
        // alarm — including the appliance's own WAN alarms on that circuit's
        // uplink. A flapping LTE backup, for example, keeps re-raising wanN
        // link-down / IP-SLA / gateway alarms; pausing that circuit silences them.
        [$mutedWans, $mutedGwIps] = $this->mutedWanTargets($device);

        // A tunnel over a paused circuit alarms on BOTH ends, named differently:
        //  - on the HUB, for the REMOTE endpoint ("to_FL0092-HCF_Broadband1-LTE")
        //    → matched cross-site by the paused site's token + WAN (mutedTunnelMatchers).
        //  - on the PAUSED appliance ITSELF, for the far end it reaches over its own
        //    paused WAN ("to_FL0001-HQ-SEC_LTE-DIA2" on HCF, egressing HCF's LTE) →
        //    matched by this device's OWN paused-WAN label (the tunnel's local side).
        $tunnelMatchers = $this->mutedTunnelMatchers();
        $localPausedLabels = $this->localPausedWanLabels($device);

        $now = now();
        $seen = [];

        foreach ($names as $index => $name) {
            // Only track alarms flagged active.
            if ((int) trim($active[$index] ?? '1') !== 1) {
                continue;
            }

            $name = trim($name);
            $source = trim($sources[$index] ?? '');
            $typeId = trim($typeIds[$index] ?? '');

            // Ghost guard: a failed/empty SNMP row (no type-id and no source, or a
            // "No Such Instance/Object" placeholder) is not a real alarm — skip it
            // so it never becomes an "ec::" phantom.
            if (($typeId === '' && $source === '') || stripos($name, 'No Such') !== false) {
                continue;
            }

            // Belongs to a paused circuit's WAN (this appliance's own uplink) or a
            // tunnel to a paused REMOTE circuit? Suppress it — leaving it out of
            // $seen means any copy already open is cleared by the reconcile below,
            // so pausing silences the alarm within one poll.
            if ($this->belongsToMutedWan($name, $source, $mutedWans, $mutedGwIps)
                || $this->belongsToMutedTunnel($source, $tunnelMatchers, $localPausedLabels)) {
                continue;
            }

            $alarmId = self::ALARM_PREFIX.$typeId.':'.$source;

            // Human description: the appliance's descr (or the raw alarm name),
            // followed by the SOURCE — the specific tunnel/peer/interface — so the
            // NOC can tell WHICH tunnel or appliance is the problem instead of a
            // generic "Tunnel software version mismatch" with no target.
            $descrText = trim($descrs[$index] ?? '') ?: $name;
            $description = $source !== '' && strcasecmp($source, 'Tunnel') !== 0
                ? "{$descrText} — {$source}"
                : $descrText;

            $alarm = DeviceAlarm::firstOrNew(['device_id' => $device->id, 'alarm_id' => $alarmId]);
            $alarm->description = $description !== '' ? $description : 'EdgeConnect alarm';
            $alarm->severity = self::classifySeverity($severities[$index] ?? '', $name, $source);

            if (! $alarm->exists) {
                // Brand-new alarm.
                $alarm->first_seen_at = $now;
                $alarm->cleared_at = null;
            } elseif ($alarm->cleared_at !== null && ! $alarm->active_on_device) {
                // Was cleared and the appliance had stopped reporting it — this is
                // a genuine re-occurrence (a flap). Reopen as a fresh ticket.
                $alarm->ticket_number = DeviceAlarm::generateTicketNumber();
                $alarm->first_seen_at = $now;
                $alarm->cleared_at = null;
                $alarm->cleared_by = null;
                $alarm->clear_note = null;
                $alarm->cleared_manually = false;
                $alarm->acknowledged_at = null;
                $alarm->acknowledged_by = null;
                $alarm->ack_note = null;
            }
            // Otherwise: either still open (leave it), or manually cleared while
            // the appliance keeps reporting it — respect the NOC's clear and do
            // NOT resurrect it.

            $alarm->active_on_device = true;
            $alarm->save();

            $seen[] = $alarmId;
        }

        // Alarms the appliance did not report THIS poll. Scoped to the 'ec:'
        // prefix so SSH-sourced alarms are untouched.
        $notSeen = DeviceAlarm::where('device_id', $device->id)
            ->where('alarm_id', 'like', self::ALARM_PREFIX.'%')
            ->whereNotIn('alarm_id', $seen ?: ['__none__']);

        if ($seen !== []) {
            // The walk returned alarms — it definitively SUCCEEDED. Anything not in
            // it is genuinely gone, so clear it immediately (fast recovery when a
            // restored link clears some alarms while others remain).
            (clone $notSeen)->whereNull('cleared_at')->get()
                ->each(fn (DeviceAlarm $alarm) => $alarm->update([
                    'cleared_at' => $now,
                    'cleared_manually' => false,
                    'active_on_device' => false,
                ]));

            (clone $notSeen)->whereNotNull('cleared_at')->where('active_on_device', true)
                ->update(['active_on_device' => false]);

            return;
        }

        // The walk returned NO alarms — ambiguous: either the appliance is truly
        // clear, or this was a transient/partial SNMP walk (this gear drops
        // responses under high memory). Apply a ONE-POLL GRACE so a single empty
        // reading can't wipe every alarm and flap them (cleared one cycle, reopened
        // the next). Only a SECOND consecutive all-empty poll clears.
        (clone $notSeen)->whereNull('cleared_at')->where('active_on_device', false)->get()
            ->each(fn (DeviceAlarm $alarm) => $alarm->update([
                'cleared_at' => $now,
                'cleared_manually' => false,
            ]));

        (clone $notSeen)->where('active_on_device', true)
            ->update(['active_on_device' => false]);
    }

    /**
     * The WAN interfaces and gateway IPs of this appliance's PAUSED circuits —
     * the targets whose alarms must be suppressed. Returns [wanTokens, gwIps]
     * (both lowercased). Empty when nothing on the site is paused.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function mutedWanTargets(Device $device): array
    {
        if ($device->site_id === null) {
            return [[], []];
        }

        $wans = [];
        $gwIps = [];
        $paused = Circuit::where('site_id', $device->site_id)
            ->where('monitoring_enabled', false)
            ->whereNotNull('wan_interface')
            ->get(['wan_interface', 'gateway_ip']);
        foreach ($paused as $c) {
            $w = strtolower(trim((string) $c->wan_interface));
            if ($w !== '') {
                $wans[] = $w;
            }
            if ($c->gateway_ip) {
                $gwIps[] = strtolower(trim((string) $c->gateway_ip));
            }
        }
        // The gw:<ip> next-hop alarms name an IP, not an interface — resolve the
        // gateway IPs the paused WANs actually use so those alarms mute too.
        if ($wans !== []) {
            foreach (DeviceNextHop::where('device_id', $device->id)->get(['ip_address', 'interface']) as $nh) {
                if (in_array(strtolower((string) $nh->interface), $wans, true) && $nh->ip_address) {
                    $gwIps[] = strtolower(trim((string) $nh->ip_address));
                }
            }
        }

        return [array_values(array_unique($wans)), array_values(array_unique($gwIps))];
    }

    /**
     * Does this alarm belong to a paused WAN? Matches the wanN token as a whole
     * word (source "wan1", or "Port wan1 …" for an IP-SLA) or a paused gateway IP
     * anywhere in the alarm text (gw:<ip>). A wan0 alarm never matches a paused
     * wan1, so the live uplink keeps alarming.
     *
     * @param  list<string>  $mutedWans
     * @param  list<string>  $mutedGwIps
     */
    private function belongsToMutedWan(string $name, string $source, array $mutedWans, array $mutedGwIps): bool
    {
        if ($mutedWans === [] && $mutedGwIps === []) {
            return false;
        }
        $hay = strtolower($name.' '.$source);
        foreach ($mutedWans as $w) {
            if (preg_match('/\b'.preg_quote($w, '/').'\b/', $hay)) {
                return true;
            }
        }
        foreach ($mutedGwIps as $g) {
            if ($g !== '' && str_contains($hay, $g)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Cross-site tunnel-label mute matchers, one per paused circuit. A tunnel
     * alarm anywhere (usually the hub) is named for the REMOTE endpoint it lands
     * on — "to_<remote-appliance>_<localWan>-<remoteWan>". When that remote
     * circuit is paused, the tunnels riding it must mute too. Each matcher pairs
     * the remote site's appliance token (from its hostname) with a token
     * identifying the paused WAN (circuit_type / wan_interface), so only tunnels
     * on THAT uplink mute — a tunnel to the same site's primary keeps alarming.
     *
     * @return list<array{site: string, wans: list<string>}>
     */
    private function mutedTunnelMatchers(): array
    {
        $paused = Circuit::where('monitoring_enabled', false)
            ->whereNotNull('site_id')
            ->get(['site_id', 'wan_interface', 'circuit_type']);
        if ($paused->isEmpty()) {
            return [];
        }

        // The tunnel-label token(s) for each paused site = its edge appliance
        // hostname(s) with the "_SDW" suffix stripped (FL0092-HCF_SDW → fl0092-hcf).
        $tokensBySite = [];
        $edges = Device::whereIn('site_id', $paused->pluck('site_id')->unique()->all())
            ->where('role', 'edgeconnect')
            ->get(['site_id', 'name']);
        foreach ($edges as $edge) {
            $token = $this->siteLabelToken($edge->name);
            if ($token !== '') {
                $tokensBySite[$edge->site_id][] = $token;
            }
        }

        $matchers = [];
        foreach ($paused as $c) {
            $wans = array_values(array_filter(array_unique([
                strtolower(trim((string) $c->circuit_type)),
                strtolower(trim((string) $c->wan_interface)),
            ])));
            if ($wans === []) {
                continue;
            }
            foreach ($tokensBySite[$c->site_id] ?? [] as $siteToken) {
                $matchers[] = ['site' => $siteToken, 'wans' => $wans];
            }
        }

        return $matchers;
    }

    /** "FL0092-HCF_SDW" → "fl0092-hcf" — the token that appears in tunnel labels. */
    private function siteLabelToken(?string $deviceName): string
    {
        $n = strtolower(trim((string) $deviceName));

        return trim((string) preg_replace('/[_\- ]*sdw\d*$/', '', $n));
    }

    /**
     * The WAN labels of the polled appliance's OWN paused circuits — the tokens
     * that appear as a tunnel's LOCAL side ("…_LTE-DIA2") when that tunnel egresses
     * a paused uplink on this box. circuit_type + wan_interface, lowercased.
     *
     * @return list<string>
     */
    private function localPausedWanLabels(Device $device): array
    {
        if ($device->site_id === null) {
            return [];
        }
        $labels = [];
        foreach (Circuit::where('site_id', $device->site_id)->where('monitoring_enabled', false)
            ->get(['circuit_type', 'wan_interface']) as $c) {
            foreach ([$c->circuit_type, $c->wan_interface] as $v) {
                $v = strtolower(trim((string) $v));
                if ($v !== '') {
                    $labels[$v] = true;
                }
            }
        }

        return array_keys($labels);
    }

    /**
     * The LOCAL WAN label of a tunnel "to_<remoteHost>_<localWan>-<remoteWan>" —
     * the first token of the trailing "_"-segment. "to_FL0001-HQ-SEC_LTE-DIA2" → "lte".
     */
    private function tunnelLocalWan(string $source): string
    {
        if (! str_starts_with(strtolower($source), 'to_')) {
            return '';
        }
        $last = ltrim((string) strrchr($source, '_'), '_'); // "LTE-DIA2"
        $dash = strpos($last, '-');

        return strtolower($dash === false ? $last : substr($last, 0, $dash));
    }

    /**
     * Is this a tunnel alarm over a paused circuit — from EITHER end?
     *   - LOCAL: this appliance's own paused WAN egresses it (the tunnel's local-WAN
     *     label matches one of this box's paused circuits).
     *   - REMOTE: the tunnel lands on a paused REMOTE site's WAN (paused site's token
     *     AND its paused-WAN token both appear in the label).
     *
     * @param  list<array{site: string, wans: list<string>}>  $matchers
     * @param  list<string>  $localLabels
     */
    private function belongsToMutedTunnel(string $source, array $matchers, array $localLabels): bool
    {
        $hay = strtolower($source);
        if (! str_contains($hay, 'to_')) {
            return false;
        }
        // LOCAL end: the tunnel egresses THIS appliance's own paused WAN.
        if ($localLabels !== [] && in_array($this->tunnelLocalWan($source), $localLabels, true)) {
            return true;
        }
        // REMOTE end: the tunnel lands on a paused remote site's WAN.
        foreach ($matchers as $m) {
            if ($m['site'] === '' || ! str_contains($hay, $m['site'])) {
                continue;
            }
            foreach ($m['wans'] as $w) {
                if ($w !== '' && preg_match('/\b'.preg_quote($w, '/').'\b/', $hay)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Map the appliance alarm severity to a display severity. The
     * SILVERPEAK-MGMT-MIB severity column is a short token (CRI/MAJ/MIN/WARN/INFO).
     * When it is absent or unrecognised, fall back to the alarm name: a
     * service-affecting tunnels-down outage is critical, everything else warning.
     */
    private static function classifySeverity(string $rawSeverity, string $name, string $source): string
    {
        $token = strtoupper(trim($rawSeverity));

        if (preg_match('/^(CRI|CRITICAL|MAJ|MAJOR)/', $token)) {
            return 'critical';
        }
        if (preg_match('/^(MIN|MINOR|WARN|WARNING)/', $token)) {
            return 'warning';
        }
        if (str_starts_with($token, 'INFO')) {
            return 'info';
        }

        // Unknown/numeric severity column (Silver Peak reports a numeric code): infer
        // from the alarm name/source. These conditions are the service-affecting
        // ROOT CAUSES of a site outage, so they are critical — not a warning that
        // gets buried under the downstream tunnel alarm:
        //   - tunnels down (overlay outage)
        //   - a WAN interface link down (wan0/wan1 — the uplink itself)
        //   - the gateway / next-hop unreachable (WAN path is dead)
        $hay = strtolower($name.' '.$source);
        $wanLinkDown = str_contains($hay, 'link') && str_contains($hay, 'down') && preg_match('/\bwan\d/', $hay);
        $gatewayDown = str_contains($hay, 'gateway') || str_contains($hay, 'next-hop') || str_contains($hay, 'nexthop') || str_starts_with(strtolower(trim($source)), 'gw:');

        if (stripos($name.' '.$source, 'tunnel_down') !== false
            || (strcasecmp($source, 'Tunnel') === 0 && stripos($name, 'down') !== false)
            || $wanLinkDown
            || $gatewayDown) {
            return 'critical';
        }

        return 'warning';
    }
}
