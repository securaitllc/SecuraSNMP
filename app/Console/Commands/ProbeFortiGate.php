<?php

namespace App\Console\Commands;

use App\Models\Device;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Dumps exactly what a FortiGate answers for every OID the alarm poller reads.
 *
 * The poller is written against FORTINET-FORTIGATE-MIB, but FortiOS builds differ
 * in what they populate, and a VM image is not a 401E. Rather than trust the alarms
 * and find out during an incident which checks were silently returning nothing,
 * run this once per firewall and read what actually comes back.
 *
 *   php artisan fortigate:probe 13
 *   php artisan fortigate:probe --all
 *
 * "(no answer)" against a line means that check will never alarm on this box.
 */
class ProbeFortiGate extends Command
{
    protected $signature = 'fortigate:probe {id? : Device id} {--all : Probe every FortiGate}';

    protected $description = 'Show what each FortiGate answers for the OIDs the alarm poller uses.';

    /** @var array<string, string> label => OID */
    private const OIDS = [
        'sysUpTime (reachability gate)' => '.1.3.6.1.2.1.1.3.0',
        'fgSysVersion' => '.1.3.6.1.4.1.12356.101.4.1.1.0',
        'fgSysCpuUsage %' => '.1.3.6.1.4.1.12356.101.4.1.3.0',
        'fgSysMemUsage %' => '.1.3.6.1.4.1.12356.101.4.1.4.0',
        'fgSysDiskUsage MB' => '.1.3.6.1.4.1.12356.101.4.1.6.0',
        'fgSysDiskCapacity MB' => '.1.3.6.1.4.1.12356.101.4.1.7.0',
        'fgSysSesCount' => '.1.3.6.1.4.1.12356.101.4.1.8.0',
        'fgHaSystemMode' => '.1.3.6.1.4.1.12356.101.13.1.1.0',
        'fgHaStatsSerial' => '.1.3.6.1.4.1.12356.101.13.2.1.1.2',
        'fgHaStatsSyncStatus' => '.1.3.6.1.4.1.12356.101.13.2.1.1.12',
        'fgVpnTunEntPhase1Name' => '.1.3.6.1.4.1.12356.101.12.2.2.1.2',
        'fgVpnTunEntPhase2Name' => '.1.3.6.1.4.1.12356.101.12.2.2.1.3',
        'fgVpnTunEntRemGwyIp' => '.1.3.6.1.4.1.12356.101.12.2.2.1.5',
        'fgVpnTunEntStatus (1=down 2=up)' => '.1.3.6.1.4.1.12356.101.12.2.2.1.20',
    ];

    public function handle(): int
    {
        $devices = $this->option('all')
            ? Device::where('vendor', 'fortigate')->where('status', 'active')->get()
            : Device::where('id', $this->argument('id'))->get();

        if ($devices->isEmpty()) {
            $this->error('No matching FortiGate. Pass a device id, or --all.');

            return self::FAILURE;
        }

        foreach ($devices as $device) {
            $this->line('');
            $this->info("── {$device->name}  ({$device->ip_address})");

            foreach (self::OIDS as $label => $oid) {
                $output = trim($this->walk($device, $oid));
                $lines = array_values(array_filter(explode("\n", $output)));

                if ($lines === [] || stripos($output, 'No Such') !== false || stripos($output, 'Timeout') !== false) {
                    $this->line(sprintf('  %-34s (no answer)', $label));

                    continue;
                }

                $first = trim($lines[0]);
                $more = count($lines) > 1 ? '  [+'.(count($lines) - 1).' more rows]' : '';
                $this->line(sprintf('  %-34s %s%s', $label, mb_strimwidth($first, 0, 90, '…'), $more));
            }
        }

        $this->line('');
        $this->comment('Any line reading "(no answer)" is a check that cannot alarm on this build.');

        return self::SUCCESS;
    }

    private function walk(Device $device, string $oid): string
    {
        $command = $device->snmp_version === 'v3'
            ? [
                'snmpwalk', '-v3', '-t', '3', '-r', '2', '-u', (string) $device->snmp_v3_username,
                '-l', 'authPriv', '-a', 'SHA', '-A', (string) $device->snmp_v3_auth_key,
                '-x', 'AES', '-X', (string) $device->snmp_v3_priv_key,
                $device->ip_address, $oid,
            ]
            : ['snmpwalk', '-v2c', '-t', '3', '-r', '2', '-c', (string) $device->snmp_community, $device->ip_address, $oid];

        $process = new Process($command);
        $process->setTimeout(20);
        $process->run();

        return $process->isSuccessful() ? $process->getOutput() : (string) $process->getErrorOutput();
    }
}
