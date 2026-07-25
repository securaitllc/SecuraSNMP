<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceAlarm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026_07_25_000002 purges alarm rows whose alarm_id was never a real identifier
 * ('ec::' phantoms and bare walk indices from retired poller versions).
 *
 * These tests pin the blast radius: valid ids survive, and an OPEN malformed row
 * is kept — a live problem must never silently vanish from the NOC.
 */
class MalformedDeviceAlarmPurgeTest extends TestCase
{
    use RefreshDatabase;

    private function alarm(string $alarmId, bool $cleared): DeviceAlarm
    {
        return DeviceAlarm::create([
            'device_id' => Device::factory()->create()->id,
            'alarm_id' => $alarmId,
            'description' => 'row',
            'severity' => 'warning',
            'first_seen_at' => now()->subDay(),
            'cleared_at' => $cleared ? now() : null,
        ]);
    }

    /**
     * Run the migration's up() directly. RefreshDatabase has already recorded the
     * migration against an empty table, so `artisan migrate` would be a no-op.
     */
    private function purge(): void
    {
        (require database_path('migrations/2026_07_25_000002_purge_malformed_device_alarms.php'))->up();
    }

    public function test_cleared_malformed_alarms_are_deleted(): void
    {
        $phantom = $this->alarm('ec::', cleared: true);
        $index = $this->alarm('3', cleared: true);

        $this->purge();

        $this->assertDatabaseMissing('device_alarms', ['id' => $phantom->id]);
        $this->assertDatabaseMissing('device_alarms', ['id' => $index->id]);
    }

    public function test_valid_alarm_ids_survive(): void
    {
        $edge = $this->alarm('ec:65537:Tunnel', cleared: true);
        $unreachable = $this->alarm('device-unreachable', cleared: true);
        $sourced = $this->alarm('ec:262153:System', cleared: true);

        $this->purge();

        $this->assertDatabaseHas('device_alarms', ['id' => $edge->id]);
        $this->assertDatabaseHas('device_alarms', ['id' => $unreachable->id]);
        $this->assertDatabaseHas('device_alarms', ['id' => $sourced->id]);
    }

    public function test_an_open_malformed_alarm_is_kept(): void
    {
        $open = $this->alarm('ec::', cleared: false);

        $this->purge();

        $this->assertDatabaseHas('device_alarms', ['id' => $open->id]);
    }
}
