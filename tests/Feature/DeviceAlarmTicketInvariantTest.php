<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceAlarm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every alarm the NOC can see must carry an 8-digit ticket number — it is how an
 * operator references the alarm to an ISP, in a dispatch, or on a bridge call.
 *
 * Massey production carried 8 alarms with a NULL ticket because
 * 2026_07_22_000004 added the column without a backfill (the interface_alerts and
 * tunnel_alerts equivalents both shipped one). These tests pin both halves: the
 * model always assigns on create, and no row is left without one.
 */
class DeviceAlarmTicketInvariantTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_an_alarm_assigns_a_prefixed_ticket(): void
    {
        $alarm = DeviceAlarm::create([
            'device_id' => Device::factory()->create()->id,
            'alarm_id' => 'ec:65537:Tunnel',
            'description' => 'Tunnel state is Down',
            'severity' => 'critical',
            'first_seen_at' => now(),
        ]);

        $this->assertNotNull($alarm->ticket_number);
        $this->assertMatchesRegularExpression('/^MSTK\d{8}$/', $alarm->ticket_number);
    }

    public function test_an_explicit_ticket_is_not_overwritten(): void
    {
        $alarm = DeviceAlarm::create([
            'device_id' => Device::factory()->create()->id,
            'alarm_id' => 'ec:65537:Tunnel',
            'ticket_number' => '12345678',
            'description' => 'Tunnel state is Down',
            'severity' => 'critical',
            'first_seen_at' => now(),
        ]);

        $this->assertSame('12345678', $alarm->ticket_number);
    }

    public function test_tickets_are_unique_across_alarms(): void
    {
        $device = Device::factory()->create();

        $tickets = collect(range(1, 25))->map(fn (int $i) => DeviceAlarm::create([
            'device_id' => $device->id,
            'alarm_id' => "ec:65537:tunnel-{$i}",
            'description' => 'Tunnel state is Down',
            'severity' => 'critical',
            'first_seen_at' => now(),
        ])->ticket_number);

        $this->assertCount(25, $tickets->unique());
    }

    public function test_no_alarm_is_left_without_a_ticket(): void
    {
        $device = Device::factory()->create();

        // Simulate a row written before the ticket column existed.
        DeviceAlarm::create([
            'device_id' => $device->id,
            'alarm_id' => 'ec::',
            'description' => 'Legacy row',
            'severity' => 'warning',
            'first_seen_at' => now(),
        ])->updateQuietly(['ticket_number' => null]);

        $this->assertSame(1, DeviceAlarm::whereNull('ticket_number')->count());

        // Run the REAL migration, not a re-typed copy of its body — otherwise this
        // asserts the technique works, not that 2026_07_25_000001 works.
        // RefreshDatabase already recorded it against an empty table, so
        // `artisan migrate` would be a no-op; require + up() re-runs it.
        (require database_path('migrations/2026_07_25_000001_backfill_device_alarm_tickets.php'))->up();

        $this->assertSame(0, DeviceAlarm::whereNull('ticket_number')->count());
        $this->assertMatchesRegularExpression(
            '/^MSTK\d{8}$/',
            DeviceAlarm::latest('id')->first()->ticket_number,
        );
    }
}
