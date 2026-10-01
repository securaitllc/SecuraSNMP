<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceAlarm;
use App\Models\Site;
use App\Services\FortiGateAlarmPoller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The three FortiGates in the fleet produced exactly one kind of alarm between
 * them — device-unreachable, from ICMP. Nothing about the firewall itself was
 * watched: a tunnel could drop or the box could sit in conserve mode in silence.
 */
class FortiGateAlarmPollerTest extends TestCase
{
    use RefreshDatabase;

    private const UPTIME = '.1.3.6.1.2.1.1.3.0';

    private const CPU = '.1.3.6.1.4.1.12356.101.4.1.3.0';

    private const MEM = '.1.3.6.1.4.1.12356.101.4.1.4.0';

    private const DISK_USED = '.1.3.6.1.4.1.12356.101.4.1.6.0';

    private const DISK_CAP = '.1.3.6.1.4.1.12356.101.4.1.7.0';

    private const VPN_P2 = '.1.3.6.1.4.1.12356.101.12.2.2.1.3';

    private const VPN_REMOTE = '.1.3.6.1.4.1.12356.101.12.2.2.1.5';

    private const VPN_STATUS = '.1.3.6.1.4.1.12356.101.12.2.2.1.20';

    private const HA_SERIAL = '.1.3.6.1.4.1.12356.101.13.2.1.1.2';

    private const HA_SYNC = '.1.3.6.1.4.1.12356.101.13.2.1.1.12';

    private const HA_MODE = '.1.3.6.1.4.1.12356.101.13.1.1.0';

    private function firewall(): Device
    {
        return Device::factory()->create([
            'site_id' => Site::factory()->create()->id,
            'name' => 'FL0001-HQ-FW',
            'vendor' => 'fortigate',
            'role' => 'firewall',
            'model' => 'FTG401E',
            'status' => 'active',
        ]);
    }

    /** @param array<string, string> $answers */
    private function poller(array $answers): FortiGateAlarmPoller
    {
        return new FortiGateAlarmPoller(
            fn (Device $d, string $oid): string => $answers[$oid] ?? ''
        );
    }

    private function alive(array $answers = []): array
    {
        return array_merge([self::UPTIME => 'DISMAN-EVENT-MIB::sysUpTimeInstance = Timeticks: (98765432) 11 days'], $answers);
    }

    public function test_a_down_ipsec_tunnel_raises_a_critical_alarm(): void
    {
        $fw = $this->firewall();

        $this->poller($this->alive([
            self::VPN_STATUS => "fgVpnTunEntStatus.1 = INTEGER: 2\nfgVpnTunEntStatus.2 = INTEGER: 1",
            self::VPN_P2 => "fgVpnTunEntPhase2Name.1 = STRING: \"HQ-to-SC125\"\nfgVpnTunEntPhase2Name.2 = STRING: \"HQ-to-SC182\"",
            self::VPN_REMOTE => 'fgVpnTunEntRemGwyIp.2 = IpAddress: 64.159.252.45',
        ]))->poll($fw);

        $alarm = DeviceAlarm::where('device_id', $fw->id)->where('alarm_id', 'fg:vpn:HQ-to-SC182')->first();

        $this->assertNotNull($alarm, 'the tunnel reporting down must alarm');
        $this->assertSame('critical', $alarm->severity);
        $this->assertStringContainsString('64.159.252.45', $alarm->description);

        // The tunnel that is up must not.
        $this->assertNull(DeviceAlarm::where('alarm_id', 'fg:vpn:HQ-to-SC125')->first());
    }

    public function test_a_tunnel_that_comes_back_clears(): void
    {
        $fw = $this->firewall();

        $down = $this->alive([
            self::VPN_STATUS => 'fgVpnTunEntStatus.1 = INTEGER: 1',
            self::VPN_P2 => 'fgVpnTunEntPhase2Name.1 = STRING: "HQ-to-SC125"',
        ]);
        $this->poller($down)->poll($fw);
        $this->assertNull(DeviceAlarm::where('alarm_id', 'fg:vpn:HQ-to-SC125')->first()->cleared_at);

        $up = $this->alive([
            self::VPN_STATUS => 'fgVpnTunEntStatus.1 = INTEGER: 2',
            self::VPN_P2 => 'fgVpnTunEntPhase2Name.1 = STRING: "HQ-to-SC125"',
        ]);
        $this->poller($up)->poll($fw);

        $this->assertNotNull(DeviceAlarm::where('alarm_id', 'fg:vpn:HQ-to-SC125')->first()->cleared_at);
    }

