<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\Process\Process;

/**
 * On-demand diagnostics ("looking glass"). All commands run with array
 * arguments (no shell) and a hard timeout; the target defaults to the device IP
 * and, when supplied, must be a valid IP so it can't be turned into an argument
 * injection or an arbitrary host.
 */
class DeviceToolController extends Controller
{
    public function run(Request $request, Device $device, string $tool): JsonResponse
    {
        $validated = $request->validate([
            'target' => ['nullable', 'ip'],
            'oid' => ['nullable', 'string', 'regex:/^[0-9.]+$/'],
        ]);

        $target = $validated['target'] ?? $device->ip_address;

        $command = match ($tool) {
            'ping' => ['ping', '-c', '4', '-W', '2', $target],
            'traceroute' => ['traceroute', '-m', '15', '-w', '2', $target],
            'snmpwalk' => $this->snmpwalkCommand($device, $target, $validated['oid'] ?? '.1.3.6.1.2.1.1.1.0'),
            default => null,
        };

        if ($command === null) {
            return response()->json(['message' => 'Unknown tool.'], 422);
        }

        $process = new Process($command);
        $process->setTimeout(30);
        $process->run();

        return response()->json([
            'tool' => $tool,
            'target' => $target,
            'exit_code' => $process->getExitCode(),
            'output' => $process->getOutput().$process->getErrorOutput(),
        ]);
    }

    private function snmpwalkCommand(Device $device, string $target, string $oid): array
    {
        if ($device->snmp_version === 'v3') {
            return [
                'snmpwalk', '-v3', '-u', (string) $device->snmp_v3_username,
                '-l', 'authPriv', '-a', 'SHA', '-A', (string) $device->snmp_v3_auth_key,
                '-x', 'AES', '-X', (string) $device->snmp_v3_priv_key,
                '-t', '2', '-r', '1', $target, $oid,
            ];
        }

        return ['snmpwalk', '-v2c', '-c', (string) $device->snmp_community, '-t', '2', '-r', '1', $target, $oid];
    }
}
