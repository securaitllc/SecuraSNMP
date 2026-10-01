<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceAlarm;
use App\Models\DeviceMetricHistory;
use App\Models\Site;
use App\Services\DeviceMonitor;
use App\Services\SnmpIdentityPoller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Replacing hardware must replace the hardware's identity.
 *
 * Identity enrichment is write-once (that is what stops a fleet-wide snmpwalk storm),
 * so a swapped switch or a migrated SD-WAN appliance kept the dead unit's serial
 * forever. A recovery is the one moment the box behind an IP can plausibly have
 * changed, so that is when the identity is re-read.
 */
class HardwareReplacementTest extends TestCase
{
    use RefreshDatabase;

    private function device(array $attrs = []): Device
    {
        return Device::factory()->create([
            'site_id' => Site::factory()->create()->id,
            'vendor' => 'juniper',
            'model' => 'EX4300-48T',
            'serial_number' => 'OLD-SERIAL-1',
            'os_version' => '20.4R3',
            'status' => 'active',
            ...$attrs,
        ]);
    }

    /** A poller whose walker answers with the NEW unit's identity. */
    private function pollerReturning(string $sysDescr, string $serial): SnmpIdentityPoller
    {
        return new SnmpIdentityPoller(function (Device $d, string $oid) use ($sysDescr, $serial) {
            return str_contains($oid, '.1.3.6.1.2.1.47.1.1.1.1.11')
                ? "SNMPv2-MIB::x.1 = STRING: {$serial}"
                : "SNMPv2-MIB::sysDescr.0 = STRING: {$sysDescr}";
        });
    }

    public function test_a_recovery_asks_for_the_identity_to_be_re_read(): void
    {
        $device = $this->device();
        DeviceAlarm::factory()->create([
            'device_id' => $device->id, 'alarm_id' => 'device-unreachable',
            'severity' => 'critical', 'cleared_at' => null, 'active_on_device' => true,
        ]);

        (new DeviceMonitor(fn () => 8.0))->check($device);

        $this->assertNotNull($device->fresh()->identity_recheck_at, 'a real recovery re-opens identity');
    }

    public function test_a_device_that_was_never_down_is_not_re_walked(): void
    {
        // clearUnreachable runs on EVERY reachable poll. Stamping it each time would put
        // the whole fleet into a permanent identity re-walk — the snmpwalk storm.
        $device = $this->device();

        (new DeviceMonitor(fn () => 8.0))->check($device);

        $this->assertNull($device->fresh()->identity_recheck_at);
    }

    public function test_a_replaced_switch_takes_the_new_serial_and_keeps_the_old_one_on_record(): void
    {
        $device = $this->device(['identity_recheck_at' => now()]);
        DeviceMetricHistory::create([
            'device_id' => $device->id, 'recorded_at' => now(), 'response_time_ms' => 6.0,
        ]);

        $this->pollerReturning('Juniper Networks, Inc. ex4400-48p JUNOS 23.4R2.13', 'NEW-SERIAL-9')
            ->poll($device);

        $fresh = $device->fresh();
        $this->assertSame('NEW-SERIAL-9', $fresh->serial_number, 'the racked unit owns the record');
        $this->assertSame('OLD-SERIAL-1', $fresh->previous_serial_number, 'the outgoing serial stays provable');
        $this->assertNotNull($fresh->hardware_changed_at);
        $this->assertNull($fresh->identity_recheck_at, 'the one-shot is spent');
        $this->assertStringContainsString('23.4R2', (string) $fresh->os_version, 'new hardware, new image');
    }

    public function test_a_recovery_that_returns_the_same_serial_records_no_replacement(): void
    {
        $device = $this->device(['identity_recheck_at' => now()]);
        DeviceMetricHistory::create([
            'device_id' => $device->id, 'recorded_at' => now(), 'response_time_ms' => 6.0,
        ]);

        $this->pollerReturning('Juniper Networks, Inc. ex4300-48t JUNOS 20.4R3', 'OLD-SERIAL-1')
            ->poll($device);

        $fresh = $device->fresh();
        $this->assertSame('OLD-SERIAL-1', $fresh->serial_number);
        $this->assertNull($fresh->previous_serial_number, 'the same box is not a replacement');
        $this->assertNull($fresh->hardware_changed_at);
        $this->assertNull($fresh->identity_recheck_at);
    }

    public function test_a_silent_agent_never_erases_the_serial_and_the_recheck_is_retried(): void
    {
        // This gear drops SNMP responses under memory pressure. An empty answer means
        // "no answer", never "no serial" — erasing good inventory on a timeout would be
        // the worst possible outcome of a feature meant to keep inventory honest.
        $device = $this->device(['identity_recheck_at' => now()]);
        DeviceMetricHistory::create([
            'device_id' => $device->id, 'recorded_at' => now(), 'response_time_ms' => 6.0,
        ]);

        (new SnmpIdentityPoller(fn () => ''))->poll($device);

        $fresh = $device->fresh();
        $this->assertSame('OLD-SERIAL-1', $fresh->serial_number);
        $this->assertNull($fresh->previous_serial_number);
        $this->assertNotNull($fresh->identity_recheck_at, 'left set so the next cycle tries again');
    }
}
