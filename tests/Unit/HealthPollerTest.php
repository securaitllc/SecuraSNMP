<?php

namespace Tests\Unit;

use App\Models\Device;
use App\Services\HealthPoller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthPollerTest extends TestCase
{
    use RefreshDatabase;

    private function fakeWalker(array $responses): callable
    {
        return fn (Device $device, string $oid) => $responses[$oid] ?? '';
    }

    public function test_memory_detail_computes_reclaimable_and_swap_from_hrstorage(): void
    {
        // Real values from a live EC-10104 (KB units): 98% "used" but healthy.
        $descrs = [1 => 'Physical memory', 6 => 'Memory buffers', 7 => 'Cached memory', 10 => 'Swap space'];
        $units = [1 => '1024', 6 => '1024', 7 => '1024', 10 => '1024'];
        $sizes = [1 => '7899848', 6 => '7899848', 7 => '1013420', 10 => '3906244'];
        $used = [1 => '7765400', 6 => '787848', 7 => '1013420', 10 => '256'];

        [$reclaimableMb, $swapMb] = HealthPoller::memoryDetail($descrs, $sizes, $used, $units);

        // free(131) + buffers(769) + cached(989) ≈ 1889 MB reclaimable; swap ~0.
        $this->assertEqualsWithDelta(1889, $reclaimableMb, 3);
        $this->assertSame(0, $swapMb);
    }

    public function test_memory_detail_returns_null_without_a_physical_memory_row(): void
    {
        $this->assertSame([null, null], HealthPoller::memoryDetail([1 => 'Swap space'], [1 => '100'], [1 => '10'], [1 => '1024']));
    }

    public function test_average_cpu(): void
    {
        $this->assertSame(15.0, HealthPoller::averageCpu([1 => '10', 2 => '20']));
        $this->assertNull(HealthPoller::averageCpu([]));
    }

    public function test_memory_percent_from_ram_row(): void
    {
        $types = [1 => '.1.3.6.1.2.1.25.2.1.4', 3 => 'HOST-RESOURCES-TYPES::hrStorageRam'];
        $sizes = [1 => '100', 3 => '1000'];
        $used = [1 => '50', 3 => '250'];

        $this->assertSame(25.0, HealthPoller::memoryPercent($types, $sizes, $used));
    }

    public function test_parse_uptime_handles_timeticks(): void
    {
        $this->assertSame(3600, HealthPoller::parseUptime([0 => '(360000) 1:00:00.00']));
        $this->assertSame(50, HealthPoller::parseUptime([0 => '5000']));
    }

    public function test_poll_records_health_and_sensors(): void
    {
        $device = Device::factory()->create(['snmp_version' => 'v2c']);

        $poller = new HealthPoller($this->fakeWalker([
            '.1.3.6.1.2.1.25.3.3.1.2' => "hrProcessorLoad.1 = INTEGER: 20\nhrProcessorLoad.2 = INTEGER: 40",
            '.1.3.6.1.2.1.25.2.3.1.2' => 'hrStorageType.3 = OID: HOST-RESOURCES-TYPES::hrStorageRam',
            '.1.3.6.1.2.1.25.2.3.1.5' => 'hrStorageSize.3 = INTEGER: 1000',
            '.1.3.6.1.2.1.25.2.3.1.6' => 'hrStorageUsed.3 = INTEGER: 600',
            '.1.3.6.1.2.1.1.3.0' => 'sysUpTime.0 = Timeticks: (360000) 1:00:00.00',
            '.1.3.6.1.2.1.99.1.1.1.1' => "entPhySensorType.1 = INTEGER: celsius(8)\nentPhySensorType.2 = INTEGER: rpm(10)",
            '.1.3.6.1.2.1.99.1.1.1.3' => "entPhySensorPrecision.1 = INTEGER: 0\nentPhySensorPrecision.2 = INTEGER: 0",
            '.1.3.6.1.2.1.99.1.1.1.4' => "entPhySensorValue.1 = INTEGER: 47\nentPhySensorValue.2 = INTEGER: 4200",
            '.1.3.6.1.2.1.99.1.1.1.5' => "entPhySensorOperStatus.1 = INTEGER: ok(1)\nentPhySensorOperStatus.2 = INTEGER: ok(1)",
            '.1.3.6.1.2.1.47.1.1.1.1.7' => "entPhysicalName.1 = STRING: Temp CPU\nentPhysicalName.2 = STRING: Fan 1",
        ]));

        $poller->poll($device);

        $this->assertDatabaseHas('device_health', [
            'device_id' => $device->id,
            'cpu_pct' => 30.0,
            'mem_pct' => 60.0,
            'temperature_c' => 47.0,
            'uptime_seconds' => 3600,
        ]);
        $this->assertDatabaseHas('device_sensors', ['device_id' => $device->id, 'name' => 'Temp CPU', 'sensor_type' => 'celsius', 'value' => 47.0]);
        $this->assertDatabaseHas('device_sensors', ['device_id' => $device->id, 'name' => 'Fan 1', 'sensor_type' => 'rpm', 'value' => 4200.0]);
        $this->assertDatabaseCount('device_health_history', 1);
    }

    public function test_max_value_ignores_zero_and_missing(): void
    {
        $this->assertSame(78.0, HealthPoller::maxValue([1 => '0', 7 => '78', 9 => '12']));
        $this->assertNull(HealthPoller::maxValue([1 => '0', 2 => '0']));
        $this->assertNull(HealthPoller::maxValue([]));
    }

    public function test_juniper_health_comes_from_jnx_operating_table(): void
    {
        $device = Device::factory()->create(['snmp_version' => 'v2c', 'vendor' => 'juniper']);

        // Junos: HOST-RESOURCES/ENTITY-SENSOR empty; only jnxOperatingTable answers.
        // Two components (RE index 9, FPC index 7) — the device figure is the max.
        $poller = new HealthPoller($this->fakeWalker([
            '.1.3.6.1.2.1.1.3.0' => 'sysUpTime.0 = Timeticks: (360000) 1:00:00.00',
            '.1.3.6.1.4.1.2636.3.1.13.1.8' => "jnxOperatingCPU.9 = INTEGER: 12\njnxOperatingCPU.7 = INTEGER: 5",
            '.1.3.6.1.4.1.2636.3.1.13.1.11' => "jnxOperatingBuffer.9 = INTEGER: 63\njnxOperatingBuffer.7 = INTEGER: 41",
            '.1.3.6.1.4.1.2636.3.1.13.1.7' => "jnxOperatingTemp.9 = INTEGER: 44\njnxOperatingTemp.7 = INTEGER: 39",
        ]));

        $poller->poll($device);

        $this->assertDatabaseHas('device_health', [
            'device_id' => $device->id,
            'cpu_pct' => 12.0,
            'mem_pct' => 63.0,
            'temperature_c' => 44.0,
        ]);
    }
}
