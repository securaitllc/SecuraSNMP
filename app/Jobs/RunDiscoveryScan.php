<?php

namespace App\Jobs;

use App\Models\DiscoveryScan;
use App\Models\SnmpCredential;
use App\Services\DiscoveryScanner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Symfony\Component\Process\Process;
use Throwable;

class RunDiscoveryScan implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    // A /22 sweep touches ~1000 hosts; give it room.
    public int $timeout = 3600;
    public int $tries = 1;

    private const OID_SYS_DESCR = '.1.3.6.1.2.1.1.1.0';
    private const OID_SYS_OBJECT_ID = '.1.3.6.1.2.1.1.2.0';
    private const OID_SYS_NAME = '.1.3.6.1.2.1.1.5.0';
    private const OID_ENT_SERIAL = '.1.3.6.1.2.1.47.1.1.1.1.11';

    public function __construct(public int $scanId)
    {
    }

    public function handle(DiscoveryScanner $scanner): void
    {
        $scan = DiscoveryScan::find($this->scanId);

        if (! $scan) {
            return;
        }

        try {
            $scanner->run($scan, fn (string $ip, SnmpCredential $cred) => $this->probe($ip, $cred));
        } catch (Throwable $e) {
            $scan->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array{sys_name: ?string, sys_descr: ?string, sys_object_id: ?string, serial: ?string}|null
     */
    private function probe(string $ip, SnmpCredential $cred): ?array
    {
        // ICMP pre-check keeps dead hosts from costing an SNMP timeout each.
        $ping = new Process(['ping', '-c', '1', '-W', '1', $ip]);
        $ping->run();

        if (! $ping->isSuccessful()) {
            return null;
        }

        $sysDescr = $this->snmpGet($cred, $ip, self::OID_SYS_DESCR);

        // Reachable but not answering SNMP with this credential — not something
        // we can add and monitor, so it is not a discovery hit.
        if ($sysDescr === null) {
            return null;
        }

        return [
            'sys_descr' => $sysDescr,
            'sys_name' => $this->snmpGet($cred, $ip, self::OID_SYS_NAME),
            'sys_object_id' => $this->snmpGet($cred, $ip, self::OID_SYS_OBJECT_ID),
            'serial' => $this->snmpWalkFirst($cred, $ip, self::OID_ENT_SERIAL),
        ];
    }

    private function snmpGet(SnmpCredential $cred, string $ip, string $oid): ?string
    {
        $process = new Process([...$this->authArgs($cred, 'snmpget'), $ip, $oid]);
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        return $this->cleanValue($process->getOutput());
    }

    private function snmpWalkFirst(SnmpCredential $cred, string $ip, string $oid): ?string
    {
        $process = new Process([...$this->authArgs($cred, 'snmpwalk'), $ip, $oid]);
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        foreach (preg_split('/\r?\n/', $process->getOutput()) as $line) {
            $value = $this->cleanValue($line);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Build the snmp command with value-only output (-Ovq), short timeout, and
     * no MIB loading (-m ''). Passed as array args — no shell, no injection.
     *
     * @return array<int, string>
     */
    private function authArgs(SnmpCredential $cred, string $tool): array
    {
        if ($cred->snmp_version === 'v3') {
            return [
                $tool, '-v3', '-u', (string) $cred->snmp_v3_username,
                '-l', 'authPriv', '-a', 'SHA', '-A', (string) $cred->snmp_v3_auth_key,
                '-x', 'AES', '-X', (string) $cred->snmp_v3_priv_key,
                '-Ovq', '-t', '1', '-r', '0', '-m', '',
            ];
        }

        return [
            $tool, '-v2c', '-c', (string) $cred->snmp_community,
            '-Ovq', '-t', '1', '-r', '0', '-m', '',
        ];
    }

    private function cleanValue(string $raw): ?string
    {
        $value = trim($raw);

        if ($value === '') {
            return null;
        }

        // -Ovq still quotes string values; strip surrounding quotes.
        if (strlen($value) >= 2 && str_starts_with($value, '"') && str_ends_with($value, '"')) {
            $value = substr($value, 1, -1);
        }

        return $value === '' ? null : $value;
    }
}
