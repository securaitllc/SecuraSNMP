<?php

namespace Tests\Feature;

use App\Models\Anomaly;
use App\Models\Circuit;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The anomaly feed powers three surfaces: the dashboard rail (unscoped), the device
 * detail page (?device=), and the circuit expand (?circuit=). Each scoped view must
 * return only the anomalies for that entity, and every row must carry a deep-link that
 * lands the operator ON the problem — not the top of a page.
 */
class AnomalyControllerTest extends TestCase
{
    use RefreshDatabase;

    private function viewer(): User
    {
        return User::factory()->create(['role' => 'viewer']);
    }

    private function anomaly(string $type, int $id, string $metric, array $extra = []): Anomaly
    {
        return Anomaly::create(array_merge([
            'entity_type' => $type, 'entity_id' => $id, 'metric' => $metric, 'direction' => 'spike',
            'baseline' => 10, 'observed' => 90, 'z_score' => 9.0, 'detected_at' => now(), 'last_seen_at' => now(),
        ], $extra));
    }

    public function test_device_scope_returns_the_device_and_its_interface_anomalies_only(): void
    {
        $site = Site::factory()->create();
        $device = Device::factory()->create(['site_id' => $site->id]);
        $iface = DeviceInterface::factory()->create(['device_id' => $device->id, 'if_name' => 'ge-0/0/5']);
        $other = Device::factory()->create(['site_id' => $site->id]);
        $otherIf = DeviceInterface::factory()->create(['device_id' => $other->id]);

        $mine = $this->anomaly('device', $device->id, 'cpu');
        $myIf = $this->anomaly('interface', $iface->id, 'throughput');
        $this->anomaly('device', $other->id, 'cpu');            // another device — excluded
        $this->anomaly('interface', $otherIf->id, 'discards');  // another device's iface — excluded

        $res = $this->actingAs($this->viewer())->getJson("/api/anomalies?device={$device->id}")->assertOk();

        $ids = collect($res->json('data'))->pluck('id')->sort()->values()->all();
        $this->assertSame(collect([$mine->id, $myIf->id])->sort()->values()->all(), $ids);
    }

    public function test_circuit_scope_returns_only_that_circuits_anomalies(): void
    {
        $site = Site::factory()->create();
        $circuit = Circuit::factory()->create(['site_id' => $site->id]);
        $mine = $this->anomaly('circuit', $circuit->id, 'latency');
        $this->anomaly('circuit', Circuit::factory()->create(['site_id' => $site->id])->id, 'loss');

        $res = $this->actingAs($this->viewer())->getJson("/api/anomalies?circuit={$circuit->id}")->assertOk();

        $this->assertSame([$mine->id], collect($res->json('data'))->pluck('id')->all());
    }

    public function test_each_row_deep_links_to_where_the_problem_is(): void
    {
        $site = Site::factory()->create();
        $device = Device::factory()->create(['site_id' => $site->id]);
        $iface = DeviceInterface::factory()->create(['device_id' => $device->id]);
        $circuit = Circuit::factory()->create(['site_id' => $site->id]);

        $ifAnom = $this->anomaly('interface', $iface->id, 'throughput');
        $devAnom = $this->anomaly('device', $device->id, 'cpu');
        $cxAnom = $this->anomaly('circuit', $circuit->id, 'latency');

        $routes = collect($this->actingAs($this->viewer())->getJson('/api/anomalies')->assertOk()->json('data'))
            ->pluck('route', 'id');

        // Interface + device → device page, focused on the exact anomaly row.
        $this->assertSame("/devices/{$device->id}?focusAnomaly={$ifAnom->id}", $routes[$ifAnom->id]);
        $this->assertSame("/devices/{$device->id}?focusAnomaly={$devAnom->id}", $routes[$devAnom->id]);
        // Circuit → circuit list, filtered to it, with the anomaly to highlight.
        $this->assertStringContainsString('focusAnomaly='.$cxAnom->id, $routes[$cxAnom->id]);
        $this->assertStringStartsWith('/circuits?q=', $routes[$cxAnom->id]);
    }
}
