<?php

namespace App\Services;

use App\Models\Device;
use App\Models\DeviceAlarm;
use App\Models\LldpNeighbor;
use Illuminate\Support\Collection;

/**
 * Dependency-aware monitoring. When an upstream device fails, everything that reaches
 * the internet THROUGH it goes dark too — a core switch dropping can spew 100+ "device
 * unreachable" alerts. This walks the LLDP dependency tree (edge → core → access →
 * endpoints), finds the TOP-MOST failed device (the root cause), and rolls the rest up:
 *
 *   Root cause: Core-SW-01 unreachable
 *   Suppressed: 43 downstream switches · 127 endpoints · 18 APs · 6 printers
 *
 * The NOC sees ONE incident affecting N devices, not N incidents. Everything is
 * computed live from current state (active `device-unreachable` alarms + the LLDP
 * tree), so it re-resolves as devices recover — nothing is persisted to go stale.
 */
class DependencyResolver
{
    private const UNREACHABLE = 'device-unreachable';

    /** LLDP neighbor_type → the human bucket shown in the "affected" breakdown. */
    private const ENDPOINT_BUCKET = ['ap' => 'aps', 'phone' => 'phones', 'camera' => 'cameras', 'router' => 'routers', 'other' => 'endpoints'];

    /**
     * Root-cause incidents for one site (empty when nothing is down or there is no
     * SD-WAN root to anchor the tree).
     *
     * @return list<array<string,mixed>>
     */
    public function forSite(int $siteId): array
    {
        $devices = Device::where('site_id', $siteId)->get(['id', 'name', 'role']);
        if ($devices->count() < 2) {
            return [];
        }

        $ids = $devices->pluck('id');

        // Active "device unreachable" alarms → the down set, keyed by device.
        $downAlarms = DeviceAlarm::whereIn('device_id', $ids)
            ->where('alarm_id', self::UNREACHABLE)->whereNull('cleared_at')
            ->get(['id', 'device_id', 'ticket_number', 'first_seen_at'])->keyBy('device_id');
        if ($downAlarms->isEmpty()) {
            return [];
        }

        // Resolved device↔device adjacency from LLDP (both directions).
        $adj = [];
        LldpNeighbor::whereIn('device_id', $ids)->whereNotNull('remote_device_id')
            ->whereNull('absent_since')->get(['device_id', 'remote_device_id'])
            ->each(function ($n) use (&$adj) {
                $adj[$n->device_id][$n->remote_device_id] = true;
                $adj[$n->remote_device_id][$n->device_id] = true;
            });

        // Live endpoints (LLDP neighbors that are NOT managed devices) per switch, bucketed.
        $endpoints = [];
        LldpNeighbor::whereIn('device_id', $ids)->whereNull('remote_device_id')
            ->whereNull('absent_since')->get(['device_id', 'neighbor_type'])
            ->each(function ($n) use (&$endpoints) {
                $bucket = self::ENDPOINT_BUCKET[$n->neighbor_type] ?? 'endpoints';
                $endpoints[$n->device_id][$bucket] = ($endpoints[$n->device_id][$bucket] ?? 0) + 1;
            });

        // Roots of the tree = the SD-WAN edge appliance(s); the whole site hangs off them.
        $roots = $devices->where('role', 'edgeconnect')->pluck('id')->all();
        if ($roots === []) {
            return [];
        }

        return $this->analyze($devices->keyBy('id'), $adj, $roots, $downAlarms, $endpoints, $siteId);
    }

    /**
     * Pure core: BFS the tree from the roots to get a parent for every device, then for
     * each failed device with a HEALTHY parent (a genuine root cause) sum its downstream
     * subtree — suppressed child alarms + affected endpoints by bucket.
     *
     * @param  Collection<int,Device>  $devices  keyed by id
     * @param  array<int,array<int,bool>>  $adj  deviceId => set of neighbor ids
     * @param  list<int>  $roots  edge device ids
     * @param  Collection<int,DeviceAlarm>  $downAlarms  keyed by device_id
     * @param  array<int,array<string,int>>  $endpoints  deviceId => bucket => count
     * @return list<array<string,mixed>>
     */
    public function analyze(Collection $devices, array $adj, array $roots, Collection $downAlarms, array $endpoints, int $siteId = 0): array
    {
        // BFS from the edge → depth + parent for every reachable device.
        $parent = [];
        $depth = [];
        $queue = [];
        foreach ($roots as $r) {
            $depth[$r] = 0;
            $parent[$r] = null;
            $queue[] = $r;
        }
        while ($queue !== []) {
            $cur = array_shift($queue);
            foreach (array_keys($adj[$cur] ?? []) as $nb) {
                if (! isset($depth[$nb])) {
                    $depth[$nb] = $depth[$cur] + 1;
                    $parent[$nb] = $cur;
                    $queue[] = $nb;
                }
            }
        }

        $isDown = fn (int $id): bool => $downAlarms->has($id);

        // Children map (invert parent) to sum subtrees.
        $children = [];
        foreach ($parent as $id => $p) {
            if ($p !== null) {
                $children[$p][] = $id;
            }
        }

        $incidents = [];
        foreach ($downAlarms as $deviceId => $alarm) {
            $p = $parent[$deviceId] ?? null;
            // Root cause = a failed device whose parent is NOT failed (or has no parent);
            // a failed device under another failed device is a suppressed symptom.
            if ($p !== null && $isDown($p)) {
                continue;
            }

            $subtree = $this->subtree($deviceId, $children); // includes the root itself
            $affected = ['switches' => 0];
            $suppressedAlarmIds = [];
            $suppressedNames = [];
            foreach ($subtree as $sid) {
                foreach (($endpoints[$sid] ?? []) as $bucket => $n) {
                    $affected[$bucket] = ($affected[$bucket] ?? 0) + $n;
                }
                if ($sid === $deviceId) {
                    continue; // the root itself is not "downstream affected"
                }
                $role = $devices[$sid]->role ?? 'device';
                $affected['switches'] += 1; // every downstream managed device loses its path
                if ($isDown($sid)) {
                    $suppressedAlarmIds[] = $downAlarms[$sid]->id;
                    $suppressedNames[] = $devices[$sid]->name;
                }
                unset($role);
            }

            $endpointTotal = array_sum(array_diff_key($affected, ['switches' => 0]));
            $incidents[] = [
                'site_id' => $siteId,
                'root_device_id' => $deviceId,
                'root_device_name' => $devices[$deviceId]->name ?? "device {$deviceId}",
                'root_role' => $devices[$deviceId]->role ?? 'device',
                'ticket_number' => $alarm->ticket_number,
                'started_at' => optional($alarm->first_seen_at)->toIso8601String(),
                'affected' => array_filter($affected, fn ($v) => $v > 0),
                'affected_total' => ($affected['switches'] ?? 0) + $endpointTotal,
                'suppressed_count' => count($suppressedAlarmIds),
                'suppressed_alarm_ids' => $suppressedAlarmIds,
                'suppressed_device_names' => $suppressedNames,
            ];
        }

        // Only incidents that actually suppressed something OR affect endpoints are worth
        // elevating; a lone down device with nothing behind it stays an ordinary alarm.
        return array_values(array_filter($incidents, fn ($i) => $i['affected_total'] > 0));
    }

    /**
     * All device ids in the subtree rooted at $id (inclusive).
     *
     * @param  array<int,list<int>>  $children
     * @return list<int>
     */
    private function subtree(int $id, array $children): array
    {
        $out = [$id];
        foreach ($children[$id] ?? [] as $c) {
            $out = array_merge($out, $this->subtree($c, $children));
        }

        return $out;
    }
}
