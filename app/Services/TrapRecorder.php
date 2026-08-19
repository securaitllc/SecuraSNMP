<?php

namespace App\Services;

use App\Models\Device;
use App\Models\SnmpTrap;

class TrapRecorder
{
    /**
     * Parse and store one trap delivered by net-snmp's snmptrapd traphandle,
     * whose stdin format is:
     *   line 1: hostname
     *   line 2: transport, e.g. "UDP: [10.0.0.5]:41234->[172.17.0.2]:162"
     *   line 3+: one "<OID> <value>" varbind per line
     */
    public function record(string $raw): SnmpTrap
    {
        $lines = preg_split('/\r?\n/', trim($raw));
        $transport = $lines[1] ?? '';

        $sourceIp = '0.0.0.0';
        if (preg_match('/\[([\d.]+)\]/', $transport, $m)) {
            $sourceIp = $m[1];
        }

        $varbinds = array_slice($lines, 2);
        $trapOid = null;
        $parts = [];
        foreach ($varbinds as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            // snmpTrapOID.0 carries the trap's identifying OID.
            if (str_starts_with($line, '.1.3.6.1.6.3.1.1.4.1.0') || str_contains($line, 'snmpTrapOID')) {
                $bits = preg_split('/\s+/', $line, 2);
                $trapOid = $bits[1] ?? null;

                continue;
            }
            $parts[] = $line;
        }

        $device = Device::where('ip_address', $sourceIp)->first();

        return SnmpTrap::create([
            'device_id' => $device?->id,
            'source_ip' => $sourceIp,
            'trap_oid' => $trapOid,
            'message' => $parts === [] ? null : implode("\n", $parts),
            'received_at' => now(),
        ]);
    }
}
