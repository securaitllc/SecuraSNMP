<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\EnableLldp;
use App\Models\Device;
use App\Services\ArpCollector;
use App\Services\LldpCollector;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class DeviceLldpController extends Controller
{
    /**
     * On-demand: re-read THIS device's LLDP neighbors right now (over SNMP) instead
     * of waiting for the 10-minute discovery sweep — the "pull latest neighbors"
     * button. Runs the same LLDP + ARP collectors the poller uses, for one device.
     * Rate-limited (each call spawns snmpwalk processes) and gated to analysts.
     */
    public function refresh(Device $device): JsonResponse
    {
        if (! $device->ip_address || (! $device->snmp_community && ! $device->snmp_v3_username)) {
            return response()->json(['error' => 'This device has no SNMP configuration to read LLDP from.'], 422);
        }

        $walker = fn (Device $d, string $oid): string => $this->snmpWalk($d, $oid);

        try {
            (new LldpCollector($walker))->discover($device);
            (new ArpCollector($walker))->resolve($device);
        } catch (\Throwable $e) {
            Log::warning("On-demand LLDP refresh failed for device {$device->id}: {$e->getMessage()}");

            return response()->json(['error' => 'Could not read LLDP — the device may be unreachable or not answering SNMP.'], 502);
        }

        return response()->json(['message' => 'LLDP neighbors refreshed.']);
    }

    /** Bounded snmpwalk (-On numeric OIDs, -t 3 -r 3, hard 15s kill) for the refresh. */
    private function snmpWalk(Device $device, string $oid): string
    {
        if ($device->snmp_version === 'v3') {
            $cmd = ['snmpwalk', '-On', '-t', '3', '-r', '3', '-v3', '-u', (string) $device->snmp_v3_username,
                '-l', 'authPriv', '-a', 'SHA', '-A', (string) $device->snmp_v3_auth_key,
                '-x', 'AES', '-X', (string) $device->snmp_v3_priv_key, $device->ip_address, $oid];
        } else {
            $cmd = ['snmpwalk', '-On', '-t', '3', '-r', '3', '-v2c', '-c', (string) $device->snmp_community, $device->ip_address, $oid];
        }

        $process = new Process($cmd);
        $process->setTimeout(15);
        $process->run();

        return $process->isSuccessful() ? $process->getOutput() : '';
    }

    /**
     * Enable LLDP on chosen Silver Peak LAN interfaces so the switch discovers the
     * appliance as a neighbor. Admin-only, config-write. Interface names are
     * strictly whitelisted before they ever reach the CLI to prevent injection.
     */
    public function enable(Request $request, Device $device): JsonResponse
    {
        if ($device->vendor !== 'silverpeak') {
            return response()->json(['error' => 'LLDP enable is only supported on Silver Peak EdgeConnect appliances.'], 422);
        }

        $data = $request->validate([
            'interfaces' => ['required', 'array', 'min:1', 'max:8'],
            // e.g. lan0, wan0, mgmt0, and the transport forms tlan0 / twan1 — never
            // anything that could carry a CLI payload.
            'interfaces.*' => ['string', 'regex:/^t?(lan|wan|mgmt)\d{1,2}$/'],
        ]);

        // Validate the SSH credential resolves up front (surface a bad-credential
        // error immediately), but run the SSH push itself on the queue so the
        // single-threaded web server isn't blocked for the length of the session —
        // the operator can keep working while it runs.
        try {
            if (! $device->effectiveSshUsername() || ! $device->effectiveSshCredential()) {
                return response()->json(['error' => 'No SSH credential resolved for this device. Link an SSH credential first.'], 422);
            }
        } catch (DecryptException $e) {
            Log::error("LLDP enable — credential decrypt failed for device {$device->id}: {$e->getMessage()}");

            return response()->json(['error' => 'This device\'s SSH credential could not be decrypted. Re-enter or re-link the SSH credential, then try again.'], 502);
        }

        $device->update(['lldp_enable_status' => 'Queued — enabling LLDP on '.implode(', ', $data['interfaces']).'…', 'lldp_enable_at' => now()]);
        EnableLldp::dispatch($device->id, $data['interfaces']);

        return response()->json([
            'queued' => true,
            'message' => 'LLDP enable is running in the background. You can keep working; the neighbor may take a few minutes to appear.',
        ], 202);
    }
}
