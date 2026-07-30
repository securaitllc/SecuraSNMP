<?php

namespace Tests\Unit;

use App\Models\Device;
use App\Models\Site;
use App\Services\SnmpIdentityPoller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SnmpIdentityPollerTest extends TestCase
{
    use RefreshDatabase;

    private function device(array $over = []): Device
    {
        $device = Device::factory()->create(array_merge([
            'site_id' => Site::factory()->create()->id,
            'vendor' => 'juniper',
            'model' => 'Unknown',
            'serial_number' => null,
            'os_version' => null,
            'snmp_community' => 'public',
        ], $over));

        // The poller only enriches reachable devices — seed a successful ping.
        \App\Models\DeviceMetricHistory::create([
            'device_id' => $device->id, 'recorded_at' => now(), 'response_time_ms' => 5.0,
        ]);

        return $device;
    }

    private function walker(array $responses): callable
    {
        return fn (Device $d, string $oid) => $responses[$oid] ?? '';
    }

    public function test_it_fills_model_serial_from_entity_mib(): void
    {
        $device = $this->device();
        (new SnmpIdentityPoller($this->walker([
            '.1.3.6.1.2.1.47.1.1.1.1.13' => 'ENTITY-MIB::entPhysicalModelName.1 = STRING: "EX4300-48T"',
            '.1.3.6.1.2.1.47.1.1.1.1.11' => 'ENTITY-MIB::entPhysicalSerialNum.1 = STRING: "PE3717AF0123"',
            '.1.3.6.1.2.1.1.1' => 'SNMPv2-MIB::sysDescr.0 = STRING: Juniper Networks, Inc. ex4300-48t Ethernet Switch, kernel JUNOS 18.4R2-S3.3, Build date: 2020',
        ])))->poll($device);

        $device->refresh();
        $this->assertSame('EX4300-48T', $device->model);
        $this->assertSame('PE3717AF0123', $device->serial_number);
        $this->assertSame('18.4R2-S3.3', $device->os_version);
    }

    public function test_it_falls_back_to_sysdescr_for_model_when_entity_mib_is_empty(): void
    {
        $device = $this->device();
        (new SnmpIdentityPoller($this->walker([
            '.1.3.6.1.2.1.1.1' => 'SNMPv2-MIB::sysDescr.0 = STRING: Juniper Networks, Inc. ex3400-24t Ethernet Switch, kernel JUNOS 20.4R3',
        ])))->poll($device);

        $device->refresh();
        $this->assertSame('EX3400-24T', $device->model);
        $this->assertSame('20.4R3', $device->os_version);
    }

    public function test_silverpeak_model_serial_and_version_come_from_the_silverpeak_mib(): void
    {
        $device = $this->device(['vendor' => 'silverpeak']);
        // Identity comes from a single walk of the SILVERPEAK-MGMT system group.
        (new SnmpIdentityPoller($this->walker([
            '.1.3.6.1.4.1.23867.3.1.1.1' => implode("\n", [
                'iso.3.6.1.4.1.23867.3.1.1.1.1.0 = STRING: "9.3.8.1_96913"',
                'iso.3.6.1.4.1.23867.3.1.1.1.2.0 = STRING: "EC10104"',
                'iso.3.6.1.4.1.23867.3.1.1.1.6.0 = STRING: "00-1B-BC-36-9E-88"',
            ]),
        ])))->poll($device);

        $device->refresh();
        $this->assertSame('EC10104', $device->model);
        $this->assertSame('9.3.8.1_96913', $device->os_version);
        // Serial OID .6.0 returns a dashed MAC; stored without separators (GUI form).
        $this->assertSame('001BBC369E88', $device->serial_number);
    }

    public function test_a_no_such_object_reply_is_never_stored_as_a_value(): void
    {
        $device = $this->device();
        (new SnmpIdentityPoller($this->walker([
            '.1.3.6.1.2.1.47.1.1.1.1.11' => 'ENTITY-MIB::entPhysicalSerialNum.1 = No Such Object available on this agent at this OID',
            '.1.3.6.1.2.1.47.1.1.1.1.13' => 'ENTITY-MIB::entPhysicalModelName.1 = No Such Instance currently exists at this OID',
            '.1.3.6.1.2.1.1.1' => 'SNMPv2-MIB::sysDescr.0 = STRING: Juniper Networks, Inc. ex4300-48t Ethernet Switch, kernel JUNOS 18.4R2',
        ])))->poll($device);

        $device->refresh();
        $this->assertSame('EX4300-48T', $device->model);          // fell back to sysDescr
        $this->assertNull($device->serial_number);                // NOT "No Such Object..."
    }

    public function test_a_device_with_a_known_model_is_skipped_entirely(): void
    {
        // Once the model is known the device is considered identified and is not
        // re-walked — this is the fix for the every-cycle snmpwalk storm.
        $device = $this->device(['model' => 'EX4600', 'os_version' => '21.2R1']);
        (new SnmpIdentityPoller($this->walker([
            '.1.3.6.1.2.1.47.1.1.1.1.13' => 'x.1 = STRING: "WRONG-MODEL"',
            '.1.3.6.1.2.1.47.1.1.1.1.11' => 'x.1 = STRING: "SER123"',
        ])))->poll($device);

        $device->refresh();
        $this->assertSame('EX4600', $device->model);        // untouched
        $this->assertSame('21.2R1', $device->os_version);   // untouched
        $this->assertNull($device->serial_number);          // not re-walked
    }

    public function test_an_unreachable_device_is_not_walked(): void
    {
        $device = Device::factory()->create([
            'site_id' => Site::factory()->create()->id,
            'vendor' => 'juniper', 'model' => 'Unknown', 'snmp_community' => 'public',
        ]);
        // Last ping timed out (null) → unreachable → no SNMP walks attempted.
        \App\Models\DeviceMetricHistory::create([
            'device_id' => $device->id, 'recorded_at' => now(), 'response_time_ms' => null,
        ]);

        $walked = false;
        (new SnmpIdentityPoller(function () use (&$walked) { $walked = true; return ''; }))->poll($device);

        $this->assertFalse($walked);
        $this->assertSame('Unknown', $device->fresh()->model);
    }
}
