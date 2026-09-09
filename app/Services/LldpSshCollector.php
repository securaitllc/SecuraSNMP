<?php

namespace App\Services;

use App\Models\Device;
use App\Models\LldpNeighbor;
use App\Support\SshSession;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reads LLDP neighbors from a Silver Peak EdgeConnect over SSH (`show lldp
 * neighbors`). The appliance does NOT expose the standard LLDP-MIB neighbor table
 * over SNMP — an snmpwalk (what LldpCollector + the "Pull latest" button use)
 * comes back empty on every EdgeConnect while Juniper switches answer fine — so the
 * SD-WAN "Connected Endpoints" panel was always empty. The CLI is the only vantage.
 *
 * Writes into the same lldp_neighbors table LldpCollector uses (same upsert key +
 * absent-reconcile), so the device page / topology consume it identically.
 *
 * Uses the injected-executor pattern (::forProduction) so the parse/reconcile logic
 * is unit-testable with a fake executor.
 */
class LldpSshCollector
{
    private const COMMAND = 'show lldp neighbors';

    /** @param  callable(Device, array): array<string, string>  $executor */
    public function __construct(private $executor)
    {
    }

    public static function forProduction(): self
    {
        return new self(fn (Device $device, array $commands): array => SshSession::run($device, $commands));
    }

    public function pollAll(): void
    {
        Device::where('role', 'edgeconnect')->get()->each(function (Device $device) {
            try {
                $this->poll($device);
            } catch (Throwable $e) {
                Log::warning("SSH LLDP poll failed for device {$device->id}: ".\App\Support\SshError::safe($e->getMessage()));
            }
        });
    }

    /** @return int  neighbors seen this run */
    public function poll(Device $device): int
    {
        $output = ($this->executor)($device, [self::COMMAND]);
        $rows = self::parse($output[self::COMMAND] ?? '');

        // Empty parse = unreachable / unexpected output: leave the stored neighbors
        // as-is rather than stamping them all absent on a transient blip.
        if ($rows === []) {
            return 0;
        }

        // Resolve remote system names to known devices in one query (for topology links).
        $byName = Device::whereIn('name', array_values(array_unique(array_column($rows, 'sysname'))))
            ->get(['id', 'name'])->keyBy('name');

        $seen = [];
        foreach ($rows as $r) {
            $remote = $byName->get($r['sysname']);
            $attrs = [
                'neighbor_type' => $remote ? 'switch' : 'other',
                'remote_device_id' => $remote?->id,
                'last_seen_at' => now(),
                'absent_since' => null,
            ];
            if ($r['chassis'] !== null) {
                $attrs['remote_chassis_id'] = $r['chassis'];
            }
            if ($r['mac'] !== null) {
                $attrs['remote_mac'] = $r['mac'];
            }
            $neighbor = LldpNeighbor::updateOrCreate(
                ['device_id' => $device->id, 'local_port' => $r['local'], 'remote_sysname' => $r['sysname'], 'remote_port' => $r['remote_port']],
                $attrs,
            );
            $seen[] = $neighbor->id;
        }

        // An EdgeConnect's neighbors come ONLY from this SSH source, so a full parse is
        // authoritative: stamp anything not reported this run as absent (kept for the
        // "port went down — what was on it?" history, not deleted).
        LldpNeighbor::where('device_id', $device->id)
            ->whereNotIn('id', $seen ?: [0])
            ->whereNull('absent_since')
            ->update(['absent_since' => now()]);

        return count($seen);
    }

    /**
     * Parse `show lldp neighbors` into rows. Silver Peak aligns a whitespace table with
     * a header; column ORDER has varied across ECOS builds, so this reads by token TYPE
     * (interface / MAC / port / hostname) rather than fixed positions — robust to order.
     *
     * @return list<array{local:string, sysname:string, remote_port:string, chassis:?string, mac:?string}>
     */
    public static function parse(string $output): array
    {
        $rows = [];
        foreach (preg_split('/\r?\n/', $output) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '-') {
                continue;
            }
            $tokens = preg_split('/\s{2,}|\t|\s+/', $line) ?: [];
            // Skip the header row (column names, no data).
            $lower = strtolower($line);
            if (str_contains($lower, 'system name') || str_contains($lower, 'chassis') || str_contains($lower, 'neighbor')
                || (str_contains($lower, 'interface') && str_contains($lower, 'port'))) {
                continue;
            }

            $local = $mac = $chassis = $remotePort = $sysname = null;
            $leftover = [];
            foreach ($tokens as $t) {
                if ($local === null && preg_match('/^t?(?:lan|wan|mgmt)\d+$/i', $t)) {
                    $local = strtolower($t);
                } elseif ($mac === null && preg_match('/^(?:[0-9a-f]{2}[:.-]){5}[0-9a-f]{2}$/i', $t)) {
                    $mac = strtolower(str_replace(['-', '.'], ':', $t));
                    $chassis = $t;
                } elseif ($remotePort === null && preg_match('#^(?:[gx]e-\d|et-\d|ae\d|xe-\d|\d+/\d+|eth\d+|swp\d+|(?:Gi|Te|Fa)\d)#i', $t)) {
                    $remotePort = $t;
                } else {
                    $leftover[] = $t;
                }
            }
            // The system name is the remaining hostname-looking token (letters + a
            // separator/digit) — e.g. a Massey switch "FL0034-SC055SWA001".
            foreach ($leftover as $t) {
                if (preg_match('/[A-Za-z]/', $t) && preg_match('/[-_.\d]/', $t) && strlen($t) >= 3) {
                    $sysname = $t;
                    break;
                }
            }

            // A usable row needs at least a local interface and a remote identity.
            if ($local !== null && ($sysname !== null || $remotePort !== null)) {
                $rows[] = [
                    'local' => $local,
                    'sysname' => $sysname ?? ($chassis ?? 'unknown'),
                    'remote_port' => $remotePort ?? '',
                    'chassis' => $chassis,
                    'mac' => $mac,
                ];
            }
        }

        return $rows;
    }
}
