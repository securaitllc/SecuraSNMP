<?php

namespace Tests\Feature;

use App\Models\Circuit;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\Tunnel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Metric history endpoints must refuse an unscoped query.
 *
 * Each of these took an OPTIONAL id filter, so omitting it loaded the whole fleet's
 * history for the range. Measured against the live fleet: devices and interfaces
 * returned 500, and tunnels ran for twelve seconds before failing — twelve seconds
 * of a pinned worker and database connection per call, which a few concurrent
 * requests turn into a starved pool.
 *
 * No caller needs the unscoped shape: the UI graphs one entity at a time, and the
 * table views use the windowed, point-capped summary endpoints instead. A 422 is
 * the correct answer.
 */
class MetricEndpointsAreScopedTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string}> */
    public static function unscopedEndpoints(): array
    {
        return [
            'devices' => ['/api/devices/metrics?range=24h'],
            'interfaces' => ['/api/interfaces/metrics?range=24h'],
            'tunnels' => ['/api/tunnels/metrics?range=24h'],
            'circuits' => ['/api/circuits/metrics?range=24h'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unscopedEndpoints')]
    public function test_an_unscoped_metric_query_is_rejected(string $url): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson($url)
            ->assertStatus(422);
    }

    public function test_a_scoped_device_query_still_works(): void
    {
        $device = Device::factory()->create();

        $this->actingAs(User::factory()->create())
            ->getJson("/api/devices/metrics?device_id={$device->id}&range=24h")
            ->assertOk();
    }

    public function test_a_scoped_circuit_query_still_works(): void
    {
        $circuit = Circuit::factory()->create();

        $this->actingAs(User::factory()->create())
            ->getJson("/api/circuits/metrics?circuit_id={$circuit->id}&range=24h")
            ->assertOk();
    }

    public function test_interfaces_accept_either_scope(): void
    {
        $device = Device::factory()->create();
        $interface = DeviceInterface::factory()->create(['device_id' => $device->id]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson("/api/interfaces/metrics?interface_id={$interface->id}")
            ->assertOk();

        $this->actingAs($user)
            ->getJson("/api/interfaces/metrics?device_id={$device->id}")
            ->assertOk();
    }

    public function test_tunnels_accept_either_scope(): void
    {
        $device = Device::factory()->create();
        $tunnel = Tunnel::factory()->create(['device_id' => $device->id]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson("/api/tunnels/metrics?tunnel_id={$tunnel->id}")
            ->assertOk();

        $this->actingAs($user)
            ->getJson("/api/tunnels/metrics?device_id={$device->id}")
            ->assertOk();
    }

    public function test_a_nonexistent_scope_is_rejected_rather_than_scanned(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/devices/metrics?device_id=999999&range=24h')
            ->assertStatus(422);
    }
}