    public function test_an_unreachable_firewall_never_false_clears(): void
    {
        // The rule this codebase keeps relearning: a failed poll is not a healthy
        // answer. No sysUpTime reply means change nothing at all.
        $fw = $this->firewall();

        $this->poller($this->alive([
            self::VPN_STATUS => 'fgVpnTunEntStatus.1 = INTEGER: 1',
            self::VPN_P2 => 'fgVpnTunEntPhase2Name.1 = STRING: "HQ-to-SC125"',
        ]))->poll($fw);

        $this->poller([])->poll($fw);   // whole box silent

        $this->assertNull(DeviceAlarm::where('alarm_id', 'fg:vpn:HQ-to-SC125')->first()->cleared_at);
    }

    public function test_an_unanswered_oid_raises_nothing_and_clears_nothing(): void
    {
        // A FortiOS build that does not populate the VPN table must read as
        // "unmeasured", never as "no tunnels are down".
        $fw = $this->firewall();

        $this->poller($this->alive())->poll($fw);

        $this->assertSame(0, DeviceAlarm::where('device_id', $fw->id)->count());
    }

    public function test_memory_at_the_conserve_threshold_warns(): void
    {
        $fw = $this->firewall();

        $this->poller($this->alive([
            self::MEM => 'fgSysMemUsage.0 = Gauge32: 91',
        ]))->poll($fw);

        $alarm = DeviceAlarm::where('alarm_id', 'fg:sys:memory')->first();
        $this->assertNotNull($alarm);
        $this->assertSame('warning', $alarm->severity);
        $this->assertStringContainsString('91%', $alarm->description);
    }

    public function test_memory_below_the_threshold_does_not_alarm(): void
    {
        $fw = $this->firewall();

        $this->poller($this->alive([self::MEM => 'fgSysMemUsage.0 = Gauge32: 62']))->poll($fw);

        $this->assertSame(0, DeviceAlarm::where('device_id', $fw->id)->count());
    }

    public function test_cpu_only_alarms_on_a_sustained_pin(): void
    {
        $fw = $this->firewall();

        $this->poller($this->alive([self::CPU => 'fgSysCpuUsage.0 = Gauge32: 80']))->poll($fw);
        $this->assertNull(DeviceAlarm::where('alarm_id', 'fg:sys:cpu')->first());

        $this->poller($this->alive([self::CPU => 'fgSysCpuUsage.0 = Gauge32: 97']))->poll($fw);
        $this->assertNotNull(DeviceAlarm::where('alarm_id', 'fg:sys:cpu')->first());
    }

    public function test_a_full_log_disk_warns_with_the_figures(): void
    {
        $fw = $this->firewall();

        $this->poller($this->alive([
            self::DISK_USED => 'fgSysDiskUsage.0 = Gauge32: 29500',
            self::DISK_CAP => 'fgSysDiskCapacity.0 = Gauge32: 30000',
        ]))->poll($fw);

        $alarm = DeviceAlarm::where('alarm_id', 'fg:sys:disk')->first();
        $this->assertNotNull($alarm);
        $this->assertStringContainsString('98%', $alarm->description);
    }

    public function test_an_out_of_sync_cluster_member_is_critical(): void
    {
        // An HA pair out of sync still passes traffic, so nothing looks wrong until
        // the failover that does not work.
        $fw = $this->firewall();

        $this->poller($this->alive([
            self::HA_MODE => 'fgHaSystemMode.0 = INTEGER: 3',
            self::HA_SYNC => "fgHaStatsSyncStatus.1 = INTEGER: 1\nfgHaStatsSyncStatus.2 = INTEGER: 0",
            self::HA_SERIAL => "fgHaStatsSerial.1 = STRING: \"FG4H1E5819900638\"\nfgHaStatsSerial.2 = STRING: \"FG4H1E5819900999\"",
        ]))->poll($fw);

        $alarm = DeviceAlarm::where('alarm_id', 'fg:ha:FG4H1E5819900999')->first();
        $this->assertNotNull($alarm);
        $this->assertSame('critical', $alarm->severity);
        $this->assertNull(DeviceAlarm::where('alarm_id', 'fg:ha:FG4H1E5819900638')->first());
    }

