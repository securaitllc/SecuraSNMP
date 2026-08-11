<?php

namespace Tests\Feature;

use App\Models\Anomaly;
use App\Models\Circuit;
use App\Models\CircuitMetricHistory;
use App\Models\Site;
use App\Services\AnomalyDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnomalyDetectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_robust_z_and_sustained_detection(): void
    {
        $base = array_merge(array_fill(0, 20, 100.0), [98.0, 102.0, 101.0, 99.0]);

        $this->assertGreaterThan(50, AnomalyDetector::robustZ($base, 900.0));       // clear spike
        $this->assertLessThan(1, abs(AnomalyDetector::robustZ($base, 103.0)));      // within noise
        $this->assertNull(AnomalyDetector::robustZ([1, 2, 3], 99.0));               // too few to judge

        $this->assertSame('spike', AnomalyDetector::sustained($base, [880.0, 905.0, 890.0])['direction']);
        $this->assertNull(AnomalyDetector::sustained($base, [880.0, 101.0, 890.0])); // one normal → not sustained
        // drops don't count for spikes-only metrics (discards / latency)
        $this->assertNull(AnomalyDetector::sustained($base, [2.0, 1.0, 3.0], spikesOnly: true));
    }

    public function test_circuit_latency_anomaly_opens_then_resolves(): void
    {
        $circuit = Circuit::factory()->create(['site_id' => Site::factory()->create()->id, 'monitoring_enabled' => true]);

        // 20 baseline samples around 10 ms, then 3 sustained ~120 ms spikes.
        foreach (range(1, 20) as $i) {
            CircuitMetricHistory::create(['circuit_id' => $circuit->id, 'recorded_at' => now()->subMinutes(100 - $i), 'response_time_ms' => 10 + ($i % 3), 'loss_pct' => 0]);
        }
        foreach ([120, 125, 122] as $j => $v) {
            CircuitMetricHistory::create(['circuit_id' => $circuit->id, 'recorded_at' => now()->subMinutes(3 - $j), 'response_time_ms' => $v, 'loss_pct' => 0]);
        }

        (new AnomalyDetector)->scanCircuit($circuit->id);
        $this->assertDatabaseHas('anomalies', ['entity_type' => 'circuit', 'entity_id' => $circuit->id, 'metric' => 'latency', 'resolved_at' => null]);

        // Latency back to normal → the open anomaly resolves.
        CircuitMetricHistory::where('circuit_id', $circuit->id)->delete();
        foreach (range(1, 25) as $i) {
            CircuitMetricHistory::create(['circuit_id' => $circuit->id, 'recorded_at' => now()->subMinutes(30 - $i), 'response_time_ms' => 10, 'loss_pct' => 0]);
        }
        (new AnomalyDetector)->scanCircuit($circuit->id);
        $this->assertNotNull(Anomaly::first()->resolved_at);
        $this->assertSame(0, Anomaly::open()->count());
    }
}
