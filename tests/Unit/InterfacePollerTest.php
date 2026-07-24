<?php

namespace Tests\Unit;

use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\InterfaceAlert;
use App\Services\InterfacePoller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InterfacePollerTest extends TestCase
{
    use RefreshDatabase;

    private function fakeWalker(array $responses): callable
    {
        return function (Device $device, string $oid) use ($responses) {
            return $responses[$oid] ?? '';
        };
    }

    public function test_computes_interface_utilization_between_polls(): void
    {
        $device = Device::factory()->create(['snmp_version' => 'v2c']);

        // Baseline poll: 0 octets, 1 Mbps link (ifHighSpeed=1 -> 1_000_000 bps).
        $poller1 = new InterfacePoller($this->fakeWalker([
            '.1.3.6.1.2.1.2.2.1.2' => 'ifDescr.1 = STRING: ge-0/0/0',
            '.1.3.6.1.2.1.2.2.1.8' => 'ifOperStatus.1 = INTEGER: up(1)',
            '.1.3.6.1.2.1.2.2.1.10' => 'ifInOctets.1 = Counter32: 0',
            '.1.3.6.1.2.1.2.2.1.16' => 'ifOutOctets.1 = Counter32: 0',
            '.1.3.6.1.2.1.2.2.1.13' => 'ifInDiscards.1 = Counter32: 0',
            '.1.3.6.1.2.1.2.2.1.19' => 'ifOutDiscards.1 = Counter32: 0',
            '.1.3.6.1.2.1.31.1.1.1.15' => 'ifHighSpeed.1 = Gauge32: 1',
        ]));
        $poller1->poll($device);

        // Next poll: +12500 bytes in (= 100000 bits). Over the ~1s floor interval
        // that is 100000 / 1_000_000 = 10% utilisation.
        $poller2 = new InterfacePoller($this->fakeWalker([
            '.1.3.6.1.2.1.2.2.1.2' => 'ifDescr.1 = STRING: ge-0/0/0',
            '.1.3.6.1.2.1.2.2.1.8' => 'ifOperStatus.1 = INTEGER: up(1)',
            '.1.3.6.1.2.1.2.2.1.10' => 'ifInOctets.1 = Counter32: 12500',
            '.1.3.6.1.2.1.2.2.1.16' => 'ifOutOctets.1 = Counter32: 0',
            '.1.3.6.1.2.1.2.2.1.13' => 'ifInDiscards.1 = Counter32: 0',
            '.1.3.6.1.2.1.2.2.1.19' => 'ifOutDiscards.1 = Counter32: 0',
            '.1.3.6.1.2.1.31.1.1.1.15' => 'ifHighSpeed.1 = Gauge32: 1',
        ]));
        $poller2->poll($device);

        $interface = DeviceInterface::where('device_id', $device->id)->where('if_index', 1)->first();
        $this->assertSame(1000000, $interface->speed_bps);
        $this->assertSame(10.0, $interface->in_util_pct);
    }

    public function test_interface_first_seen_down_does_not_alert(): void
    {
        $device = Device::factory()->create(['snmp_version' => 'v2c']);

        // An enabled but unused port (no cable) discovered oper-down on the very
        // first poll must not raise a fresh "interface down" alert.
        $poller = new InterfacePoller($this->fakeWalker([
            '.1.3.6.1.2.1.2.2.1.2' => 'ifDescr.5 = STRING: ge-0/0/5',
            '.1.3.6.1.2.1.2.2.1.7' => 'ifAdminStatus.5 = INTEGER: up(1)',
            '.1.3.6.1.2.1.2.2.1.8' => 'ifOperStatus.5 = INTEGER: down(2)',
        ]));
        $poller->poll($device);

        $interface = DeviceInterface::where('device_id', $device->id)->where('if_index', 5)->first();
        $this->assertSame('down', $interface->status);
        $this->assertSame(0, InterfaceAlert::where('device_interface_id', $interface->id)->count());
    }

    public function test_admin_down_interface_is_not_alerted(): void
    {
        $device = Device::factory()->create(['snmp_version' => 'v2c']);

        // An unused Juniper port: administratively disabled (ifAdminStatus down)
        // and therefore oper-down. This must NOT raise an interface-down alert.
        $poller = new InterfacePoller($this->fakeWalker([
            '.1.3.6.1.2.1.2.2.1.2' => 'ifDescr.9 = STRING: ge-0/0/9',
            '.1.3.6.1.2.1.2.2.1.7' => 'ifAdminStatus.9 = INTEGER: down(2)',
            '.1.3.6.1.2.1.2.2.1.8' => 'ifOperStatus.9 = INTEGER: down(2)',
        ]));
        $poller->poll($device);

        $interface = DeviceInterface::where('device_id', $device->id)->where('if_index', 9)->first();
        $this->assertSame('down', $interface->status);
        $this->assertSame('down', $interface->admin_status);
        $this->assertSame(0, InterfaceAlert::where('device_interface_id', $interface->id)->count());
    }

    public function test_a_logical_subunit_does_not_raise_its_own_alarm(): void
    {
        $device = Device::factory()->create(['snmp_version' => 'v2c']);

        // Physical ge-0/0/11 (ifIndex 11) + its logical unit ge-0/0/11.0 (111),
        // both up first...
        (new InterfacePoller($this->fakeWalker([
            '.1.3.6.1.2.1.2.2.1.2' => "ifDescr.11 = STRING: ge-0/0/11\nifDescr.111 = STRING: ge-0/0/11.0",
            '.1.3.6.1.2.1.2.2.1.7' => "ifAdminStatus.11 = INTEGER: up(1)\nifAdminStatus.111 = INTEGER: up(1)",
            '.1.3.6.1.2.1.2.2.1.8' => "ifOperStatus.11 = INTEGER: up(1)\nifOperStatus.111 = INTEGER: up(1)",
        ])))->poll($device);

        // ...then both flap down. Only the physical port raises an alarm.
        (new InterfacePoller($this->fakeWalker([
            '.1.3.6.1.2.1.2.2.1.2' => "ifDescr.11 = STRING: ge-0/0/11\nifDescr.111 = STRING: ge-0/0/11.0",
            '.1.3.6.1.2.1.2.2.1.7' => "ifAdminStatus.11 = INTEGER: up(1)\nifAdminStatus.111 = INTEGER: up(1)",
            '.1.3.6.1.2.1.2.2.1.8' => "ifOperStatus.11 = INTEGER: down(2)\nifOperStatus.111 = INTEGER: down(2)",
        ])))->poll($device);

        $phys = DeviceInterface::where('device_id', $device->id)->where('if_index', 11)->first();
        $logical = DeviceInterface::where('device_id', $device->id)->where('if_index', 111)->first();
        $this->assertSame(1, InterfaceAlert::where('device_interface_id', $phys->id)->count(), 'physical port alarms');
        $this->assertSame(0, InterfaceAlert::where('device_interface_id', $logical->id)->count(), 'logical sub-unit must not alarm');
    }

    public function test_admin_down_closes_a_pre_existing_open_alert(): void
    {
        $device = Device::factory()->create(['snmp_version' => 'v2c']);

        // Port comes up, then goes oper-down (a real flap) which opens an alert.
        $up = $this->fakeWalker([
            '.1.3.6.1.2.1.2.2.1.2' => 'ifDescr.9 = STRING: ge-0/0/9',
            '.1.3.6.1.2.1.2.2.1.7' => 'ifAdminStatus.9 = INTEGER: up(1)',
            '.1.3.6.1.2.1.2.2.1.8' => 'ifOperStatus.9 = INTEGER: up(1)',
        ]);
        (new InterfacePoller($up))->poll($device);

        $poller1 = new InterfacePoller($this->fakeWalker([
            '.1.3.6.1.2.1.2.2.1.2' => 'ifDescr.9 = STRING: ge-0/0/9',
            '.1.3.6.1.2.1.2.2.1.7' => 'ifAdminStatus.9 = INTEGER: up(1)',
            '.1.3.6.1.2.1.2.2.1.8' => 'ifOperStatus.9 = INTEGER: down(2)',
        ]));
        $poller1->poll($device);
        $interface = DeviceInterface::where('device_id', $device->id)->where('if_index', 9)->first();
        $this->assertSame(1, InterfaceAlert::where('device_interface_id', $interface->id)->whereNull('ended_at')->count());

        $poller2 = new InterfacePoller($this->fakeWalker([
            '.1.3.6.1.2.1.2.2.1.2' => 'ifDescr.9 = STRING: ge-0/0/9',
            '.1.3.6.1.2.1.2.2.1.7' => 'ifAdminStatus.9 = INTEGER: down(2)',
            '.1.3.6.1.2.1.2.2.1.8' => 'ifOperStatus.9 = INTEGER: down(2)',
        ]));
        $poller2->poll($device);

        $this->assertSame(0, InterfaceAlert::where('device_interface_id', $interface->id)->whereNull('ended_at')->count());
    }

    public function test_discovering_an_up_interface_creates_no_alert(): void
    {
        $device = Device::factory()->create(['snmp_version' => 'v2c']);

        $poller = new InterfacePoller($this->fakeWalker([
            '.1.3.6.1.2.1.2.2.1.2' => 'IF-MIB::ifDescr.1 = STRING: ge-0/0/0',
            '.1.3.6.1.2.1.2.2.1.8' => 'IF-MIB::ifOperStatus.1 = INTEGER: up(1)',
            '.1.3.6.1.2.1.2.2.1.10' => 'IF-MIB::ifInOctets.1 = Counter32: 1000',
            '.1.3.6.1.2.1.2.2.1.16' => 'IF-MIB::ifOutOctets.1 = Counter32: 2000',
            '.1.3.6.1.2.1.2.2.1.13' => 'IF-MIB::ifInDiscards.1 = Counter32: 0',
            '.1.3.6.1.2.1.2.2.1.19' => 'IF-MIB::ifOutDiscards.1 = Counter32: 0',
        ]));

        $poller->poll($device);

        $interface = DeviceInterface::where('device_id', $device->id)->where('if_index', 1)->first();
        $this->assertNotNull($interface);
        $this->assertSame('up', $interface->status);
        $this->assertSame('ge-0/0/0', $interface->if_name);
        $this->assertSame(0, InterfaceAlert::count());
    }

    public function test_an_up_to_down_transition_opens_an_alert(): void
    {
        $device = Device::factory()->create(['snmp_version' => 'v2c']);

        $up = $this->fakeWalker([
            '.1.3.6.1.2.1.2.2.1.2' => 'IF-MIB::ifDescr.1 = STRING: ge-0/0/0',
            '.1.3.6.1.2.1.2.2.1.8' => 'IF-MIB::ifOperStatus.1 = INTEGER: up(1)',
            '.1.3.6.1.2.1.2.2.1.10' => 'IF-MIB::ifInOctets.1 = Counter32: 0',
            '.1.3.6.1.2.1.2.2.1.16' => 'IF-MIB::ifOutOctets.1 = Counter32: 0',
            '.1.3.6.1.2.1.2.2.1.13' => 'IF-MIB::ifInDiscards.1 = Counter32: 0',
            '.1.3.6.1.2.1.2.2.1.19' => 'IF-MIB::ifOutDiscards.1 = Counter32: 0',
        ]);
        (new InterfacePoller($up))->poll($device);

        // Now it goes down — a genuine flap, which alerts.
        $down = $this->fakeWalker([
            '.1.3.6.1.2.1.2.2.1.2' => 'IF-MIB::ifDescr.1 = STRING: ge-0/0/0',
            '.1.3.6.1.2.1.2.2.1.8' => 'IF-MIB::ifOperStatus.1 = INTEGER: down(2)',
            '.1.3.6.1.2.1.2.2.1.10' => 'IF-MIB::ifInOctets.1 = Counter32: 0',
            '.1.3.6.1.2.1.2.2.1.16' => 'IF-MIB::ifOutOctets.1 = Counter32: 0',
            '.1.3.6.1.2.1.2.2.1.13' => 'IF-MIB::ifInDiscards.1 = Counter32: 0',
            '.1.3.6.1.2.1.2.2.1.19' => 'IF-MIB::ifOutDiscards.1 = Counter32: 0',
        ]);
        (new InterfacePoller($down))->poll($device);

        $interface = DeviceInterface::where('device_id', $device->id)->where('if_index', 1)->first();
        $this->assertSame('down', $interface->status);
        $this->assertDatabaseHas('interface_alerts', [
            'device_interface_id' => $interface->id,
            'ended_at' => null,
        ]);
    }

    public function test_snmp_does_not_overwrite_a_hand_entered_serial(): void
    {
        $device = Device::factory()->create(['snmp_version' => 'v2c', 'vendor' => 'silverpeak', 'serial_number' => 'MANUAL-SN']);

        (new InterfacePoller($this->fakeWalker([
            '.1.3.6.1.2.1.2.2.1.2' => 'ifDescr.1 = STRING: mgmt0',
            '.1.3.6.1.2.1.2.2.1.8' => 'ifOperStatus.1 = INTEGER: up(1)',
            '.1.3.6.1.4.1.23867.3.1.1.1.6.0' => 'iso...6.0 = STRING: "00-1B-BC-36-9E-88"',
        ])))->poll($device);

        $this->assertSame('MANUAL-SN', $device->fresh()->serial_number);   // not clobbered
    }

    public function test_silverpeak_serial_is_stored_without_separators(): void
    {
        $device = Device::factory()->create(['snmp_version' => 'v2c', 'vendor' => 'silverpeak', 'serial_number' => null]);

        (new InterfacePoller($this->fakeWalker([
            '.1.3.6.1.2.1.2.2.1.2' => 'ifDescr.1 = STRING: mgmt0',
            '.1.3.6.1.2.1.2.2.1.8' => 'ifOperStatus.1 = INTEGER: up(1)',
            '.1.3.6.1.4.1.23867.3.1.1.1.6.0' => 'iso...6.0 = STRING: "00-1B-BC-2F-58-30"',
        ])))->poll($device);

        $this->assertSame('001BBC2F5830', $device->fresh()->serial_number);   // matches appliance GUI
    }

    public function test_an_uplink_port_down_is_critical_a_regular_port_is_warning(): void
    {
        $device = Device::factory()->create(['snmp_version' => 'v2c']);
        // ge-0/0/0 uplinks to another switch (LLDP); ge-0/0/1 is a plain access port.
        \App\Models\LldpNeighbor::create([
            'device_id' => $device->id, 'local_port' => 'ge-0/0/0',
            'remote_sysname' => 'CORE-SW01', 'neighbor_type' => 'switch', 'last_seen_at' => now(),
        ]);

        $ports = fn (string $s0, string $s1) => $this->fakeWalker([
            '.1.3.6.1.2.1.2.2.1.2' => "IF-MIB::ifDescr.1 = STRING: ge-0/0/0\nIF-MIB::ifDescr.2 = STRING: ge-0/0/1",
            '.1.3.6.1.2.1.2.2.1.8' => "IF-MIB::ifOperStatus.1 = INTEGER: {$s0}\nIF-MIB::ifOperStatus.2 = INTEGER: {$s1}",
        ]);
        (new InterfacePoller($ports('up(1)', 'up(1)')))->poll($device);
        (new InterfacePoller($ports('down(2)', 'down(2)')))->poll($device);

        $uplink = DeviceInterface::where('device_id', $device->id)->where('if_name', 'ge-0/0/0')->first();
        $access = DeviceInterface::where('device_id', $device->id)->where('if_name', 'ge-0/0/1')->first();

        $this->assertSame('critical', InterfaceAlert::where('device_interface_id', $uplink->id)->value('severity'));
        $this->assertSame('warning', InterfaceAlert::where('device_interface_id', $access->id)->value('severity'));
    }

    public function test_transition_to_up_closes_the_open_alert_and_computes_discard_deltas(): void
    {
        $device = Device::factory()->create(['snmp_version' => 'v2c']);
        $interface = DeviceInterface::factory()->create([
            'device_id' => $device->id,
            'if_index' => 1,
            'status' => 'down',
            'in_discards' => 5,
            'out_discards' => 5,
        ]);
        $alert = InterfaceAlert::factory()->create(['device_interface_id' => $interface->id, 'ended_at' => null]);

        $poller = new InterfacePoller($this->fakeWalker([
            '.1.3.6.1.2.1.2.2.1.2' => 'IF-MIB::ifDescr.1 = STRING: ge-0/0/0',
            '.1.3.6.1.2.1.2.2.1.8' => 'IF-MIB::ifOperStatus.1 = INTEGER: up(1)',
            '.1.3.6.1.2.1.2.2.1.10' => 'IF-MIB::ifInOctets.1 = Counter32: 1000',
            '.1.3.6.1.2.1.2.2.1.16' => 'IF-MIB::ifOutOctets.1 = Counter32: 2000',
            '.1.3.6.1.2.1.2.2.1.13' => 'IF-MIB::ifInDiscards.1 = Counter32: 8',
            '.1.3.6.1.2.1.2.2.1.19' => 'IF-MIB::ifOutDiscards.1 = Counter32: 9',
        ]));

        $poller->poll($device);

        $interface->refresh();
        $alert->refresh();
        $this->assertSame('up', $interface->status);
        $this->assertNotNull($alert->ended_at);
        $this->assertSame(3, $interface->in_discards_delta);
        $this->assertSame(4, $interface->out_discards_delta);
    }

    public function test_a_normal_poll_writes_an_interface_metric_history_row(): void
    {
        $device = Device::factory()->create(['snmp_version' => 'v2c']);
        $interface = DeviceInterface::factory()->create([
            'device_id' => $device->id,
            'if_index' => 1,
            'status' => 'up',
            'in_octets' => 1000,
            'out_octets' => 2000,
            'in_discards' => 5,
            'out_discards' => 5,
        ]);

        $poller = new InterfacePoller($this->fakeWalker([
            '.1.3.6.1.2.1.2.2.1.2' => 'IF-MIB::ifDescr.1 = STRING: ge-0/0/0',
            '.1.3.6.1.2.1.2.2.1.8' => 'IF-MIB::ifOperStatus.1 = INTEGER: up(1)',
            '.1.3.6.1.2.1.2.2.1.10' => 'IF-MIB::ifInOctets.1 = Counter32: 1500',
            '.1.3.6.1.2.1.2.2.1.16' => 'IF-MIB::ifOutOctets.1 = Counter32: 2800',
            '.1.3.6.1.2.1.2.2.1.13' => 'IF-MIB::ifInDiscards.1 = Counter32: 8',
            '.1.3.6.1.2.1.2.2.1.19' => 'IF-MIB::ifOutDiscards.1 = Counter32: 9',
        ]));

        $poller->poll($device);

        $this->assertDatabaseHas('interface_metric_history', [
            'device_interface_id' => $interface->id,
            'status' => 'up',
            'in_octets_delta' => 500,
            'out_octets_delta' => 800,
            'in_discards_delta' => 3,
            'out_discards_delta' => 4,
        ]);
    }

    public function test_an_interface_skipped_this_cycle_gets_no_history_row(): void
    {
        $device = Device::factory()->create(['snmp_version' => 'v2c']);
        $interface = DeviceInterface::factory()->create([
            'device_id' => $device->id,
            'if_index' => 1,
            'status' => 'up',
        ]);

        $poller = new InterfacePoller($this->fakeWalker([
            '.1.3.6.1.2.1.2.2.1.2' => 'IF-MIB::ifDescr.1 = STRING: ge-0/0/0',
            // ifOperStatus response is empty for ifIndex 1 — the existing
            // partial-response protection skips this interface entirely.
            '.1.3.6.1.2.1.2.2.1.8' => '',
            '.1.3.6.1.2.1.2.2.1.10' => 'IF-MIB::ifInOctets.1 = Counter32: 999',
            '.1.3.6.1.2.1.2.2.1.16' => 'IF-MIB::ifOutOctets.1 = Counter32: 0',
            '.1.3.6.1.2.1.2.2.1.13' => 'IF-MIB::ifInDiscards.1 = Counter32: 0',
            '.1.3.6.1.2.1.2.2.1.19' => 'IF-MIB::ifOutDiscards.1 = Counter32: 0',
        ]));

        $poller->poll($device);

        $this->assertSame(0, \App\Models\InterfaceMetricHistory::where('device_interface_id', $interface->id)->count());
    }

    public function test_staying_down_does_not_open_a_duplicate_alert(): void
    {
        $device = Device::factory()->create(['snmp_version' => 'v2c']);
        $interface = DeviceInterface::factory()->create([
            'device_id' => $device->id,
            'if_index' => 1,
            'status' => 'down',
        ]);
        InterfaceAlert::factory()->create(['device_interface_id' => $interface->id, 'ended_at' => null]);

        $poller = new InterfacePoller($this->fakeWalker([
            '.1.3.6.1.2.1.2.2.1.2' => 'IF-MIB::ifDescr.1 = STRING: ge-0/0/0',
            '.1.3.6.1.2.1.2.2.1.8' => 'IF-MIB::ifOperStatus.1 = INTEGER: down(2)',
            '.1.3.6.1.2.1.2.2.1.10' => 'IF-MIB::ifInOctets.1 = Counter32: 0',
            '.1.3.6.1.2.1.2.2.1.16' => 'IF-MIB::ifOutOctets.1 = Counter32: 0',
            '.1.3.6.1.2.1.2.2.1.13' => 'IF-MIB::ifInDiscards.1 = Counter32: 0',
            '.1.3.6.1.2.1.2.2.1.19' => 'IF-MIB::ifOutDiscards.1 = Counter32: 0',
        ]));

        $poller->poll($device);

        $this->assertSame(1, InterfaceAlert::where('device_interface_id', $interface->id)->count());
    }

    public function test_pollall_isolates_a_failing_device_from_the_rest(): void
    {
        $goodDevice = Device::factory()->create(['snmp_version' => 'v2c', 'ip_address' => '10.0.0.1']);
        $badDevice = Device::factory()->create(['snmp_version' => 'v2c', 'ip_address' => '10.0.0.2']);

        $poller = new InterfacePoller(function (Device $device, string $oid) {
            if ($device->ip_address === '10.0.0.2') {
                throw new \RuntimeException('snmp timeout');
            }

            return match ($oid) {
                '.1.3.6.1.2.1.2.2.1.2' => 'IF-MIB::ifDescr.1 = STRING: ge-0/0/0',
                '.1.3.6.1.2.1.2.2.1.8' => 'IF-MIB::ifOperStatus.1 = INTEGER: up(1)',
                default => 'IF-MIB::ifValue.1 = Counter32: 0',
            };
        });

        $poller->pollAll();

        $this->assertSame(1, DeviceInterface::where('device_id', $goodDevice->id)->count());
        $this->assertSame(0, DeviceInterface::where('device_id', $badDevice->id)->count());
    }

    public function test_raw_numeric_oper_status_1_is_classified_as_up(): void
    {
        $device = Device::factory()->create(['snmp_version' => 'v2c']);

        $poller = new InterfacePoller($this->fakeWalker([
            '.1.3.6.1.2.1.2.2.1.2' => 'IF-MIB::ifDescr.1 = STRING: ge-0/0/0',
            '.1.3.6.1.2.1.2.2.1.8' => 'IF-MIB::ifOperStatus.1 = INTEGER: 1',
            '.1.3.6.1.2.1.2.2.1.10' => 'IF-MIB::ifInOctets.1 = Counter32: 1000',
            '.1.3.6.1.2.1.2.2.1.16' => 'IF-MIB::ifOutOctets.1 = Counter32: 2000',
            '.1.3.6.1.2.1.2.2.1.13' => 'IF-MIB::ifInDiscards.1 = Counter32: 0',
            '.1.3.6.1.2.1.2.2.1.19' => 'IF-MIB::ifOutDiscards.1 = Counter32: 0',
        ]));

        $poller->poll($device);

        $interface = DeviceInterface::where('device_id', $device->id)->where('if_index', 1)->first();
        $this->assertSame('up', $interface->status);
        $this->assertSame(0, InterfaceAlert::count());
    }

    public function test_raw_numeric_oper_status_2_is_classified_as_down(): void
    {
        $device = Device::factory()->create(['snmp_version' => 'v2c']);

        $poller = new InterfacePoller($this->fakeWalker([
            '.1.3.6.1.2.1.2.2.1.2' => 'IF-MIB::ifDescr.1 = STRING: ge-0/0/0',
            '.1.3.6.1.2.1.2.2.1.8' => 'IF-MIB::ifOperStatus.1 = INTEGER: 2',
            '.1.3.6.1.2.1.2.2.1.10' => 'IF-MIB::ifInOctets.1 = Counter32: 0',
            '.1.3.6.1.2.1.2.2.1.16' => 'IF-MIB::ifOutOctets.1 = Counter32: 0',
            '.1.3.6.1.2.1.2.2.1.13' => 'IF-MIB::ifInDiscards.1 = Counter32: 0',
            '.1.3.6.1.2.1.2.2.1.19' => 'IF-MIB::ifOutDiscards.1 = Counter32: 0',
        ]));

        $poller->poll($device);

        // Numeric "2" (no MIB names) resolves to the down state.
        $interface = DeviceInterface::where('device_id', $device->id)->where('if_index', 1)->first();
        $this->assertSame('down', $interface->status);
    }

    public function test_parseWalk_strips_quotes_from_real_snmpwalk_string_values(): void
    {
        $device = Device::factory()->create(['snmp_version' => 'v2c']);

        $poller = new InterfacePoller($this->fakeWalker([
            '.1.3.6.1.2.1.2.2.1.2' => 'iso.3.6.1.2.1.2.2.1.2.1 = STRING: "lo"' . "\n" .
                                      'iso.3.6.1.2.1.2.2.1.2.2 = STRING: "eth0"',
            '.1.3.6.1.2.1.2.2.1.8' => 'iso.3.6.1.2.1.2.2.1.8.1 = INTEGER: up(1)' . "\n" .
                                      'iso.3.6.1.2.1.2.2.1.8.2 = INTEGER: up(1)',
            '.1.3.6.1.2.1.2.2.1.10' => 'iso.3.6.1.2.1.2.2.1.10.1 = Counter32: 1000' . "\n" .
                                       'iso.3.6.1.2.1.2.2.1.10.2 = Counter32: 2000',
            '.1.3.6.1.2.1.2.2.1.16' => 'iso.3.6.1.2.1.2.2.1.16.1 = Counter32: 3000' . "\n" .
                                       'iso.3.6.1.2.1.2.2.1.16.2 = Counter32: 4000',
            '.1.3.6.1.2.1.2.2.1.13' => 'iso.3.6.1.2.1.2.2.1.13.1 = Counter32: 0' . "\n" .
                                       'iso.3.6.1.2.1.2.2.1.13.2 = Counter32: 0',
            '.1.3.6.1.2.1.2.2.1.19' => 'iso.3.6.1.2.1.2.2.1.19.1 = Counter32: 0' . "\n" .
                                       'iso.3.6.1.2.1.2.2.1.19.2 = Counter32: 0',
        ]));

        $poller->poll($device);

        $interface1 = DeviceInterface::where('device_id', $device->id)->where('if_index', 1)->first();
        $interface2 = DeviceInterface::where('device_id', $device->id)->where('if_index', 2)->first();

        $this->assertSame('lo', $interface1->if_name);
        $this->assertSame('eth0', $interface2->if_name);
    }

    public function test_pollall_does_not_flip_a_previously_up_interface_on_failure(): void
    {
        $device = Device::factory()->create(['snmp_version' => 'v2c', 'ip_address' => '10.0.0.1']);
        $interface = DeviceInterface::factory()->create([
            'device_id' => $device->id,
            'if_index' => 1,
            'status' => 'up',
        ]);

        $poller = new InterfacePoller(function (Device $dev, string $oid) use ($device) {
            if ($dev->id === $device->id) {
                throw new \RuntimeException('snmp timeout');
            }
            return '';
        });

        $poller->pollAll();

        $interface->refresh();
        $this->assertSame('up', $interface->status);
        $this->assertSame(0, InterfaceAlert::count());
    }

    public function test_missing_operstatus_for_a_discovered_interface_skips_it_without_flipping_status(): void
    {
        $device = Device::factory()->create(['snmp_version' => 'v2c']);
        $interface = DeviceInterface::factory()->create([
            'device_id' => $device->id,
            'if_index' => 1,
            'status' => 'up',
            'in_octets' => 500,
        ]);

        $poller = new InterfacePoller($this->fakeWalker([
            '.1.3.6.1.2.1.2.2.1.2' => 'IF-MIB::ifDescr.1 = STRING: ge-0/0/0',
            // ifOperStatus response is empty/truncated for ifIndex 1 — simulates
            // a partial SNMP response, not a real device state change.
            '.1.3.6.1.2.1.2.2.1.8' => '',
            '.1.3.6.1.2.1.2.2.1.10' => 'IF-MIB::ifInOctets.1 = Counter32: 999',
            '.1.3.6.1.2.1.2.2.1.16' => 'IF-MIB::ifOutOctets.1 = Counter32: 0',
            '.1.3.6.1.2.1.2.2.1.13' => 'IF-MIB::ifInDiscards.1 = Counter32: 0',
            '.1.3.6.1.2.1.2.2.1.19' => 'IF-MIB::ifOutDiscards.1 = Counter32: 0',
        ]));

        $poller->poll($device);

        $interface->refresh();
        $this->assertSame('up', $interface->status);
        $this->assertSame(500, $interface->in_octets);
        $this->assertSame(0, InterfaceAlert::count());
    }

    public function test_missing_counter_value_preserves_previous_counter_instead_of_resetting_to_zero(): void
    {
        $device = Device::factory()->create(['snmp_version' => 'v2c']);
        $interface = DeviceInterface::factory()->create([
            'device_id' => $device->id,
            'if_index' => 1,
            'status' => 'up',
            'in_discards' => 40,
            'out_discards' => 40,
        ]);

        $poller = new InterfacePoller($this->fakeWalker([
            '.1.3.6.1.2.1.2.2.1.2' => 'IF-MIB::ifDescr.1 = STRING: ge-0/0/0',
            '.1.3.6.1.2.1.2.2.1.8' => 'IF-MIB::ifOperStatus.1 = INTEGER: up(1)',
            '.1.3.6.1.2.1.2.2.1.10' => 'IF-MIB::ifInOctets.1 = Counter32: 1000',
            '.1.3.6.1.2.1.2.2.1.16' => 'IF-MIB::ifOutOctets.1 = Counter32: 2000',
            // ifInDiscards response is missing ifIndex 1 entirely (partial walk).
            '.1.3.6.1.2.1.2.2.1.13' => '',
            '.1.3.6.1.2.1.2.2.1.19' => 'IF-MIB::ifOutDiscards.1 = Counter32: 45',
        ]));

        $poller->poll($device);

        $interface->refresh();
        $this->assertSame(40, $interface->in_discards);
        $this->assertSame(0, $interface->in_discards_delta);
        $this->assertSame(45, $interface->out_discards);
        $this->assertSame(5, $interface->out_discards_delta);
    }

    public function test_pollall_skips_a_v2c_device_without_a_community_string(): void
    {
        $device = Device::factory()->create(['snmp_version' => 'v2c', 'snmp_community' => null]);

        $walkerInvoked = false;
        $poller = new InterfacePoller(function (Device $d, string $oid) use (&$walkerInvoked) {
            $walkerInvoked = true;

            return '';
        });

        $poller->pollAll();

        $this->assertFalse($walkerInvoked, 'Walker should never be invoked for a device missing SNMP credentials.');
        $this->assertSame(0, DeviceInterface::where('device_id', $device->id)->count());
    }

    public function test_pollall_skips_a_v3_device_missing_any_required_credential(): void
    {
        $device = Device::factory()->create([
            'snmp_version' => 'v3',
            'snmp_community' => null,
            'snmp_v3_username' => 'operator',
            'snmp_v3_auth_key' => 'authkey',
            'snmp_v3_priv_key' => null,
        ]);

        $walkerInvoked = false;
        $poller = new InterfacePoller(function (Device $d, string $oid) use (&$walkerInvoked) {
            $walkerInvoked = true;

            return '';
        });

        $poller->pollAll();

        $this->assertFalse($walkerInvoked, 'Walker should never be invoked for a device missing SNMP credentials.');
        $this->assertSame(0, DeviceInterface::where('device_id', $device->id)->count());
    }

    public function test_pollall_polls_a_v3_device_with_all_required_credentials(): void
    {
        $device = Device::factory()->create([
            'snmp_version' => 'v3',
            'snmp_community' => null,
            'snmp_v3_username' => 'operator',
            'snmp_v3_auth_key' => 'authkey',
            'snmp_v3_priv_key' => 'privkey',
        ]);

        $poller = new InterfacePoller($this->fakeWalker([
            '.1.3.6.1.2.1.2.2.1.2' => 'IF-MIB::ifDescr.1 = STRING: ge-0/0/0',
            '.1.3.6.1.2.1.2.2.1.8' => 'IF-MIB::ifOperStatus.1 = INTEGER: up(1)',
            '.1.3.6.1.2.1.2.2.1.10' => 'IF-MIB::ifInOctets.1 = Counter32: 0',
            '.1.3.6.1.2.1.2.2.1.16' => 'IF-MIB::ifOutOctets.1 = Counter32: 0',
            '.1.3.6.1.2.1.2.2.1.13' => 'IF-MIB::ifInDiscards.1 = Counter32: 0',
            '.1.3.6.1.2.1.2.2.1.19' => 'IF-MIB::ifOutDiscards.1 = Counter32: 0',
        ]));

        $poller->pollAll();

        $this->assertSame(1, DeviceInterface::where('device_id', $device->id)->count());
    }

    public function test_multiple_interfaces_in_one_poll_cycle_share_the_same_recorded_at_timestamp(): void
    {
        $device = Device::factory()->create(['snmp_version' => 'v2c']);

        $poller = new InterfacePoller($this->fakeWalker([
            '.1.3.6.1.2.1.2.2.1.2' => 'IF-MIB::ifDescr.1 = STRING: ge-0/0/0' . "\n" .
                                      'IF-MIB::ifDescr.2 = STRING: ge-0/0/1',
            '.1.3.6.1.2.1.2.2.1.8' => 'IF-MIB::ifOperStatus.1 = INTEGER: up(1)' . "\n" .
                                      'IF-MIB::ifOperStatus.2 = INTEGER: up(1)',
            '.1.3.6.1.2.1.2.2.1.10' => 'IF-MIB::ifInOctets.1 = Counter32: 1000' . "\n" .
                                       'IF-MIB::ifInOctets.2 = Counter32: 2000',
            '.1.3.6.1.2.1.2.2.1.16' => 'IF-MIB::ifOutOctets.1 = Counter32: 3000' . "\n" .
                                       'IF-MIB::ifOutOctets.2 = Counter32: 4000',
            '.1.3.6.1.2.1.2.2.1.13' => 'IF-MIB::ifInDiscards.1 = Counter32: 0' . "\n" .
                                       'IF-MIB::ifInDiscards.2 = Counter32: 0',
            '.1.3.6.1.2.1.2.2.1.19' => 'IF-MIB::ifOutDiscards.1 = Counter32: 0' . "\n" .
                                       'IF-MIB::ifOutDiscards.2 = Counter32: 0',
        ]));

        $poller->poll($device);

        $interface1 = DeviceInterface::where('device_id', $device->id)->where('if_index', 1)->first();
        $interface2 = DeviceInterface::where('device_id', $device->id)->where('if_index', 2)->first();

        $history1 = \App\Models\InterfaceMetricHistory::where('device_interface_id', $interface1->id)->first();
        $history2 = \App\Models\InterfaceMetricHistory::where('device_interface_id', $interface2->id)->first();

        $this->assertNotNull($history1);
        $this->assertNotNull($history2);
        $this->assertSame($history1->recorded_at->toDateTimeString(), $history2->recorded_at->toDateTimeString());
    }
    public function test_snmp_vlan_walk_records_active_vlans(): void
    {
        $device = Device::factory()->create(['snmp_version' => 'v2c']);

        $poller = new InterfacePoller($this->fakeWalker([
            '.1.3.6.1.2.1.2.2.1.2' => 'IF-MIB::ifDescr.1 = STRING: ge-0/0/0',
            '.1.3.6.1.2.1.2.2.1.8' => 'IF-MIB::ifOperStatus.1 = INTEGER: up(1)',
            '.1.3.6.1.2.1.2.2.1.10' => 'IF-MIB::ifInOctets.1 = Counter32: 0',
            '.1.3.6.1.2.1.2.2.1.16' => 'IF-MIB::ifOutOctets.1 = Counter32: 0',
            '.1.3.6.1.2.1.2.2.1.13' => 'IF-MIB::ifInDiscards.1 = Counter32: 0',
            '.1.3.6.1.2.1.2.2.1.19' => 'IF-MIB::ifOutDiscards.1 = Counter32: 0',
            '.1.3.6.1.2.1.17.7.1.4.3.1.1' => 'Q-BRIDGE-MIB::dot1qVlanStaticName.10 = STRING: "DATA"' . "\n" .
                                             'Q-BRIDGE-MIB::dot1qVlanStaticName.20 = STRING: "VOICE"',
        ]));

        $poller->poll($device);

        $this->assertDatabaseHas('device_vlans', ['device_id' => $device->id, 'vlan_id' => 10, 'name' => 'DATA', 'status' => 'active']);
        $this->assertDatabaseHas('device_vlans', ['device_id' => $device->id, 'vlan_id' => 20, 'name' => 'VOICE', 'status' => 'active']);
    }

    public function test_a_vlan_no_longer_reported_is_marked_inactive(): void
    {
        $device = Device::factory()->create(['snmp_version' => 'v2c']);
        \App\Models\DeviceVlan::create(['device_id' => $device->id, 'vlan_id' => 99, 'name' => 'OLD', 'status' => 'active', 'last_seen_at' => now()]);

        $poller = new InterfacePoller($this->fakeWalker([
            '.1.3.6.1.2.1.2.2.1.2' => 'IF-MIB::ifDescr.1 = STRING: ge-0/0/0',
            '.1.3.6.1.2.1.2.2.1.8' => 'IF-MIB::ifOperStatus.1 = INTEGER: up(1)',
            '.1.3.6.1.2.1.2.2.1.10' => 'IF-MIB::ifInOctets.1 = Counter32: 0',
            '.1.3.6.1.2.1.2.2.1.16' => 'IF-MIB::ifOutOctets.1 = Counter32: 0',
            '.1.3.6.1.2.1.2.2.1.13' => 'IF-MIB::ifInDiscards.1 = Counter32: 0',
            '.1.3.6.1.2.1.2.2.1.19' => 'IF-MIB::ifOutDiscards.1 = Counter32: 0',
            '.1.3.6.1.2.1.17.7.1.4.3.1.1' => 'Q-BRIDGE-MIB::dot1qVlanStaticName.10 = STRING: "DATA"',
        ]));

        $poller->poll($device);

        $this->assertDatabaseHas('device_vlans', ['device_id' => $device->id, 'vlan_id' => 99, 'status' => 'inactive']);
        $this->assertDatabaseHas('device_vlans', ['device_id' => $device->id, 'vlan_id' => 10, 'status' => 'active']);
    }

    public function test_an_empty_vlan_walk_leaves_existing_vlans_untouched(): void
    {
        $device = Device::factory()->create(['snmp_version' => 'v2c']);
        \App\Models\DeviceVlan::create(['device_id' => $device->id, 'vlan_id' => 10, 'name' => 'DATA', 'status' => 'active', 'last_seen_at' => now()]);

        $poller = new InterfacePoller($this->fakeWalker([
            '.1.3.6.1.2.1.2.2.1.2' => 'IF-MIB::ifDescr.1 = STRING: ge-0/0/0',
            '.1.3.6.1.2.1.2.2.1.8' => 'IF-MIB::ifOperStatus.1 = INTEGER: up(1)',
            '.1.3.6.1.2.1.2.2.1.10' => 'IF-MIB::ifInOctets.1 = Counter32: 0',
            '.1.3.6.1.2.1.2.2.1.16' => 'IF-MIB::ifOutOctets.1 = Counter32: 0',
            '.1.3.6.1.2.1.2.2.1.13' => 'IF-MIB::ifInDiscards.1 = Counter32: 0',
            '.1.3.6.1.2.1.2.2.1.19' => 'IF-MIB::ifOutDiscards.1 = Counter32: 0',
            // no VLAN OID provided -> empty walk
        ]));

        $poller->poll($device);

        $this->assertDatabaseHas('device_vlans', ['device_id' => $device->id, 'vlan_id' => 10, 'status' => 'active']);
        $this->assertSame(1, \App\Models\DeviceVlan::where('device_id', $device->id)->count());
    }

    public function test_snmp_serial_walk_updates_the_device_serial_number(): void
    {
        $device = Device::factory()->create(['snmp_version' => 'v2c', 'vendor' => 'juniper', 'serial_number' => null]);

        $poller = new InterfacePoller($this->fakeWalker([
            '.1.3.6.1.2.1.2.2.1.2' => 'IF-MIB::ifDescr.1 = STRING: ge-0/0/0',
            '.1.3.6.1.2.1.2.2.1.8' => 'IF-MIB::ifOperStatus.1 = INTEGER: up(1)',
            '.1.3.6.1.2.1.2.2.1.10' => 'IF-MIB::ifInOctets.1 = Counter32: 0',
            '.1.3.6.1.2.1.2.2.1.16' => 'IF-MIB::ifOutOctets.1 = Counter32: 0',
            '.1.3.6.1.2.1.2.2.1.13' => 'IF-MIB::ifInDiscards.1 = Counter32: 0',
            '.1.3.6.1.2.1.2.2.1.19' => 'IF-MIB::ifOutDiscards.1 = Counter32: 0',
            '.1.3.6.1.2.1.47.1.1.1.1.11' => 'ENTITY-MIB::entPhysicalSerialNum.1 = STRING: "JN1234ABCD"',
        ]));

        $poller->poll($device);

        $device->refresh();
        $this->assertSame('JN1234ABCD', $device->serial_number);
    }

    public function test_silverpeak_serial_comes_from_the_vendor_oid(): void
    {
        $device = Device::factory()->create(['snmp_version' => 'v2c', 'vendor' => 'silverpeak', 'serial_number' => null]);

        $poller = new InterfacePoller($this->fakeWalker([
            '.1.3.6.1.2.1.2.2.1.2' => 'IF-MIB::ifDescr.1 = STRING: wan0',
            '.1.3.6.1.2.1.2.2.1.8' => 'IF-MIB::ifOperStatus.1 = INTEGER: up(1)',
            '.1.3.6.1.2.1.2.2.1.10' => 'IF-MIB::ifInOctets.1 = Counter32: 0',
            '.1.3.6.1.2.1.2.2.1.16' => 'IF-MIB::ifOutOctets.1 = Counter32: 0',
            '.1.3.6.1.2.1.2.2.1.13' => 'IF-MIB::ifInDiscards.1 = Counter32: 0',
            '.1.3.6.1.2.1.2.2.1.19' => 'IF-MIB::ifOutDiscards.1 = Counter32: 0',
            // Vendor serial OID (scalar, ends .0) takes precedence for Silver Peak.
            '.1.3.6.1.4.1.23867.3.1.1.1.6.0' => 'SILVERPEAK::serial.0 = STRING: "00-1B-BC-99-88-77"',
        ]));

        $poller->poll($device);

        $device->refresh();
        $this->assertSame('001BBC998877', $device->serial_number);   // stored in GUI form (no separators)
    }

    public function test_juniper_model_and_os_version_come_from_entity_oids(): void
    {
        $device = Device::factory()->create(['snmp_version' => 'v2c', 'vendor' => 'juniper', 'model' => 'unknown', 'os_version' => null]);

        $poller = new InterfacePoller($this->fakeWalker([
            '.1.3.6.1.2.1.2.2.1.2' => 'IF-MIB::ifDescr.1 = STRING: ge-0/0/0',
            '.1.3.6.1.2.1.2.2.1.8' => 'IF-MIB::ifOperStatus.1 = INTEGER: up(1)',
            '.1.3.6.1.2.1.2.2.1.10' => 'IF-MIB::ifInOctets.1 = Counter32: 0',
            '.1.3.6.1.2.1.2.2.1.16' => 'IF-MIB::ifOutOctets.1 = Counter32: 0',
            '.1.3.6.1.2.1.2.2.1.13' => 'IF-MIB::ifInDiscards.1 = Counter32: 0',
            '.1.3.6.1.2.1.2.2.1.19' => 'IF-MIB::ifOutDiscards.1 = Counter32: 0',
            '.1.3.6.1.2.1.47.1.1.1.1.7.120' => 'ENTITY-MIB::entPhysicalName.120 = STRING: "EX4300-48T"',
            '.1.3.6.1.2.1.47.1.1.1.1.10.1' => 'ENTITY-MIB::entPhysicalSoftwareRev.1 = STRING: "20.4R3-S4.8"',
        ]));

        $poller->poll($device);

        $device->refresh();
        $this->assertSame('EX4300-48T', $device->model);
        $this->assertSame('20.4R3-S4.8', $device->os_version);
    }

    public function test_non_juniper_model_is_left_untouched(): void
    {
        $device = Device::factory()->create(['snmp_version' => 'v2c', 'vendor' => 'silverpeak', 'model' => 'EC-US']);

        $poller = new InterfacePoller($this->fakeWalker([
            '.1.3.6.1.2.1.2.2.1.2' => 'IF-MIB::ifDescr.1 = STRING: wan0',
            '.1.3.6.1.2.1.2.2.1.8' => 'IF-MIB::ifOperStatus.1 = INTEGER: up(1)',
            '.1.3.6.1.2.1.47.1.1.1.1.7.120' => 'ENTITY-MIB::entPhysicalName.120 = STRING: "SHOULD-NOT-APPLY"',
        ]));

        $poller->poll($device);

        $device->refresh();
        $this->assertSame('EC-US', $device->model);
    }

    public function test_collects_interface_errors_and_computes_delta(): void
    {
        $device = Device::factory()->create(['snmp_version' => 'v2c']);

        $base = [
            '.1.3.6.1.2.1.2.2.1.2' => 'ifDescr.1 = STRING: ge-0/0/0',
            '.1.3.6.1.2.1.2.2.1.8' => 'ifOperStatus.1 = INTEGER: up(1)',
        ];

        // First poll establishes the counter baseline (100 in-errors).
        (new InterfacePoller($this->fakeWalker($base + [
            '.1.3.6.1.2.1.2.2.1.14' => 'ifInErrors.1 = Counter32: 100',
            '.1.3.6.1.2.1.2.2.1.20' => 'ifOutErrors.1 = Counter32: 5',
        ])))->poll($device);

        // Second poll: +14 CRC/in-errors, out-errors unchanged.
        (new InterfacePoller($this->fakeWalker($base + [
            '.1.3.6.1.2.1.2.2.1.14' => 'ifInErrors.1 = Counter32: 114',
            '.1.3.6.1.2.1.2.2.1.20' => 'ifOutErrors.1 = Counter32: 5',
        ])))->poll($device);

        $interface = DeviceInterface::where('device_id', $device->id)->where('if_index', 1)->first();
        $this->assertSame(114, (int) $interface->in_errors);
        $this->assertSame(14, (int) $interface->in_errors_delta);
        $this->assertSame(0, (int) $interface->out_errors_delta);
    }
}
