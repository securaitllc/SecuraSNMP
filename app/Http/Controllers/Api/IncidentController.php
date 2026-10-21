<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceAlarm;
use App\Models\Site;
use App\Services\DependencyResolver;
use App\Services\TunnelCorrelation;
use Illuminate\Http\JsonResponse;

/**
 * Dependency-aware root-cause incidents: an upstream failure rolled up with the
 * downstream cascade it caused, so the NOC sees one incident affecting N devices
 * instead of N separate alerts. `suppressed_alarm_ids` lets the alarm list hide the
 * symptoms under their root.
 */
class IncidentController extends Controller
{
    public function index(DependencyResolver $resolver, TunnelCorrelation $tunnels): JsonResponse
    {
        // Only analyse sites that actually have a device down right now.
        $siteIds = DeviceAlarm::query()
            ->where('device_alarms.alarm_id', 'device-unreachable')->whereNull('cleared_at')
            ->join('devices', 'devices.id', '=', 'device_alarms.device_id')
            ->distinct()->pluck('devices.site_id')->filter()->values();

        $siteNames = Site::whereIn('id', $siteIds)->pluck('name', 'id');

        $incidents = [];
        foreach ($siteIds as $siteId) {
            foreach ($resolver->forSite((int) $siteId) as $incident) {
                $incident['kind'] = 'device';
                $incident['site_name'] = $siteNames[$siteId] ?? null;
                $incidents[] = $incident;
            }
        }

        // Cross-site SD-WAN tunnel symptoms rolled up to the failing end (spoke OR hub).
        $tunnel = $tunnels->analyze();
        foreach ($tunnel['incidents'] as $incident) {
            $incidents[] = $incident;
        }

        // Worst blast radius first.
        usort($incidents, fn ($a, $b) => $b['affected_total'] <=> $a['affected_total']);

        $suppressed = array_merge(
            array_merge(...array_map(fn ($i) => $i['suppressed_alarm_ids'] ?? [], $incidents) ?: [[]]),
            $tunnel['suppressed_alarm_ids'],
        );

        return response()->json([
            'data' => $incidents,
            // Flat list so the alarm view can hide every suppressed symptom in one pass.
            'suppressed_alarm_ids' => array_values(array_unique($suppressed)),
        ]);
    }
}
