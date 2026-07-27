<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceAlarm;
use App\Models\DeviceInterface;
use App\Models\InterfaceAlert;
use App\Models\Tunnel;
use App\Models\TunnelAlert;
use App\Models\User;
use App\Support\TicketNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ticket numbers are sequential and type-tagged: MSIT-ALM-000123.
 *
 * The previous generator drew a random 8-digit number, checked the table, and
 * inserted — check-then-write with no unique constraint behind it, while three
 * poller loops run concurrently. Randomness made a duplicate unlikely rather than
 * impossible.
 */
class SequentialTicketNumberTest extends TestCase
{
    use RefreshDatabase;

    private function alarm(): DeviceAlarm
    {
        return DeviceAlarm::create([
            'device_id' => Device::factory()->create()->id,
            'alarm_id' => 'ec:65537:Tunnel-'.uniqid(),
            'description' => 'row',
            'severity' => 'critical',
            'first_seen_at' => now(),
        ]);
    }

    public function test_alarm_tickets_are_sequential_and_prefixed(): void
    {
        $first = $this->alarm();
        $second = $this->alarm();

        $this->assertSame('MSIT-ALM-000001', $first->ticket_number);
        $this->assertSame('MSIT-ALM-000002', $second->ticket_number);
    }

    public function test_each_type_counts_independently(): void
    {
        $device = Device::factory()->create();

        $alarm = $this->alarm();
        $iface = InterfaceAlert::create([
            'device_interface_id' => DeviceInterface::factory()->create(['device_id' => $device->id])->id,
            'severity' => 'warning',
            'started_at' => now(),
        ]);
        $tunnel = TunnelAlert::create([
            'tunnel_id' => Tunnel::factory()->create(['device_id' => $device->id])->id,
            'started_at' => now(),
        ]);

        // Type is legible from the number itself — the reason for a per-type scheme.
        $this->assertSame('MSIT-ALM-000001', $alarm->ticket_number);
        $this->assertSame('MSIT-IFC-000001', $iface->ticket_number);
        $this->assertSame('MSIT-TUN-000001', $tunnel->ticket_number);
    }

    public function test_the_org_prefix_is_configurable(): void
    {
        config(['monitoring.ticket_prefix' => 'ACME']);

        $this->assertSame('ACME-ALM-000001', $this->alarm()->ticket_number);
    }

    public function test_numbers_are_unique_across_many_tickets(): void
    {
        $tickets = collect(range(1, 50))->map(fn () => $this->alarm()->ticket_number);

        $this->assertCount(50, $tickets->unique());
        $this->assertSame('MSIT-ALM-000050', $tickets->last());
    }

    public function test_a_duplicate_ticket_number_is_rejected_by_the_database(): void
    {
        $existing = $this->alarm();

        // The unique index is the backstop behind the sequence: even if a bug
        // handed out a repeat, the write must fail rather than silently duplicate.
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        DB::table('device_alarms')->insert([
            'device_id' => Device::factory()->create()->id,
            'alarm_id' => 'ec:1:dupe',
            'ticket_number' => $existing->ticket_number,
            'description' => 'dupe',
            'severity' => 'warning',
            'first_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_an_explicitly_supplied_ticket_is_not_overwritten(): void
    {
        $alarm = DeviceAlarm::create([
            'device_id' => Device::factory()->create()->id,
            'alarm_id' => 'ec:1:explicit',
            'ticket_number' => 'MSIT-ALM-999999',
            'description' => 'row',
            'severity' => 'warning',
            'first_seen_at' => now(),
        ]);

        $this->assertSame('MSIT-ALM-999999', $alarm->ticket_number);
    }

    public function test_reserve_through_moves_the_counter_past_a_backfill(): void
    {
        TicketNumber::reserveThrough(TicketNumber::TYPE_ALARM, 400);

        $this->assertSame('MSIT-ALM-000401', $this->alarm()->ticket_number);
    }

    public function test_a_legacy_ticket_number_still_finds_its_alarm(): void
    {
        $alarm = $this->alarm();
        $alarm->updateQuietly(['legacy_ticket_number' => '83263267']);

        $response = $this->actingAs(User::factory()->create())->getJson('/api/search?q=83263267');

        $response->assertOk();
        // A number quoted to an ISP before the renumbering must not become a dead end.
        $this->assertNotEmpty(array_filter(
            $response->json(),
            fn ($r) => ($r['type'] ?? null) === 'alarm' && $r['label'] === $alarm->ticket_number,
        ));
    }

    public function test_an_alarm_is_findable_by_its_new_ticket_number(): void
    {
        $alarm = $this->alarm();

        $response = $this->actingAs(User::factory()->create())
            ->getJson('/api/search?q='.urlencode($alarm->ticket_number));

        $response->assertOk();
        $this->assertNotEmpty(array_filter(
            $response->json(),
            fn ($r) => ($r['type'] ?? null) === 'alarm' && $r['label'] === $alarm->ticket_number,
        ));
    }
}
