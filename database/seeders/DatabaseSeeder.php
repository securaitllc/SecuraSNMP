<?php

namespace Database\Seeders;

use App\Models\Circuit;
use App\Models\CircuitAlert;
use App\Models\CircuitMetricHistory;
use App\Models\Device;
use App\Models\DeviceAlarm;
use App\Models\DeviceHealth;
use App\Models\DeviceInterface;
use App\Models\DeviceSensor;
use App\Models\SyslogMessage;
use App\Models\DeviceMetricHistory;
use App\Models\DeviceVlan;
use App\Models\InterfaceAlert;
use App\Models\InterfaceMetricHistory;
use App\Models\DeviceNextHop;
use App\Models\IspProvider;
use App\Models\LldpNeighbor;
use App\Models\NextHopAlert;
use App\Models\SnmpTrap;
use App\Models\Site;
use App\Models\SnmpCredential;
use App\Models\SshCredential;
use App\Models\Tunnel;
use App\Models\TunnelMetricHistory;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    private const HISTORY_HOURS = 48;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Created directly (not via User::factory()) because factories rely on
        // fakerphp/faker, a require-dev package absent from the production
        // Docker image's --no-dev vendor/. The same applies to every model
        // created below.
        // Bootstrap logins. Passwords are change-me placeholders overridable per
        // environment (SEED_ADMIN_PASSWORD / SEED_VIEWER_PASSWORD) — rotate them
        // in the UI right after first login regardless.
        User::create([
            'name' => 'Admin',
            'email' => 'admin@securasnmp.local',
            'password' => bcrypt(env('SEED_ADMIN_PASSWORD', 'ChangeMe123!')),
            'role' => 'admin',
            'is_active' => true,
        ]);

        User::create([
            'name' => 'Viewer',
            'email' => 'viewer@securasnmp.local',
            'password' => bcrypt(env('SEED_VIEWER_PASSWORD', 'ChangeMe123!')),
            'role' => 'viewer',
            'is_active' => true,
        ]);

        // Reusable SNMP credential for the Discovery workflow (community
        // encrypted at rest). Operators pick this when launching a subnet scan.
        SnmpCredential::create([
            'name' => 'Massey read-only',
            'snmp_version' => 'v2c',
            'snmp_community' => 'public',
            'notes' => 'Read-only community used to discover switches (.10) and SD-WAN (.254).',
        ]);

        // Massey Services is Florida-based; coordinates place the site markers on
        // the map. The HQ carries the three fully-instrumented demo devices; the
        // branches carry lighter fixtures chosen so the map shows a mix of healthy
        // and alerting sites.
        // HQ is the WAN hub; the branches below home to it (hub-and-spoke).
        $hq = Site::create([
            'name' => 'Corporate HQ — Orlando',
            'site_type' => 'hub',
            'address' => '3210 Clay Ave, Orlando, FL',
            'latitude' => 28.5384,
            'longitude' => -81.3789,
        ]);

        $this->seedJuniperSwitch($hq);
        $this->seedEdgeConnect($hq);
        $this->seedFortigateFirewall($hq);

        // ISP providers — support phone + account rep stored once, reused by circuits.
        $lumen = IspProvider::create([
            'name' => 'Lumen',
            'support_phone' => '1-800-555-0134',
            'account_rep_name' => 'Dana Ruiz',
            'account_rep_mobile' => '407-555-0110',
            'account_rep_phone' => '407-555-0111',
            'account_rep_email' => 'dana.ruiz@lumen.example',
        ]);
        $spectrum = IspProvider::create([
            'name' => 'Spectrum',
            'support_phone' => '1-855-555-0177',
            'account_rep_name' => 'Marco Lee',
            'account_rep_mobile' => '813-555-0120',
            'account_rep_email' => 'marco.lee@spectrum.example',
        ]);
        $att = IspProvider::create([
            'name' => 'AT&T',
            'support_phone' => '1-800-555-0199',
            'account_rep_name' => 'Priya Shah',
            'account_rep_mobile' => '904-555-0130',
            'account_rep_phone' => '904-555-0131',
            'account_rep_email' => 'priya.shah@att.example',
        ]);

        Circuit::create([
            'site_id' => $hq->id,
            'isp_provider_id' => $lumen->id,
            'isp_name' => 'Lumen',
            'circuit_type' => 'fiber',
            'circuit_id' => 'CKT-ORL-4471',
            'account_number' => '8841200055',
            'monitored_ip' => '64.132.10.1',
            'subnet' => '255.255.255.252',
            'status' => 'up',
            'last_checked_at' => now(),
        ]);

        // Tampa — everything healthy (green marker).
        $tampa = Site::create([
            'name' => 'Tampa Branch',
            'hub_site_id' => $hq->id,
            'address' => '4801 W Kennedy Blvd, Tampa, FL',
            'latitude' => 27.9506,
            'longitude' => -82.4572,
        ]);
        $this->seedBranchDevice($tampa, 'tampa-sw01', '10.20.1.1', 'up');
        Circuit::create([
            'site_id' => $tampa->id,
            'isp_provider_id' => $spectrum->id,
            'isp_name' => 'Spectrum',
            'circuit_type' => 'cable',
            'circuit_id' => 'CKT-TPA-2210',
            'account_number' => '7712009984',
            'monitored_ip' => '24.104.8.5',
            'subnet' => '255.255.255.248',
            'status' => 'up',
            'last_checked_at' => now(),
        ]);

        // Jacksonville — a circuit is down (red marker + a circuit-down alert with a ticket).
        $jax = Site::create([
            'name' => 'Jacksonville Branch',
            'hub_site_id' => $hq->id,
            'address' => '76 S Laura St, Jacksonville, FL',
            'latitude' => 30.3322,
            'longitude' => -81.6557,
        ]);
        $this->seedBranchDevice($jax, 'jax-sw01', '10.30.1.1', 'up');
        $jaxCircuit = Circuit::create([
            'site_id' => $jax->id,
            'isp_provider_id' => $att->id,
            'isp_name' => 'AT&T',
            'circuit_type' => 'fiber',
            'circuit_id' => 'CKT-JAX-9032',
            'account_number' => '3390881220',
            'monitored_ip' => '99.12.40.9',
            'subnet' => '255.255.255.252',
            'status' => 'down',
            'last_checked_at' => now(),
        ]);
        // An earlier outage on this circuit that was ticketed and cleared...
        CircuitAlert::create([
            'circuit_id' => $jaxCircuit->id,
            'started_at' => now()->subDays(6),
            'ended_at' => now()->subDays(6)->addHours(3),
            'ticket_number' => 'INC-39900',
        ]);
        // ...and the same circuit is down AGAIN now, with no ticket yet — the UI
        // surfaces #INC-39900 to reference or reopen with the ISP.
        CircuitAlert::create([
            'circuit_id' => $jaxCircuit->id,
            'started_at' => now()->subHours(2)->subMinutes(14),
            'ended_at' => null,
            'ticket_number' => null,
        ]);
        // Jacksonville also has a Silver Peak behind that AT&T circuit. With the
        // underlay down, its next-hop is unreachable and its tunnels drop — the
        // full 3-alarm ISP-outage correlation the Topology view draws as one
        // incident (circuit = root cause; next-hop + tunnels = symptoms).
        $jaxEdge = Device::create([
            'site_id' => $jax->id,
            'name' => 'jax-ec01',
            'ip_address' => '10.30.2.1',
            'next_hop_ip' => '99.12.40.9',
            'vendor' => 'silverpeak',
            'model' => 'EC10104',
            'role' => 'edgeconnect',
            'ssh_username' => 'admin',
            'ssh_credential' => 'demo-password',
            'status' => 'active',
        ]);
        foreach (['wan0', 'wan1'] as $idx => $wan) {
            // wan0 (the AT&T underlay that's down) shows CRC errors + drops — the
            // kind of last-mile signal the interface-stats panel is meant to surface.
            $errs = $idx === 0 ? 214 : 0;
            $drops = $idx === 0 ? 37 : 0;
            DeviceInterface::create([
                'device_id' => $jaxEdge->id, 'if_index' => $idx + 1, 'if_name' => $wan,
                'status' => $idx === 0 ? 'down' : 'up',
                'in_octets' => 0, 'out_octets' => 0, 'in_discards' => $drops, 'out_discards' => 0,
                'in_discards_delta' => $drops, 'out_discards_delta' => 0,
                'in_errors' => $errs, 'out_errors' => 0, 'in_errors_delta' => $errs, 'out_errors_delta' => 0,
                'speed_bps' => 1_000_000_000, 'in_util_pct' => $idx === 0 ? 0 : 34.5, 'out_util_pct' => $idx === 0 ? 0 : 12.1,
                'last_polled_at' => now(),
            ]);
        }
        foreach (['MPLS-to-DC', 'Internet-to-DC'] as $tname) {
            Tunnel::create([
                'device_id' => $jaxEdge->id, 'tunnel_name' => $tname, 'status' => 'down',
                'in_discards' => 0, 'out_discards' => 0, 'in_discards_delta' => 0, 'out_discards_delta' => 0,
                'last_checked_at' => now(),
            ]);
        }
        // jax Silver Peak has two WAN uplinks; the AT&T underlay (wan0) is down so
        // its next-hop is unreachable, wan1 (backup) still reachable.
        $jaxNh0 = DeviceNextHop::create(['device_id' => $jaxEdge->id, 'ip_address' => '99.12.40.9', 'interface' => 'wan0', 'reachability' => 'unreachable', 'status' => 'down', 'uptime' => '—', 'last_checked_at' => now()]);
        DeviceNextHop::create(['device_id' => $jaxEdge->id, 'ip_address' => '4.17.76.49', 'interface' => 'wan1', 'reachability' => 'reachable', 'status' => 'up', 'uptime' => '5d 2h', 'last_checked_at' => now()]);
        // A LAN-side next-hop (lan0 cabled to the Juniper per LLDP) — renders in the
        // LAN area, not the WAN chain.
        DeviceNextHop::create(['device_id' => $jaxEdge->id, 'ip_address' => '10.40.0.1', 'interface' => 'lan0', 'reachability' => 'reachable', 'status' => 'up', 'uptime' => '30d 6h', 'last_checked_at' => now()]);
        NextHopAlert::create([
            'device_id' => $jaxEdge->id,
            'device_next_hop_id' => $jaxNh0->id,
            'started_at' => now()->subHours(2)->subMinutes(10),
            'ended_at' => null,
        ]);

        // Miami — a device interface is down (red marker + an interface-down alert).
        $miami = Site::create([
            'name' => 'Miami Branch',
            'hub_site_id' => $hq->id,
            'address' => '1450 Brickell Ave, Miami, FL',
            'latitude' => 25.7617,
            'longitude' => -80.1918,
        ]);
        $miamiDevice = $this->seedBranchDevice($miami, 'miami-sw01', '10.40.1.1', 'up');
        $downInterface = DeviceInterface::create([
            'device_id' => $miamiDevice->id,
            'if_index' => 2,
            'if_name' => 'ge-0/0/1',
            'status' => 'down',
            'in_octets' => 0,
            'out_octets' => 0,
            'in_discards' => 0,
            'out_discards' => 0,
            'in_discards_delta' => 0,
            'out_discards_delta' => 0,
            'last_polled_at' => now(),
        ]);
        InterfaceAlert::create([
            'device_interface_id' => $downInterface->id,
            'started_at' => now()->subMinutes(11),
            'ended_at' => null,
        ]);

        // Response-time history for every circuit: healthy circuits get a
        // realistic latency line; the down circuit's recent points are timeouts
        // (null) so the graph shows the outage as gaps.
        foreach (Circuit::all() as $circuit) {
            $this->seedCircuitHistory($circuit, $circuit->status === 'down');
        }

        // ICMP response-time history + a synthetic SNMP-style serial for every
        // device (Health column, detail page, and inventory export).
        $serialPrefixes = ['juniper' => 'JN', 'silverpeak' => 'SP', 'fortigate' => 'FG'];
        foreach (Device::all() as $device) {
            $this->seedDeviceHistory($device);
            $device->update([
                'serial_number' => ($serialPrefixes[$device->vendor] ?? 'DV').random_int(100000, 999999).'X',
            ]);
        }

        // Active VLANs on the switch-role devices (as if discovered over SNMP).
        foreach (Device::where('role', 'switch')->get() as $switch) {
            foreach ([['vlan_id' => 10, 'name' => 'DATA'], ['vlan_id' => 20, 'name' => 'VOICE'], ['vlan_id' => 99, 'name' => 'MGMT']] as $v) {
                DeviceVlan::create([
                    'device_id' => $switch->id,
                    'vlan_id' => $v['vlan_id'],
                    'name' => $v['name'],
                    'status' => 'active',
                    'last_seen_at' => now(),
                ]);
            }
        }

        // A couple of demo SNMP traps on the HQ core switch.
        $coreSwitch = Device::where('name', 'demo-core-sw01')->first();
        if ($coreSwitch) {
            SnmpTrap::create([
                'device_id' => $coreSwitch->id,
                'source_ip' => $coreSwitch->ip_address,
                'trap_oid' => '.1.3.6.1.6.3.1.1.5.3',
                'message' => 'linkDown: ifIndex 2 (ge-0/0/1)',
                'received_at' => now()->subMinutes(42),
            ]);
            SnmpTrap::create([
                'device_id' => $coreSwitch->id,
                'source_ip' => $coreSwitch->ip_address,
                'trap_oid' => '.1.3.6.1.6.3.1.1.5.4',
                'message' => 'linkUp: ifIndex 2 (ge-0/0/1)',
                'received_at' => now()->subMinutes(38),
            ]);
        }

        // Shared SSH credential (password encrypted at rest). Linked to the
        // SD-WAN devices, which are the ones verified over SSH. The password is
        // a change-me placeholder for the demo — override it per environment
        // with SEED_SSH_PASSWORD, or rotate it in the admin UI after deploy.
        $sshCredential = SshCredential::create([
            'name' => 'Massey NOC',
            'username' => env('SEED_SSH_USERNAME', 'netadmin'),
            'password' => env('SEED_SSH_PASSWORD', 'ChangeMe-SSH!2026'),
            'notes' => 'Shared SSH login used to verify EdgeConnect appliances.',
        ]);
        Device::where('role', 'edgeconnect')->update(['ssh_credential_id' => $sshCredential->id]);

        // Demo health + capacity data (live deployments populate this via the
        // health:monitor and interfaces:monitor pollers). Seeded so the health
        // panel and the "Busiest Interfaces" card aren't empty on first run.
        $cpu = [22, 41, 68, 15, 33, 79];
        $mem = [55, 72, 60, 48, 83, 66];
        $temp = [38, 44, 52, 35, 47, 58];
        $rpm = [4200, 3900, 5100, 4000, 4600, 5400];
        foreach (Device::all()->values() as $i => $device) {
            DeviceHealth::create([
                'device_id' => $device->id,
                'cpu_pct' => $cpu[$i % 6],
                'mem_pct' => $mem[$i % 6],
                'temperature_c' => $temp[$i % 6],
                'uptime_seconds' => (14 - ($i % 14)) * 86400 + 3600,
                'polled_at' => now(),
            ]);
            DeviceSensor::create(['device_id' => $device->id, 'name' => 'Inlet Temp', 'sensor_type' => 'celsius', 'value' => $temp[$i % 6], 'unit' => '°C', 'status' => 'ok', 'last_seen_at' => now()]);
            DeviceSensor::create(['device_id' => $device->id, 'name' => 'Fan 1', 'sensor_type' => 'rpm', 'value' => $rpm[$i % 6], 'unit' => 'RPM', 'status' => 'ok', 'last_seen_at' => now()]);
        }

        $utils = [88, 64, 47, 31, 22, 15, 9, 5];
        foreach (DeviceInterface::where('status', 'up')->take(8)->get()->values() as $idx => $if) {
            $if->update([
                'speed_bps' => 1_000_000_000,
                'in_util_pct' => $utils[$idx] ?? 5,
                'out_util_pct' => max(2, ($utils[$idx] ?? 5) - 12),
            ]);
        }

        // Discovered LLDP adjacency: each switch reports the co-located Silver Peak
        // as a neighbor (as the real Juniper LLDP-MIB would), so the topology draws
        // the real SD-WAN → switch link with ports instead of inferring it.
        foreach ([['demo-core-sw01', 'demo-edge-01', 'ge-0/0/47', 'lan0'], ['jax-sw01', 'jax-ec01', 'ge-0/0/47', 'lan0']] as [$swName, $ecName, $swPort, $ecPort]) {
            $sw = Device::where('name', $swName)->first();
            $ec = Device::where('name', $ecName)->first();
            if ($sw && $ec) {
                LldpNeighbor::create([
                    'device_id' => $sw->id, 'local_port' => $swPort,
                    'remote_sysname' => $ec->name, 'remote_port' => $ecPort,
                    'remote_device_id' => $ec->id, 'last_seen_at' => now(),
                    'neighbor_type' => 'switch',
                ]);
            }
        }

        // Unmanaged LLDP endpoints on jax-sw01: Mist APs + PoE phones, as a real
        // switch would report them, so the topology shows what's plugged in + port.
        $jaxSw = Device::where('name', 'jax-sw01')->first();
        if ($jaxSw) {
            // Endpoint identity mirrors what LLDP really advertises, so the demo
            // exercises the same paths as live data: an AP publishes a MAC chassis
            // id and no extension, while a handset publishes an address and carries
            // its extension in the system name. One column per attribute would
            // leave most cells empty, which is why the UI groups them.
            $endpoints = [
                ['ge-0/0/5', 'JAX-AP-Lobby', 'Juniper Mist AP43', 'ap', '02:00:5E:11:A0:14', null, null, '10.30.1.51'],
                ['ge-0/0/6', 'JAX-AP-Warehouse', 'Juniper Mist AP33', 'ap', '02:00:5E:11:A0:2B', null, null, '10.30.1.52'],
                ['ge-0/0/12', 'regDN 500310,MINET_6930', 'Mitel MiNet PoE Phone', 'phone', null, '500310', 'Mitel 6930', '10.30.2.61'],
                ['ge-0/0/13', 'regDN 500311,MINET_6920', 'Mitel MiNet PoE Phone', 'phone', null, '500311', 'Mitel 6920', '10.30.2.62'],
                ['ge-0/0/14', 'Yealink-T54W', 'Yealink SIP-T54W', 'phone', '02:00:5E:22:B1:07', null, null, '10.30.2.63'],
            ];
            foreach ($endpoints as [$port, $name, $desc, $type, $mac, $ext, $model, $ip]) {
                LldpNeighbor::create([
                    'device_id' => $jaxSw->id, 'local_port' => $port,
                    'remote_sysname' => $name, 'remote_sysdesc' => $desc,
                    'remote_port' => 'eth0', 'neighbor_type' => $type,
                    'remote_mac' => $mac, 'extension' => $ext,
                    'endpoint_model' => $model, 'remote_mgmt_addr' => $ip,
                    'last_seen_at' => now(),
                ]);
            }
        }

        // Syslog samples across devices.
        $sysMsgs = [
            [3, 'ge-0/0/1: link down'],
            [4, 'OSPF neighbor 10.10.1.2 went DOWN'],
            [6, 'User admin logged in from 10.10.1.50'],
            [5, 'Configuration changed by netadmin'],
            [2, 'Power supply PSU1 failure'],
            [6, 'DHCP lease granted 10.20.5.34'],
            [4, 'High CPU utilization detected (79%)'],
        ];
        foreach (Device::all()->values() as $i => $device) {
            [$sev, $msg] = $sysMsgs[$i % count($sysMsgs)];
            SyslogMessage::create([
                'device_id' => $device->id,
                'source_ip' => $device->ip_address,
                'facility' => 16,
                'severity' => $sev,
                'hostname' => $device->name,
                'message' => $msg,
                'received_at' => now()->subMinutes(($i + 1) * 7),
            ]);
        }
    }

    private function seedDeviceHistory(Device $device): void
    {
        // One ping per minute would be a lot of rows; seed one per 5 minutes
        // over the last 24 hours for a good-looking response-time graph.
        $points = 24 * 12;
        $base = random_int(6, 24);

        for ($i = $points; $i >= 0; $i--) {
            DeviceMetricHistory::create([
                'device_id' => $device->id,
                'recorded_at' => now()->subMinutes($i * 5),
                'response_time_ms' => $base + random_int(-3, 8) + ($i % 9 === 0 ? random_int(3, 14) : 0),
            ]);
        }
    }

    private function seedCircuitHistory(Circuit $circuit, bool $endsInTimeout): void
    {
        // One point per 5 minutes over the last 24 hours.
        $points = 24 * 12;
        $base = random_int(9, 22);

        for ($i = $points; $i >= 0; $i--) {
            // The most recent ~30 minutes of a down circuit are timeouts.
            $isTimeout = $endsInTimeout && $i <= 6;

            CircuitMetricHistory::create([
                'circuit_id' => $circuit->id,
                'recorded_at' => now()->subMinutes($i * 5),
                'response_time_ms' => $isTimeout ? null : $base + random_int(-3, 9) + ($i % 7 === 0 ? random_int(4, 18) : 0),
            ]);
        }
    }

    private function seedBranchDevice(Site $site, string $name, string $ip, string $status): Device
    {
        $device = Device::create([
            'site_id' => $site->id,
            'name' => $name,
            'ip_address' => $ip,
            'vendor' => 'juniper',
            'model' => 'EX2300',
            'role' => 'switch',
            'snmp_version' => 'v2c',
            'snmp_community' => 'public',
            'status' => 'active',
        ]);

        $interface = DeviceInterface::create([
            'device_id' => $device->id,
            'if_index' => 1,
            'if_name' => 'ge-0/0/0',
            'status' => $status,
            'in_octets' => 0,
            'out_octets' => 0,
            'in_discards' => 0,
            'out_discards' => 0,
            'in_discards_delta' => 0,
            'out_discards_delta' => 0,
            'last_polled_at' => now(),
        ]);

        $this->seedInterfaceHistory([$interface]);

        return $device;
    }

    private function seedJuniperSwitch(Site $site): void
    {
        $device = Device::create([
            'site_id' => $site->id,
            'name' => 'demo-core-sw01',
            'ip_address' => '10.10.1.1',
            'vendor' => 'juniper',
            'model' => 'EX3400',
            'role' => 'switch',
            'snmp_version' => 'v2c',
            'snmp_community' => 'public',
            'status' => 'active',
        ]);

        $interfaces = [];
        foreach (['ge-0/0/0', 'ge-0/0/1'] as $index => $name) {
            $interfaces[] = DeviceInterface::create([
                'device_id' => $device->id,
                'if_index' => $index + 1,
                'if_name' => $name,
                'status' => 'up',
                'in_octets' => 0,
                'out_octets' => 0,
                'in_discards' => 0,
                'out_discards' => 0,
                'in_discards_delta' => 0,
                'out_discards_delta' => 0,
                'last_polled_at' => now(),
            ]);
        }

        $this->seedInterfaceHistory($interfaces);
    }

    private function seedEdgeConnect(Site $site): void
    {
        $device = Device::create([
            'site_id' => $site->id,
            'name' => 'demo-edge-01',
            'ip_address' => '10.10.2.1',
            'next_hop_ip' => '10.10.2.254',
            'vendor' => 'silverpeak',
            'model' => 'EC10104',
            'role' => 'edgeconnect',
            'ssh_username' => 'admin',
            'ssh_credential' => 'demo-password',
            'status' => 'active',
        ]);

        $interfaces = [];
        foreach (['wan0', 'wan1'] as $index => $name) {
            $interfaces[] = DeviceInterface::create([
                'device_id' => $device->id,
                'if_index' => $index + 1,
                'if_name' => $name,
                'status' => 'up',
                'in_octets' => 0,
                'out_octets' => 0,
                'in_discards' => 0,
                'out_discards' => 0,
                'in_discards_delta' => 0,
                'out_discards_delta' => 0,
                'last_polled_at' => now(),
            ]);
        }

        $this->seedInterfaceHistory($interfaces);

        $tunnels = [];
        foreach (['MPLS-to-DC', 'Internet-to-DC'] as $tunnelName) {
            $tunnels[] = Tunnel::create([
                'device_id' => $device->id,
                'tunnel_name' => $tunnelName,
                'status' => 'up',
                'in_discards' => 0,
                'out_discards' => 0,
                'in_discards_delta' => 0,
                'out_discards_delta' => 0,
                'last_checked_at' => now(),
            ]);
        }

        $this->seedTunnelHistory($tunnels);

        DeviceAlarm::create([
            'device_id' => $device->id,
            'alarm_id' => 'ALM-1001',
            'description' => 'High jitter detected on Internet-to-DC tunnel',
            'first_seen_at' => now()->subHours(3),
            'cleared_at' => null,
        ]);

        // Two WAN next-hops, both reachable (as `show system nexthops` reports).
        DeviceNextHop::create(['device_id' => $device->id, 'ip_address' => '64.132.10.1', 'interface' => 'wan0', 'reachability' => 'reachable', 'status' => 'up', 'uptime' => '12d 4h', 'last_checked_at' => now()]);
        DeviceNextHop::create(['device_id' => $device->id, 'ip_address' => '24.104.8.1', 'interface' => 'wan1', 'reachability' => 'reachable', 'status' => 'up', 'uptime' => '9d 1h', 'last_checked_at' => now()]);
    }

    private function seedFortigateFirewall(Site $site): void
    {
        $device = Device::create([
            'site_id' => $site->id,
            'name' => 'demo-fw01',
            'ip_address' => '10.10.3.1',
            'vendor' => 'fortigate',
            'model' => 'FortiGate 100F',
            'role' => 'firewall',
            'snmp_version' => 'v2c',
            'snmp_community' => 'public',
            'status' => 'active',
        ]);

        $interface = DeviceInterface::create([
            'device_id' => $device->id,
            'if_index' => 1,
            'if_name' => 'port1',
            'status' => 'up',
            'in_octets' => 0,
            'out_octets' => 0,
            'in_discards' => 0,
            'out_discards' => 0,
            'in_discards_delta' => 0,
            'out_discards_delta' => 0,
            'last_polled_at' => now(),
        ]);

        $this->seedInterfaceHistory([$interface]);
    }

    /**
     * @param  DeviceInterface[]  $interfaces  All interfaces belonging to the SAME device, so
     *                                          they share one recorded_at per hour-offset — matching
     *                                          how InterfacePoller writes one $now per poll cycle.
     */
    private function seedInterfaceHistory(array $interfaces): void
    {
        for ($hoursAgo = self::HISTORY_HOURS; $hoursAgo >= 0; $hoursAgo--) {
            $recordedAt = now()->subHours($hoursAgo);

            foreach ($interfaces as $interface) {
                InterfaceMetricHistory::create([
                    'device_interface_id' => $interface->id,
                    'recorded_at' => $recordedAt,
                    'status' => 'up',
                    'in_octets_delta' => random_int(50_000, 500_000),
                    'out_octets_delta' => random_int(30_000, 300_000),
                    'in_discards_delta' => random_int(0, 3),
                    'out_discards_delta' => random_int(0, 3),
                ]);
            }
        }
    }

    /**
     * @param  Tunnel[]  $tunnels  All tunnels belonging to the SAME device, so they share one
     *                             recorded_at per hour-offset — matching how SshVerifier::syncTunnels()
     *                             writes one shared timestamp per sync cycle for all of a device's tunnels.
     */
    private function seedTunnelHistory(array $tunnels): void
    {
        for ($hoursAgo = self::HISTORY_HOURS; $hoursAgo >= 0; $hoursAgo--) {
            $recordedAt = now()->subHours($hoursAgo);

            foreach ($tunnels as $tunnel) {
                TunnelMetricHistory::create([
                    'tunnel_id' => $tunnel->id,
                    'recorded_at' => $recordedAt,
                    'status' => 'up',
                    'in_discards_delta' => random_int(0, 5),
                    'out_discards_delta' => random_int(0, 5),
                ]);
            }
        }
    }
}
