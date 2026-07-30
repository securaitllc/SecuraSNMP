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

        // Test SNMP: probe the exact identity OIDs the poller reads, with the device's
        // STORED credentials, and report pass/fail in plain language — so "no serial /
        // no OS" can be diagnosed in one click instead of a shell session.
        if ($tool === 'snmptest') {
            return $this->snmpTest($device, $target);
        }

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

    /**
     * Probe sysDescr / sysName / ifNumber with the stored credentials and turn the
     * result into a verdict the operator can act on.
     */
    private function snmpTest(Device $device, string $target): JsonResponse
    {
        // sysDescr(.1.1.1.0), sysName(.1.5.0), ifNumber(.2.1.0) in one get.
        $oids = ['.1.3.6.1.2.1.1.1.0', '.1.3.6.1.2.1.1.5.0', '.1.3.6.1.2.1.2.1.0'];
        $command = $device->snmp_version === 'v3'
            ? array_merge(['snmpget', '-v3', '-u', (string) $device->snmp_v3_username, '-l', 'authPriv',
                '-a', 'SHA', '-A', (string) $device->snmp_v3_auth_key, '-x', 'AES', '-X', (string) $device->snmp_v3_priv_key,
                '-t', '2', '-r', '1', $target], $oids)
            : array_merge(['snmpget', '-v2c', '-c', (string) $device->snmp_community, '-t', '2', '-r', '1', $target], $oids);

        $process = new Process($command);
        $process->setTimeout(15);
        $process->run();
        $raw = trim($process->getOutput().$process->getErrorOutput());

        $sysDescr = $this->valueFor($raw, ['1.3.6.1.2.1.1.1.0', 'sysDescr']);
        $reachable = $sysDescr !== null;

        if ($reachable) {
            $ifCount = $this->valueFor($raw, ['1.3.6.1.2.1.2.1.0', 'ifNumber']);
            $sysName = $this->valueFor($raw, ['1.3.6.1.2.1.1.5.0', 'sysName']);
            $lines = [
                '✓ SNMP OK — the poller can read this device.',
                '',
                'System:     '.$sysDescr,
                'Hostname:   '.($sysName ?? '—'),
                'Interfaces: '.($ifCount ?? '—'),
                'Version:    '.($device->snmp_version ?? 'v2c'),
                '',
                'If serial / OS are still blank they will fill on the next identity cycle (≤5 min).',
            ];
        } else {
            $lines = [
                '✗ No SNMP response from '.$target.' ('.($device->snmp_version ?? 'v2c').').',
                '',
                trim($raw) !== '' ? $raw : '(no output — request timed out)',
                '',
                'Checklist:',
                ' • The community/credentials saved here match the device.',
                ' • The device\'s SNMP client-list / ACL permits this poller.',
                ' • SNMP is enabled and the OIDs are in the community\'s view.',
            ];
        }

        return response()->json([
            'tool' => 'snmptest',
            'target' => $target,
            'exit_code' => $reachable ? 0 : ($process->getExitCode() ?? 1),
            'output' => implode("\n", $lines),
        ]);
    }

    /** Pull the value for one of the given OID/name tokens out of raw snmpget output. */
    private function valueFor(string $raw, array $tokens): ?string
    {
        foreach (explode("\n", $raw) as $line) {
            foreach ($tokens as $token) {
                if (stripos($line, $token) !== false && str_contains($line, '=')) {
                    $value = trim(preg_replace('/^.*?=\s*[A-Za-z0-9-]+:\s*/', '', $line));
                    $value = trim($value, "\" \t");

                    return $value !== '' ? $value : null;
                }
            }
        }

        return null;
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
