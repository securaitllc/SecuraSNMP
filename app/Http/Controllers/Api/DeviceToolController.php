<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Support\SshError;
use App\Support\SshSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use phpseclib3\Net\SSH2;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

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
            // A physical Juniper port name (ge-0/0/25) — strictly bounded so it can
            // never be turned into a second SSH command / argument injection.
            'interface' => ['nullable', 'string', 'regex:#^(ge|xe|et|fe|mge|xle)-\d+/\d+/\d+$#'],
        ]);

        $target = $validated['target'] ?? $device->ip_address;

        // TDR cable test (Juniper): a live NOC diagnostic that briefly probes the
        // copper pairs, so it's gated to analyst/admin and Juniper switches only.
        if ($tool === 'tdr') {
            return $this->tdr($request, $device, $validated['interface'] ?? null);
        }

        // Test SNMP: probe the exact identity OIDs the poller reads, with the device's
        // STORED credentials, and report pass/fail in plain language — so "no serial /
        // no OS" can be diagnosed in one click instead of a shell session.
        if ($tool === 'snmptest') {
            return $this->snmpTest($device, $target);
        }

        // Looking-glass capture of a FortiGate's SD-WAN health-check status over SSH.
        // Runs BOTH the modern (7.2+) and legacy (6.4/7.0) command names so we get the
        // real runtime SLA table regardless of FortiOS version — the basis for the
        // SD-WAN member SLA poller.
        if ($tool === 'fortisdwan') {
            return $this->fortiSdwan($device);
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
                '✓ SNMP OK — the poller can read this device with the saved credentials.',
                '',
                'System:     '.$sysDescr,
                'Hostname:   '.($sysName ?? '—'),
                'Interfaces: '.($ifCount ?? '—'),
                'Version:    '.($device->snmp_version ?? 'v2c'),
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

    /**
     * Juniper TDR (Time-Domain Reflectometry) cable test on one copper port: starts
     * the test and reads the per-pair result over SSH. Distinguishes a cabling fault
     * (open/short/distance) from an interface fault, so a flapping/erroring port can
     * be triaged in one click. Briefly probes the line, so it's an analyst action.
     */
    private function tdr(Request $request, Device $device, ?string $interface): JsonResponse
    {
        $role = $request->user()?->role;
        if ($role !== 'admin' && $role !== 'analyst') {
            return response()->json(['message' => 'A cable test is a NOC action (analyst or admin).'], 403);
        }
        if ($device->vendor !== 'juniper') {
            return response()->json(['message' => 'The TDR cable test is available on Juniper switches only.'], 422);
        }
        if (! $interface) {
            return response()->json(['message' => 'An interface (e.g. ge-0/0/25) is required.'], 422);
        }

        if (! $device->effectiveSshUsername() || ! $device->effectiveSshCredential()) {
            return response()->json([
                'tool' => 'tdr', 'target' => $interface, 'exit_code' => 1,
                'output' => "✗ No SSH credential resolved for this switch. Link an SSH credential (or set an inline SSH username/password) on the device, then retry.",
            ], 422);
        }

        $start = "request diagnostics tdr start interface {$interface}";
        $show = "show diagnostics tdr interface {$interface}";

        try {
            // `request diagnostics tdr start` returns to the prompt IMMEDIATELY — it only
            // launches the test, which then runs ~5-7s on the switch, so we start it and
            // then POLL the result until a terminal state (Passed/Failed or per-pair
            // results) appears, capped so a stuck test can't hang the request.
            //
            // This runs over ONE SSH session with prompt-aware reads: the old code called
            // SshSession::run() five times (a fresh login each), and every read blocked the
            // full 12s READ_TIMEOUT — ~190s total, past nginx's 120s proxy cap → a 504 and
            // "TDR doesn't work". One login + reads that return the instant the CLI prompt
            // reappears keeps the whole test to a few seconds.
            $ssh = new SSH2($device->ip_address, 22, 8);
            if (! $ssh->login((string) $device->effectiveSshUsername(), (string) $device->effectiveSshCredential())) {
                throw new RuntimeException("SSH login failed for device {$device->id}");
            }
            $ssh->setTimeout(8);
            $ssh->enablePTY();
            $banner = $ssh->read(); // opens the shell + the first prompt

            // The Juniper prompt (user@host> or #) ends every command's output — read
            // until it reappears instead of waiting out the full timeout.
            $promptRe = '/[\w.@:\/~+-]+[>#%]\s*$/';
            if (preg_match('/([^\r\n]*[>#%])\s*$/', $banner, $pm)) {
                $promptRe = '/'.preg_quote(trim($pm[1]), '/').'\s*$/';
            }
            $send = function (string $cmd) use ($ssh, $promptRe): string {
                $ssh->write($cmd."\n");

                return (string) $ssh->read($promptRe, SSH2::READ_REGEX);
            };

            $send('set cli screen-length 0'); // paging off
            $send($start);

            $raw = '';
            for ($attempt = 0; $attempt < 4; $attempt++) {
                sleep(3);
                $raw = trim($send($show));
                if ($this->tdrComplete($raw)) {
                    break;
                }
            }
        } catch (Throwable $e) {
            return response()->json([
                'tool' => 'tdr', 'target' => $interface, 'exit_code' => 1,
                'output' => "✗ SSH failed: ".SshError::safe($e->getMessage()),
            ]);
        }

        $verdict = $this->tdrVerdict($raw);

        return response()->json([
            'tool' => 'tdr',
            'target' => $interface,
            'exit_code' => 0,
            'output' => ($verdict ? $verdict."\n\n" : '').($raw !== '' ? $raw : '(no result — the test may still be running; try again)'),
        ]);
    }

    /**
     * Has the TDR test reached a terminal state? While it's queued or running the
     * switch reports "Test status : Not Started / Started / Running"; it's done when a
     * Passed/Failed status or the per-pair "Cable status" lines appear. Used to stop
     * polling as soon as the result is ready (typically ~5-7s).
     */
    private function tdrComplete(string $raw): bool
    {
        if ($raw === '') {
            return false;
        }
        if (preg_match('/Test status\s*:\s*(Not Started|Started|Running|In Progress)/i', $raw)) {
            return false;
        }

        return (bool) preg_match('/Test status\s*:\s*(Passed|Failed)/i', $raw)
            || (bool) preg_match('/Cable status\s*:/i', $raw);
    }

    /** One-line human verdict from the TDR output: cabling OK vs a fault to chase. */
    private function tdrVerdict(string $raw): string
    {
        if ($raw === '') {
            return '';
        }
        $faults = [];
        foreach (explode("\n", $raw) as $line) {
            if (preg_match('/Cable status\s*:\s*(.+)/i', $line, $m) && ! preg_match('/normal/i', $m[1])) {
                $faults[] = trim($m[1]);
            }
        }
        if ($faults !== []) {
            return '✗ Cable fault detected: '.implode(', ', array_unique($faults)).' — likely a cabling/connector problem, not the interface.';
        }
        if (preg_match('/Test status\s*:\s*Passed/i', $raw) || stripos($raw, 'Normal') !== false) {
            // TDR only runs on COPPER (RJ-45) ports — there is no SFP on a port it
            // can test, so a clean result points at the interface or the far end.
            return '✓ Cabling looks good (all pairs Normal) — copper is fine, so if the port still errors/flaps, suspect the interface itself or the far-end device, not the cable.';
        }

        return '';
    }

    /**
     * Capture a FortiGate's SD-WAN health-check runtime status over SSH — the
     * per-member state/loss/latency/jitter table. Returns raw output so the exact
     * FortiOS format can drive the SD-WAN SLA poller's parser.
     */
    private function fortiSdwan(Device $device): JsonResponse
    {
        if ($device->vendor !== 'fortigate') {
            return response()->json(['message' => 'SD-WAN health-check applies to FortiGate firewalls only.'], 422);
        }

        $commands = [
            'diagnose sys sdwan health-check',            // FortiOS 7.2+
            'diagnose sys virtual-wan-link health-check', // FortiOS 6.4 / 7.0
        ];

        try {
            $out = SshSession::run($device, $commands);
        } catch (Throwable $e) {
            return response()->json([
                'tool' => 'fortisdwan',
                'target' => $device->ip_address,
                'exit_code' => 1,
                'output' => "✗ SSH failed: ".SshError::safe($e->getMessage())
                    ."\n\nChecklist:\n • SSH credentials saved on this device are correct."
                    ."\n • The FortiGate's trusted-host / admin ACL permits this poller's IP.",
            ]);
        }

        $lines = [];
        foreach ($commands as $cmd) {
            $raw = trim($out[$cmd] ?? '');
            $lines[] = "$ {$cmd}";
            $lines[] = $raw !== '' ? $raw : '(no output)';
            $lines[] = '';
        }

        return response()->json([
            'tool' => 'fortisdwan',
            'target' => $device->ip_address,
            'exit_code' => 0,
            'output' => trim(implode("\n", $lines)),
        ]);
    }

    /** Pull the value for one of the given OID/name tokens out of raw snmpget output. */
    private function valueFor(string $raw, array $tokens): ?string
    {
        foreach (explode("\n", $raw) as $line) {
            // net-snmp prints the standard subtree as "iso.3.6.1..." (iso = 1) when
            // the MIBs aren't loaded — normalise the leading iso. to 1. so a numeric
            // OID token matches.
            $line = preg_replace('/^\s*iso\./', '1.', trim($line));
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
