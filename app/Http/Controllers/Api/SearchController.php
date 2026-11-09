<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Circuit;
use App\Models\CircuitAlert;
use App\Models\Device;
use App\Models\DeviceAlarm;
use App\Models\DeviceHardwareChange;
use App\Models\DeviceMember;
use App\Models\LldpNeighbor;
use App\Models\MacAddress;
use App\Models\Site;
use App\Support\Sql;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Global quick-search across devices (name/IP/serial, incl. former serials), sites (name/address), circuits
 * (id/ISP), ISP tickets (circuit-alert ticket numbers), alarm event ids (the device
 * alarm id, which is our internal ticket) and LLDP endpoints (MAC, extension, or
 * endpoint IP).
 */
class SearchController extends Controller
{
    /**
     * LLDP endpoints by MAC, extension, or advertised address.
     *
     * A MAC-learning log or a phone bill names a MAC or an extension and nothing
     * else, so those are the strings an operator has in hand — and neither could be
     * searched at all. Punctuation is stripped from both sides of the MAC comparison
     * so 02:00:5E:05:15:32, 02005e051532 and a trailing fragment all match.
     *
     * @return list<array<string, string>>
     */
    private function endpointMatches(string $q, string $like): array
    {
        $hex = strtoupper(preg_replace('/[^0-9a-f]/i', '', $q));

        $query = LldpNeighbor::with('device')
            ->where(fn ($w) => Sql::whereLikeAny($w, ['extension', 'remote_mgmt_addr', 'remote_sysname'], $like));

        // Only treat the term as a MAC fragment when it is plausibly one: two octets
        // of hex. Fewer would match half the fleet.
        if (strlen($hex) >= 4 && ctype_xdigit($hex)) {
            $query->orWhereRaw(
                "REPLACE(REPLACE(UPPER(remote_mac), ':', ''), '-', '') LIKE ?",
                ['%'.$hex.'%'],
            );
        }

        $results = [];
        foreach ($query->orderByDesc('last_seen_at')->limit(6)->get() as $n) {
            $where = trim(($n->device?->name ?? 'unknown switch').' · '.($n->local_port ?? ''));
            $what = $n->endpoint_model ?? $n->remote_sysname ?? 'Endpoint';

            $results[] = [
                'type' => 'endpoint',
                'label' => $n->remote_mac ?: ($n->extension ? "ext {$n->extension}" : $what),
                'sub' => trim($what.($n->extension ? " · ext {$n->extension}" : '')
                    .' · '.$where
                    .($n->absent_since ? ' · disconnected' : '')),
                'route' => $n->device_id ? "/devices/{$n->device_id}" : '/devices',
            ];
        }

        return $results;
    }

    /**
     * SNMP-learned (FDB) endpoints by MAC or OUI vendor. LLDP only knows the handful
     * of neighbours a switch advertises; the FDB table is every MAC the fleet has seen,
     * so this is what actually answers "where is AC:23:16:10:00:E0" or "find the Verkada
     * cameras". Lands on the MAC Search page pre-filtered.
     *
     * @return list<array<string, string>>
     */
    private function fdbMatches(string $q, string $like): array
    {
        $hex = strtoupper(preg_replace('/[^0-9a-f]/i', '', $q));
        // A vendor word like "verkada" is all hex letters (e,a,d,a…), so classify-then-
        // branch would mistake it for a MAC. Search BOTH: OUI vendor always, and the MAC
        // whenever the term is a plausible hex fragment.
        $isMac = strlen($hex) >= 4 && ctype_xdigit($hex);

        $query = MacAddress::with(['device:id,name', 'deviceInterface:id,if_name'])
            ->where(function ($w) use ($like, $isMac, $hex) {
                Sql::whereLike($w, 'oui_vendor', $like);
                if ($isMac) {
                    $w->orWhereRaw("REPLACE(REPLACE(UPPER(mac), ':', ''), '-', '') LIKE ?", ['%'.$hex.'%']);
                }
            });

        $results = [];
        foreach ($query->orderByDesc('last_seen_at')->limit(6)->get() as $m) {
            $where = trim(($m->device?->name ?? 'switch').' · '.($m->deviceInterface?->if_name ?? ''));
            $results[] = [
                'type' => 'endpoint',
                'label' => $m->mac,
                'sub' => trim(($m->oui_vendor ?? 'MAC').' · '.$where.($m->vlan ? " · vlan {$m->vlan}" : '')),
                'route' => '/mac-search?q='.urlencode($m->mac),
            ];
        }

        return $results;
    }

    /**
     * Device sub line: IP plus the site, plus the serial when that is what matched —
     * the operator typed a serial and needs to see which one (current or the one it
     * replaced) answered.
     */
    private function deviceSub(Device $d, string $q): string
    {
        $bits = array_filter([$d->ip_address, $d->site?->name]);
        $needle = strtolower($q);

        if ($d->serial_number && str_contains(strtolower($d->serial_number), $needle)) {
            $bits[] = "serial {$d->serial_number}";
        }
        elseif ($d->previous_serial_number && str_contains(strtolower($d->previous_serial_number), $needle)) {
            $bits[] = "was serial {$d->previous_serial_number}";
        }

        return implode(' · ', $bits);
    }

