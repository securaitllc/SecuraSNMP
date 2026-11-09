<?php

namespace Tests\Feature;

use App\Models\Circuit;
use App\Models\CircuitAlert;
use App\Models\Device;
use App\Models\DeviceAlarm;
use App\Models\DeviceInterface;
use App\Models\InterfaceAlert;
use App\Models\NextHopAlert;
use App\Models\Tunnel;
use App\Models\TunnelAlert;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The unified alarm console: one list across all five alert kinds, and one
 * ack/clear path for any mix of them. This is what makes every alarm type
 * clearable from one screen.
 */
class AlertConsoleTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{device: DeviceAlarm, tunnel: TunnelAlert, interface: InterfaceAlert, circuit: CircuitAlert, nexthop: NextHopAlert} */
    private function seedOneOfEachKind(): array
    {
        $device = Device::factory()->create();

        return [
            'device' => DeviceAlarm::factory()->create(['device_id' => $device->id, 'cleared_at' => null, 'severity' => 'critical']),
            'tunnel' => TunnelAlert::factory()->create(['tunnel_id' => Tunnel::factory()->create(['device_id' => $device->id, 'status' => 'down'])->id, 'ended_at' => null]),
            'interface' => InterfaceAlert::factory()->create(['device_interface_id' => DeviceInterface::factory()->create(['device_id' => $device->id])->id, 'ended_at' => null]),
            'circuit' => CircuitAlert::factory()->create(['circuit_id' => Circuit::factory()->create()->id, 'ended_at' => null]),
            'nexthop' => NextHopAlert::factory()->create(['device_id' => $device->id, 'ended_at' => null]),
        ];
    }

    public function test_index_returns_every_open_alert_kind(): void
    {
        $this->seedOneOfEachKind();
        $viewer = User::factory()->create();

        $response = $this->actingAs($viewer)->getJson('/api/alerts');

        $response->assertOk();
        $kinds = collect($response->json('alerts'))->pluck('kind')->unique()->sort()->values()->all();
        $this->assertEqualsCanonicalizing(['circuit', 'device', 'interface', 'nexthop', 'tunnel'], $kinds);
        $this->assertSame(5, $response->json('counts.all'));
    }

    public function test_clear_closes_a_mix_of_kinds_and_flags_manual(): void
    {
        $alerts = $this->seedOneOfEachKind();
        $analyst = User::factory()->analyst()->create();

        $items = collect($alerts)->map(fn ($a, $kind) => ['kind' => $kind, 'id' => $a->id])->values()->all();

        $response = $this->actingAs($analyst)->postJson('/api/alerts/clear', [
            'items' => $items,
            'note' => 'AZR-ECH02 standby; redundancy intact',
        ]);

        $response->assertOk();
        $this->assertSame(5, $response->json('cleared'));

        // Device alarms close via cleared_at; every other kind via ended_at.
        $this->assertNotNull($alerts['device']->fresh()->cleared_at);
        $this->assertTrue($alerts['device']->fresh()->cleared_manually);
        foreach (['tunnel', 'interface', 'circuit', 'nexthop'] as $kind) {
            $this->assertNotNull($alerts[$kind]->fresh()->ended_at, "$kind should be closed");
            $this->assertTrue((bool) $alerts[$kind]->fresh()->cleared_manually, "$kind should be flagged manual");
            $this->assertSame($analyst->id, $alerts[$kind]->fresh()->cleared_by);
            $this->assertSame('AZR-ECH02 standby; redundancy intact', $alerts[$kind]->fresh()->clear_note);
        }
    }

    public function test_acknowledge_marks_a_mix_of_kinds(): void
    {
        $alerts = $this->seedOneOfEachKind();
        $analyst = User::factory()->analyst()->create();

        $items = collect($alerts)->map(fn ($a, $kind) => ['kind' => $kind, 'id' => $a->id])->values()->all();

        $response = $this->actingAs($analyst)->postJson('/api/alerts/acknowledge', ['items' => $items, 'note' => 'looking']);

        $response->assertOk();
        $this->assertSame(5, $response->json('acknowledged'));
        foreach ($alerts as $alert) {
            $this->assertNotNull($alert->fresh()->acknowledged_at);
        }
    }

    public function test_nexthop_alert_is_clearable(): void
    {
        $device = Device::factory()->create();
        $alert = NextHopAlert::factory()->create(['device_id' => $device->id, 'ended_at' => null]);
        $analyst = User::factory()->analyst()->create();

        $this->actingAs($analyst)->postJson('/api/alerts/clear', [
            'items' => [['kind' => 'nexthop', 'id' => $alert->id]],
            'note' => 'stale after SSH migration',
        ])->assertOk();

        $fresh = $alert->fresh();
        $this->assertNotNull($fresh->ended_at);
        $this->assertTrue($fresh->cleared_manually);
        $this->assertSame('stale after SSH migration', $fresh->clear_note);
    }

    public function test_viewer_cannot_clear(): void
    {
        $alerts = $this->seedOneOfEachKind();
        $viewer = User::factory()->create(); // default role viewer

        $this->actingAs($viewer)->postJson('/api/alerts/clear', [
            'items' => [['kind' => 'device', 'id' => $alerts['device']->id]],
        ])->assertForbidden();

        $this->assertNull($alerts['device']->fresh()->cleared_at);
    }

    public function test_already_cleared_alerts_are_not_double_counted(): void
    {
        $alerts = $this->seedOneOfEachKind();
        $alerts['tunnel']->update(['ended_at' => now()]);
        $analyst = User::factory()->analyst()->create();

        $response = $this->actingAs($analyst)->postJson('/api/alerts/clear', [
            'items' => [
                ['kind' => 'tunnel', 'id' => $alerts['tunnel']->id],
                ['kind' => 'device', 'id' => $alerts['device']->id],
            ],
        ]);

        // Only the still-open device alarm is counted; the pre-closed tunnel is skipped.
        $this->assertSame(1, $response->json('cleared'));
    }
}
