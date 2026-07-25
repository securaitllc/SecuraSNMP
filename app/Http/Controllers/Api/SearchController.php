<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Circuit;
use App\Models\CircuitAlert;
use App\Models\Device;
use App\Models\DeviceAlarm;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Global quick-search across devices (name/IP), sites (name/address), circuits
 * (id/ISP), ISP tickets (circuit-alert ticket numbers) and alarm event ids
 * (the device alarm id, which is our internal ticket).
 */
class SearchController extends Controller
{
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

        // Alarm event ids — our internal ticket, routes to the raising device.
        foreach (DeviceAlarm::with('device')->where('alarm_id', 'like', $like)->latest('first_seen_at')->limit(6)->get() as $al) {
            $results[] = ['type' => 'alarm', 'label' => $al->alarm_id, 'sub' => 'Alarm · '.($al->device?->name ?? '').' · '.$al->description, 'route' => $al->device ? "/devices/{$al->device->id}" : '/'];
        }

        return response()->json($results);
    }
}