    /**
     * Serials a device USED to have, plus Virtual Chassis member serials.
     *
     * `devices.previous_serial_number` keeps one hop. A unit swapped twice, or swapped
     * before that column existed, is only in `device_hardware_changes` — the append-
     * only trail — so an old serial off a returned box is answered from there: which
     * device, which site, when it was replaced and by what. Devices already listed
     * by the direct match are skipped so a swap does not show twice.
     *
     * @param  list<int>  $skipDeviceIds
     * @return list<array<string, mixed>>
     */
    private function serialHistoryMatches(string $q, string $like, array $skipDeviceIds): array
    {
        $results = [];

        $changes = DeviceHardwareChange::with(['device:id,name,site_id', 'device.site:id,name'])
            ->where('field', 'serial_number')
            ->where(fn ($w) => Sql::whereLikeAny($w, ['old_value', 'new_value'], $like))
            ->whereNotIn('device_id', $skipDeviceIds)
            ->orderByDesc('detected_at')
            ->limit(6)
            ->get()
            ->unique('device_id');

        foreach ($changes as $c) {
            if (! $c->device) {
                continue;
            }
            $hitOld = $c->old_value && str_contains(strtolower($c->old_value), strtolower($q));
            $results[] = [
                'type' => 'device',
                'id' => $c->device_id,
                'label' => $c->device->name,
                'sub' => implode(' · ', array_filter([
                    $c->device->site?->name,
                    $hitOld ? "was serial {$c->old_value}" : "serial {$c->new_value}",
                    'replaced '.$c->detected_at?->toDateString().($hitOld && $c->new_value ? " by {$c->new_value}" : ''),
                ])),
                'route' => "/devices/{$c->device_id}",
            ];
        }

        $members = DeviceMember::with(['device:id,name,site_id', 'device.site:id,name'])
            ->where(fn ($w) => Sql::whereLike($w, 'serial_number', $like))
            ->whereNotIn('device_id', $skipDeviceIds)
            ->limit(6)
            ->get();

        foreach ($members as $m) {
            if (! $m->device) {
                continue;
            }
            $results[] = [
                'type' => 'device',
                'id' => $m->device_id,
                'label' => $m->device->name,
                'sub' => implode(' · ', array_filter([$m->device->site?->name, "member serial {$m->serial_number}"])),
                'route' => "/devices/{$m->device_id}",
            ];
        }

        return $results;
    }

    public function index(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q'));

        if (strlen($q) < 2) {
            return response()->json([]);
        }

        // INJ-01: % / _ matched literally — `%%` was a full scan of every table below.
        $like = '%'.Sql::escapeLike($q).'%';
        $results = [];

        // Devices by name, IP or serial. The serial is what an RMA form, a shipping
        // label or an ISP escalation quotes, and until v0.11.2 it was not searchable:
        // an operator holding a serial could not find out which site the box was at.
        // The sub line carries the site for exactly that reason.
        $devices = Sql::whereLikeAny(Device::with('site:id,name'), ['name', 'ip_address', 'serial_number', 'previous_serial_number'], $like)->limit(6)->get();
        foreach ($devices as $d) {
            $results[] = ['type' => 'device', 'id' => $d->id, 'label' => $d->name, 'sub' => $this->deviceSub($d, $q), 'route' => "/devices/{$d->id}"];
        }

        $results = array_merge($results, $this->serialHistoryMatches($q, $like, $devices->pluck('id')->all()));

        // No per-circuit page exists, so land on the Circuits list pre-filtered to
        // this exact circuit via the search box (?q=), not the whole list.
        foreach (Sql::whereLikeAny(Circuit::query(), ['circuit_id', 'isp_name'], $like)->limit(6)->get() as $c) {
            $results[] = ['type' => 'circuit', 'id' => $c->id, 'label' => $c->circuit_id, 'sub' => $c->isp_name, 'route' => '/circuits?q='.urlencode($c->circuit_id)];
        }

        // Sites: land on the Sites list pre-filtered + the matching row auto-expanded.
        foreach (Sql::whereLikeAny(Site::query(), ['name', 'address'], $like)->limit(6)->get() as $s) {
            $results[] = ['type' => 'site', 'id' => $s->id, 'label' => $s->name, 'sub' => $s->address, 'route' => '/sites?q='.urlencode($s->name)];
        }

        // ISP tickets — the ticket number recorded on a circuit outage; jump to that circuit.
        foreach (Sql::whereLike(CircuitAlert::with('circuit'), 'ticket_number', $like)->latest('started_at')->limit(6)->get() as $a) {
            $circuitId = $a->circuit?->circuit_id;
            $results[] = ['type' => 'ticket', 'label' => $a->ticket_number, 'sub' => 'ISP ticket · '.($circuitId ?? ''), 'route' => $circuitId ? '/circuits?q='.urlencode($circuitId) : '/circuits'];
        }

        // Alarms by event id OR by ticket number. The ticket is what an operator
        // actually quotes on a bridge call, and searching only alarm_id meant an
        // alarm could not be found by the number printed in front of them.
        $alarms = DeviceAlarm::with('device')
            ->where(fn ($q) => Sql::whereLikeAny($q, ['alarm_id', 'ticket_number'], $like))
            ->latest('first_seen_at')
            ->limit(6)
            ->get();

        foreach ($alarms as $al) {
            $label = $al->ticket_number ?: $al->alarm_id;
            $results[] = [
                'type' => 'alarm',
                'label' => $label,
                'sub' => 'Alarm · '.($al->device?->name ?? '').' · '.$al->description,
                'route' => $al->device ? "/devices/{$al->device->id}" : '/',
            ];
        }

        $results = array_merge($results, $this->endpointMatches($q, $like), $this->fdbMatches($q, $like));

        return response()->json($results);
    }
}
