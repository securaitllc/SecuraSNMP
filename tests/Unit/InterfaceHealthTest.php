<?php

namespace Tests\Unit;

use App\Models\DeviceInterface;
use App\Support\InterfaceHealth;
use Carbon\Carbon;
use Tests\TestCase;

class InterfaceHealthTest extends TestCase
{
    private function iface(array $attrs = []): DeviceInterface
    {
        return new DeviceInterface(array_merge([
            'status' => 'up',
            'admin_status' => 'up',
            'alarm_suppressed' => false,
        ], $attrs));
    }

    public function test_admin_down_is_not_attention(): void
    {
        $h = InterfaceHealth::classify($this->iface(['admin_status' => 'down', 'status' => 'down']), 0, 0, Carbon::now());
        $this->assertSame('admin_down', $h['status']);
        $this->assertFalse($h['attention']);
    }

    public function test_oper_down_is_attention_unless_muted(): void
    {
        $now = Carbon::now();
        $this->assertSame('down', InterfaceHealth::classify($this->iface(['status' => 'down']), 0, 0, $now)['status']);
        $this->assertTrue(InterfaceHealth::classify($this->iface(['status' => 'down']), 0, 0, $now)['attention']);

        $muted = InterfaceHealth::classify($this->iface(['status' => 'down', 'alarm_suppressed' => true]), 0, 0, $now);
        $this->assertSame('muted', $muted['status']);
        $this->assertFalse($muted['attention']);
    }

    public function test_errors_over_floor_flag_when_recent(): void
    {
        $now = Carbon::now();
        $if = $this->iface(['last_error_at' => $now->copy()->subMinutes(5)]);
        $h = InterfaceHealth::classify($if, InterfaceHealth::ERROR_FLOOR, 0, $now);
        $this->assertSame('errors', $h['status']);
        $this->assertTrue($h['attention']);
    }

    public function test_errors_below_floor_stay_clean(): void
    {
        $now = Carbon::now();
        $if = $this->iface(['last_error_at' => $now->copy()->subMinutes(5)]);
        $h = InterfaceHealth::classify($if, InterfaceHealth::ERROR_FLOOR - 1, 0, $now);
        $this->assertSame('clean', $h['status']);
    }

    public function test_discards_over_floor_flag_congested(): void
    {
        $now = Carbon::now();
        $if = $this->iface(['last_discard_at' => $now->copy()->subMinutes(5)]);
        $h = InterfaceHealth::classify($if, 0, InterfaceHealth::DISCARD_FLOOR, $now);
        $this->assertSame('congested', $h['status']);
    }

    public function test_recent_flap_flags_flapping_and_beats_errors(): void
    {
        $now = Carbon::now();
        $if = $this->iface([
            'last_flap_at' => $now->copy()->subHour(),
            'last_error_at' => $now->copy()->subMinutes(5),
        ]);
        $h = InterfaceHealth::classify($if, 999, 0, $now);
        $this->assertSame('flapping', $h['status']);
    }

    public function test_old_flap_does_not_flag(): void
    {
        $now = Carbon::now();
        $if = $this->iface(['last_flap_at' => $now->copy()->subHours(InterfaceHealth::FLAP_RECENT_HOURS + 1)]);
        $h = InterfaceHealth::classify($if, 0, 0, $now);
        $this->assertSame('clean', $h['status']);
    }

    public function test_acknowledgement_silences_until_a_newer_fault(): void
    {
        $now = Carbon::now();

        // Errors last seen BEFORE the ack → handled, pill goes clean.
        $acked = $this->iface([
            'last_error_at' => $now->copy()->subMinutes(30),
            'health_ack_at' => $now->copy()->subMinutes(10),
        ]);
        $this->assertSame('clean', InterfaceHealth::classify($acked, 999, 0, $now)['status']);

        // A NEWER error after the ack re-arms it.
        $rearmed = $this->iface([
            'last_error_at' => $now->copy()->subMinutes(2),
            'health_ack_at' => $now->copy()->subMinutes(10),
        ]);
        $this->assertSame('errors', InterfaceHealth::classify($rearmed, 999, 0, $now)['status']);
    }
}