    public function test_a_standalone_unit_reporting_its_own_row_raises_no_ha_alarm(): void
    {
        // FGT60E-HQFW00 has `config system ha` with nothing set — no cluster at all —
        // and still answered fgHaStatsSyncStatus.1 = 0, i.e. a cluster of one that is
        // "out of sync". That raised a critical alarm on two firewalls within minutes
        // of the check going live. The mode is what decides, not the table.
        $fw = $this->firewall();

        $this->poller($this->alive([
            self::HA_MODE => 'fgHaSystemMode.0 = INTEGER: 1',
            self::HA_SYNC => 'fgHaStatsSyncStatus.1 = INTEGER: 0',
        ]))->poll($fw);

        $this->assertSame(0, DeviceAlarm::where('alarm_id', 'like', 'fg:ha:%')->count());
    }

    public function test_a_box_that_does_not_answer_the_ha_mode_raises_no_ha_alarm(): void
    {
        // No answer is not evidence of a cluster. Silence beats a false critical.
        $fw = $this->firewall();

        $this->poller($this->alive([
            self::HA_SYNC => "fgHaStatsSyncStatus.1 = INTEGER: 0\nfgHaStatsSyncStatus.2 = INTEGER: 0",
            self::HA_SERIAL => "fgHaStatsSerial.1 = STRING: \"FG1\"\nfgHaStatsSerial.2 = STRING: \"FG2\"",
        ]))->poll($fw);

        $this->assertSame(0, DeviceAlarm::where('alarm_id', 'like', 'fg:ha:%')->count());
    }

    public function test_a_clustered_member_with_no_serial_is_not_reported(): void
    {
        // "HA cluster member out of sync — member 1" tells a NOC nothing it can act
        // on. An unidentifiable row is not a reportable fault.
        $fw = $this->firewall();

        $this->poller($this->alive([
            self::HA_MODE => 'fgHaSystemMode.0 = INTEGER: 3',
            self::HA_SYNC => "fgHaStatsSyncStatus.1 = INTEGER: 0\nfgHaStatsSyncStatus.2 = INTEGER: 1",
        ]))->poll($fw);

        $this->assertSame(0, DeviceAlarm::where('alarm_id', 'like', 'fg:ha:%')->count());
    }

    public function test_a_walk_with_no_ha_table_raises_no_ha_alarm(): void
    {
        $fw = $this->firewall();

        $this->poller($this->alive())->poll($fw);

        $this->assertSame(0, DeviceAlarm::where('alarm_id', 'like', 'fg:ha:%')->count());
    }

    public function test_a_manual_clear_is_not_resurrected_while_the_fault_persists(): void
    {
        $fw = $this->firewall();

        $answers = $this->alive([
            self::VPN_STATUS => 'fgVpnTunEntStatus.1 = INTEGER: 1',
            self::VPN_P2 => 'fgVpnTunEntPhase2Name.1 = STRING: "HQ-to-SC125"',
        ]);

        $this->poller($answers)->poll($fw);

        $alarm = DeviceAlarm::where('alarm_id', 'fg:vpn:HQ-to-SC125')->first();
        $alarm->update(['cleared_at' => now(), 'cleared_manually' => true]);

        $this->poller($answers)->poll($fw);   // tunnel still down

        $this->assertNotNull($alarm->fresh()->cleared_at, 'the NOC cleared it; do not resurrect');
    }

    public function test_a_genuine_flap_reopens_with_a_fresh_ticket(): void
    {
        $fw = $this->firewall();

        $down = $this->alive([
            self::VPN_STATUS => 'fgVpnTunEntStatus.1 = INTEGER: 1',
            self::VPN_P2 => 'fgVpnTunEntPhase2Name.1 = STRING: "HQ-to-SC125"',
        ]);
        $up = $this->alive([
            self::VPN_STATUS => 'fgVpnTunEntStatus.1 = INTEGER: 2',
            self::VPN_P2 => 'fgVpnTunEntPhase2Name.1 = STRING: "HQ-to-SC125"',
        ]);

        $this->poller($down)->poll($fw);
        $first = DeviceAlarm::where('alarm_id', 'fg:vpn:HQ-to-SC125')->first()->ticket_number;

        $this->poller($up)->poll($fw);     // recovers and clears
        $this->poller($down)->poll($fw);   // drops again

        $alarm = DeviceAlarm::where('alarm_id', 'fg:vpn:HQ-to-SC125')->first();
        $this->assertNull($alarm->cleared_at);
        $this->assertNotSame($first, $alarm->ticket_number, 'a recurrence is a new ticket');
    }
}
