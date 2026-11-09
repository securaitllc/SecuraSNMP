<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DiscoveryScanRequest;
use App\Jobs\RunDiscoveryScan;
use App\Models\Device;
use App\Models\DiscoveredDevice;
use App\Models\DiscoveryScan;
use App\Services\DiscoveryScanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DiscoveryScanController extends Controller
{
    public function index(): JsonResponse
    {
        $scans = DiscoveryScan::query()
            ->with('credential:id,name')
            ->withCount([
                'discoveredDevices as new_count' => fn ($q) => $q->where('status', 'new'),
                'discoveredDevices as imported_count' => fn ($q) => $q->where('status', 'imported'),
            ])
            ->latest()
            ->get();

        return response()->json($scans);
    }

    public function store(DiscoveryScanRequest $request): JsonResponse
    {
        $scan = DiscoveryScan::create([
            ...$request->validated(),
            'user_id' => $request->user()?->id,
            'status' => 'pending',
        ]);

        RunDiscoveryScan::dispatch($scan->id);

        return response()->json($scan->load('credential:id,name'), 201);
    }

    public function show(DiscoveryScan $discoveryScan): JsonResponse
    {
        $discoveryScan->load([
            'credential:id,name',
            'discoveredDevices.suggestedSite:id,name',
            'discoveredDevices.matchedDevice:id,name',
        ]);

        return response()->json($discoveryScan);
    }

    public function destroy(DiscoveryScan $discoveryScan): JsonResponse
    {
        $discoveryScan->delete();

        return response()->json(null, 204);
    }

    /**
     * Import selected still-new discovered devices into the Devices table,
     * reusing the scan's SNMP credential. Devices already matched to an
     * existing record ('existing') or already imported are skipped.
     */
    public function import(Request $request, DiscoveryScan $discoveryScan): JsonResponse
    {
        $validated = $request->validate([
            'device_ids' => ['required', 'array', 'min:1'],
            'device_ids.*' => ['integer'],
        ]);

        $cred = $discoveryScan->credential;
        $imported = 0;

        $candidates = $discoveryScan->discoveredDevices()
            ->whereIn('id', $validated['device_ids'])
            ->where('status', 'new')
            ->get();

        foreach ($candidates as $discovered) {
            // site_id is required; a stale discovered row (or a race) can leave
            // suggested_site_id null, so resolve/create the site from its IP (the
            // same idempotent /24 mapping the scanner uses) rather than 500-ing.
            $siteId = $discovered->suggested_site_id
                ?? DiscoveryScanner::siteFor($discovered->ip_address)->id;

            $device = Device::create([
                'site_id' => $siteId,
                'name' => $this->shortHostname($discovered->sys_name) ?: $discovered->ip_address,
                'ip_address' => $discovered->ip_address,
                'vendor' => $discovered->vendor ?: 'juniper',
                'model' => $discovered->model ?: 'Unknown',
                'serial_number' => $discovered->serial_number,
                'role' => $discovered->suggested_role ?: 'switch',
                'snmp_version' => $cred->snmp_version,
                'snmp_community' => $cred->snmp_community,
                'snmp_v3_username' => $cred->snmp_v3_username,
                'snmp_v3_auth_key' => $cred->snmp_v3_auth_key,
                'snmp_v3_priv_key' => $cred->snmp_v3_priv_key,
                'status' => 'active',
            ]);

            $discovered->update([
                'status' => 'imported',
                'imported_device_id' => $device->id,
            ]);

            $imported++;
        }

        return response()->json(['imported' => $imported]);
    }

    /**
     * Short host label from an SNMP sysName: drop the DNS domain so a device is
     * named "CORE-SW01", not the full FQDN that blows out topology nodes.
     */
    private function shortHostname(?string $sysName): ?string
    {
        $name = trim((string) $sysName);
        if ($name === '') {
            return null;
        }

        // Only strip when it looks like host.domain.tld (a real FQDN), not an
        // IP or a bare hostname that happens to contain a dot.
        if (! filter_var($name, FILTER_VALIDATE_IP) && str_contains($name, '.')) {
            $name = strtok($name, '.');
        }

        return $name;
    }

    public function ignore(DiscoveredDevice $discoveredDevice): JsonResponse
    {
        if ($discoveredDevice->status === 'new') {
            $discoveredDevice->update(['status' => 'ignored']);
        }

        return response()->json($discoveredDevice);
    }
}
