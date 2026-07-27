<?php

namespace Tests\Unit;

use App\Models\Device;
use App\Models\DeviceAlarm;
use App\Models\DeviceMetricHistory;
use App\Services\DeviceMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceMonitorTest extends TestCase
{
    use RefreshDatabase;

    private function pollDown(Device $device, int $times): void
    {
        $monitor = new DeviceMonitor(fn (string $ip) => null);
        for ($i = 0; $i < $times; $i++) {
            $monitor->check($device);
        }
    }

    public function test_a_device_down_raises_a_critical_alarm_only_after_the_debounce(): void
    {
        config(['monitoring.down_threshold' => 3]);
        $device = Device::factory()->create(['status' => 'active']);

        $this->pollDown($device, 2);
        $this->assertDatabaseCount('device_alarms', 0);   // one/two misses: no alarm yet

        $this->pollDown($device, 1);   // third consecutive miss
        $alarm = DeviceAlarm::where('device_id', $device->id)->where('alarm_id', 'device-unreachable')->first();
        $this->assertNotNull($alarm);
        $this->assertSame('critical', $alarm->severity);
        $this->assertNull($alarm->cleared_at);
        $this->assertMatchesRegularExpression('/^[A-Z]+-ALM-\d{6}$/', $alarm->ticket_number);   // investigation ticket
    }

    public function test_a_recovered_device_auto_clears_its_unreachable_alarm(): void
    {
        config(['monitoring.down_threshold' => 3]);
        $device = Device::factory()->create(['status' => 'active']);
        $this->pollDown($device, 3);

        (new DeviceMonitor(fn (string $ip) => 12.0))->check($device);   // responds again

        $alarm = DeviceAlarm::where('device_id', $device->id)->where('alarm_id', 'device-unreachable')->first();
        $this->assertNotNull($alarm->cleared_at);
        $this->assertFalse($alarm->cleared_manually);
    }

    public function test_a_manually_cleared_alarm_is_not_resurrected_while_still_down(): void
    {
        config(['monitoring.down_threshold' => 3]);
        $device = Device::factory()->create(['status' => 'active']);
        $this->pollDown($device, 3);

        $alarm = DeviceAlarm::where('device_id', $device->id)->firstOrFail();
        $alarm->update(['cleared_at' => now(), 'cleared_manually' => true]);

        $this->pollDown($device, 2);   // still down

        $this->assertTrue($alarm->fresh()->cleared_manually);
        $this->assertNotNull($alarm->fresh()->cleared_at);   // NOC's clear respected
        $this->assertSame(1, DeviceAlarm::where('device_id', $device->id)->count());
    }

    public function test_a_reachable_device_records_its_response_time(): void
    {
        $device = Device::factory()->create(['status' => 'active']);
        $monitor = new DeviceMonitor(fn (string $ip) => 21.5);

        $monitor->check($device);

        $this->assertDatabaseHas('device_metric_history', [
            'device_id' => $device->id,
            'response_time_ms' => 21.5,
        ]);
    }

    public function test_a_timeout_records_a_null_response_time(): void
    {
        $device = Device::factory()->create(['status' => 'active']);
        $monitor = new DeviceMonitor(fn (string $ip) => null);

        $monitor->check($device);

        $history = DeviceMetricHistory::where('device_id', $device->id)->first();
        $this->assertNotNull($history);
        $this->assertNull($history->response_time_ms);
    }

    public function test_checkall_skips_inactive_devices(): void
    {
        $inactive = Device::factory()->create(['status' => 'inactive']);
        $walkerInvoked = false;
        $monitor = new DeviceMonitor(function (string $ip) use (&$walkerInvoked) {
            $walkerInvoked = true;

            return 5.0;
        });

        $monitor->checkAll();

        $this->assertFalse($walkerInvoked, 'Inactive devices should not be pinged.');
        $this->assertSame(0, DeviceMetricHistory::where('device_id', $inactive->id)->count());
    }

    public function test_checkall_isolates_a_failing_device_from_the_rest(): void
    {
        $good = Device::factory()->create(['status' => 'active', 'ip_address' => '10.0.0.1']);
        $bad = Device::factory()->create(['status' => 'active', 'ip_address' => '10.0.0.2']);

        $monitor = new DeviceMonitor(function (string $ip) {
            if ($ip === '10.0.0.2') {
                throw new \RuntimeException('boom');
            }

            return 7.0;
        });

        $monitor->checkAll();

        $this->assertSame(1, DeviceMetricHistory::where('device_id', $good->id)->count());
        $this->assertSame(0, DeviceMetricHistory::where('device_id', $bad->id)->count());
    }
}
