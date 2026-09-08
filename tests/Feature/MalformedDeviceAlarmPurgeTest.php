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

    private function alarm(string $alarmId, bool $cleared, ?Device $device = null, bool $activeOnDevice = false): DeviceAlarm
    {
        return DeviceAlarm::create([
            'device_id' => ($device ?? Device::factory()->create())->id,
            'alarm_id' => $alarmId,
            'description' => 'row',
            'severity' => 'warning',
            'first_seen_at' => now()->subDay(),
            'cleared_at' => $cleared ? now() : null,
            'active_on_device' => $activeOnDevice,
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

    /**
     * An 'ec::' row cleared by the NOC while the appliance still reports it is the
     * no-resurrect state — 'ec::' sits inside the live 'ec:' namespace the poller
     * reconciles, so deleting it would let firstOrNew() reopen the alarm with a
     * fresh ticket on the next 90s sweep, overriding the operator's decision.
     */
    public function test_an_ec_phantom_still_active_on_device_is_kept(): void
    {
        $stillActive = $this->alarm('ec::', cleared: true, activeOnDevice: true);

        $this->purge();

        $this->assertDatabaseHas('device_alarms', ['id' => $stillActive->id]);
    }

    /**
     * Bare-numeric ids come from a retired poller and match no reconcile scope
     * ('ec:%' or 'device-unreachable'), so active_on_device on them is residue that
     * nothing will ever clear. Guarding on it would strand the rows permanently.
     */
    public function test_a_bare_numeric_alarm_is_deleted_even_when_active_on_device(): void
    {
        $residue = $this->alarm('3', cleared: true, activeOnDevice: true);

        $this->purge();

        $this->assertDatabaseMissing('device_alarms', ['id' => $residue->id]);
    }

    /**
     * Pins the blast radius across the chunkById(500) boundary: with 1,200 rows the
     * purge runs multiple chunks while the table shrinks underneath it, and the
     * count assertion proves ONLY the intended rows went — not merely that specific
     * ids are absent.
     */
    public function test_blast_radius_across_chunk_boundary(): void
    {
        $device = Device::factory()->create();

        for ($i = 0; $i < 600; $i++) {
            $this->alarm("ec:65537:tunnel-{$i}", cleared: true, device: $device);
            $this->alarm((string) $i, cleared: true, device: $device);
        }

        $this->assertSame(1200, DeviceAlarm::count());

        $this->purge();

        // REGEXP is MySQL-only and would throw on the SQLite the tests run against,
        // so assert the surviving set directly instead.
        $this->assertSame(600, DeviceAlarm::count());
        $this->assertSame(600, DeviceAlarm::where('alarm_id', 'like', 'ec:65537:%')->count());
    }
}
