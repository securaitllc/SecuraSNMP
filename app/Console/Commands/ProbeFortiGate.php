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

    /** Enough to read a cluster or a tunnel list; short of dumping a whole branch. */
    private const MAX_ROWS = 12;

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

        // The whole VPN branch. Every individual tunnel column came back empty on
        // all three firewalls, which has two very different explanations: the table
        // is genuinely empty (no IPsec tunnels configured — plausible on an estate
        // whose WAN is EdgeConnect), or the columns are not where this build keeps
        // them. Walking the branch root separates the two: nothing at all under
        // .12 means no VPN data of any kind, while rows under .12 with nothing
        // under .12.2.2.1.20 means the tunnel table lives elsewhere.
        'fgVpn branch root' => '.1.3.6.1.4.1.12356.101.12',
        'fgVpnTunnelTable root' => '.1.3.6.1.4.1.12356.101.12.2.2',

        // Candidates for the DNS/filter side. These are NOT wired into the poller —
        // FortiOS reports UTM and FortiGuard failures in the log rather than in a
        // table, which is why those alarms come from syslog. They are probed anyway
        // because if a build does expose them, polling beats waiting for a log line.
        'fgVdEntTable (virtual domains)' => '.1.3.6.1.4.1.12356.101.3.2.1.1.1',
        'fgAvVirusDetected' => '.1.3.6.1.4.1.12356.101.8.2.1.1.1',
        'fgIpsIntrusionsDetected' => '.1.3.6.1.4.1.12356.101.9.2.1.1.1',
        'fgWfHTTPBlocked' => '.1.3.6.1.4.1.12356.101.10.2.1.1.3',
        'fgAppProxyHTTP (proxy stats)' => '.1.3.6.1.4.1.12356.101.10.1.1',
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

                // "No answer" only when EVERY line is one. Testing the whole output
                // would report a 500-row branch walk as empty because one row in it
                // said "No Such Instance".
                $real = array_values(array_filter(
                    $lines,
                    fn (string $l) => stripos($l, 'No Such') === false && stripos($l, 'Timeout') === false
                ));

                if ($real === []) {
                    $this->line(sprintf('  %-34s (no answer)', $label));

                    continue;
                }

                $lines = $real;

                // Show every row, up to a sane cap. Truncating to the first one hid
                // the second HA member's sync status — the single value that says
                // whether a real cluster is healthy — behind "[+1 more rows]".
                $shown = array_slice($lines, 0, self::MAX_ROWS);

                $this->line(sprintf('  %-34s %s', $label, mb_strimwidth(trim($shown[0]), 0, 90, '…')));

                foreach (array_slice($shown, 1) as $line) {
                    $this->line(sprintf('  %-34s %s', '', mb_strimwidth(trim($line), 0, 90, '…')));
                }

                if (count($lines) > self::MAX_ROWS) {
                    $this->line(sprintf('  %-34s … %d more rows', '', count($lines) - self::MAX_ROWS));
                }
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
