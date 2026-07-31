<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Circuit;
use App\Models\CircuitAlert;
use App\Models\Device;
use App\Models\DeviceAlarm;
use App\Models\LldpNeighbor;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Global quick-search across devices (name/IP), sites (name/address), circuits
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
            ->where(fn ($w) => $w
                ->where('extension', 'like', $like)
                ->orWhere('remote_mgmt_addr', 'like', $like)
                ->orWhere('remote_sysname', 'like', $like));

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

    public function index(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q'));

        if (strlen($q) < 2) {
            return response()->json([]);
        }

        $like = '%'.$q.'%';
        $results = [];

        foreach (Device::where('name', 'like', $like)->orWhere('ip_address', 'like', $like)->limit(6)->get() as $d) {
            $results[] = ['type' => 'device', 'label' => $d->name, 'sub' => $d->ip_address, 'route' => "/devices/{$d->id}"];
        }

        // No per-circuit page exists, so land on the Circuits list pre-filtered to
        // this exact circuit via the search box (?q=), not the whole list.
        foreach (Circuit::where('circuit_id', 'like', $like)->orWhere('isp_name', 'like', $like)->limit(6)->get() as $c) {
            $results[] = ['type' => 'circuit', 'label' => $c->circuit_id, 'sub' => $c->isp_name, 'route' => '/circuits?q='.urlencode($c->circuit_id)];
        }

        // Sites: land on the Sites list pre-filtered + the matching row auto-expanded.
        foreach (Site::where('name', 'like', $like)->orWhere('address', 'like', $like)->limit(6)->get() as $s) {
            $results[] = ['type' => 'site', 'label' => $s->name, 'sub' => $s->address, 'route' => '/sites?q='.urlencode($s->name)];
        }

        // ISP tickets — the ticket number recorded on a circuit outage; jump to that circuit.
        foreach (CircuitAlert::with('circuit')->where('ticket_number', 'like', $like)->latest('started_at')->limit(6)->get() as $a) {
            $circuitId = $a->circuit?->circuit_id;
            $results[] = ['type' => 'ticket', 'label' => $a->ticket_number, 'sub' => 'ISP ticket · '.($circuitId ?? ''), 'route' => $circuitId ? '/circuits?q='.urlencode($circuitId) : '/circuits'];
        }

        // Alarms by event id OR by ticket number. The ticket is what an operator
        // actually quotes on a bridge call, and searching only alarm_id meant an
        // alarm could not be found by the number printed in front of them.
        $alarms = DeviceAlarm::with('device')
            ->where(fn ($q) => $q->where('alarm_id', 'like', $like)
                ->orWhere('ticket_number', 'like', $like))
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

        $results = array_merge($results, $this->endpointMatches($q, $like));

        return response()->json($results);
    }
}
