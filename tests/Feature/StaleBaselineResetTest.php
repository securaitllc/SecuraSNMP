<?php

namespace Tests\Feature;

use App\Models\Anomaly;
use App\Models\Circuit;
use App\Models\Site;
use App\Services\StaleBaselineReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 19 September: the collector's egress moved to another carrier, every circuit's
 * RTT rose ~7 ms, and 34 latency anomalies opened against a 4.1 ms baseline that
 * belonged to a path nobody uses any more. Every one was correct. None could ever
 * resolve — the observed value really is above the old baseline and always will be.
 */
class StaleBaselineResetTest extends TestCase
{
    use RefreshDatabase;

    private function anomaly(float $baseline, float $observed, array $over = []): Anomaly
    {
        return Anomaly::create(array_merge([
            'entity_type' => 'circuit',
            'entity_id' => Circuit::factory()->for(Site::factory())->create()->id,
            'metric' => 'latency',
            'direction' => 'spike',
            'baseline' => $baseline,
            'observed' => $observed,
            'z_score' => 23.6,
            'detected_at' => now()->subHours(StaleBaselineReset::SETTLED_HOURS + 2),
            'last_seen_at' => now(),
        ], $over));
    }

    private function fleetShift(int $n = 10): void
    {
        foreach (range(1, $n) as $i) {
            $this->anomaly(4.1, 11.3 + ($i / 20));
        }
    }

    public function test_a_settled_fleet_wide_shift_is_reset(): void
    {
        $this->fleetShift();

        $this->assertSame(10, (new StaleBaselineReset)->run());
        $this->assertSame(0, Anomaly::open()->count());
    }

    public function test_a_shift_that_has_not_settled_is_left_alone(): void
    {
        // Still fresh. It might be a real fault that is only minutes old, and
        // clearing it would take the finding away while the NOC is reading it.
        $this->fleetShift();
        Anomaly::query()->update(['detected_at' => now()->subMinutes(20)]);

        $this->assertSame(0, (new StaleBaselineReset)->run());
        $this->assertSame(10, Anomaly::open()->count());
    }

    public function test_one_entity_drifting_alone_is_a_finding_not_a_rebaseline(): void
    {
        // The whole safeguard. A single circuit whose latency doubled is exactly what
        // this detector is for, and must never be cleared as "the new normal".
        $this->anomaly(4.1, 11.3);
        $this->anomaly(4.1, 11.3);

        $this->assertSame(0, (new StaleBaselineReset)->run());
        $this->assertSame(2, Anomaly::open()->count());
    }

    public function test_an_outlier_keeps_its_finding_while_the_crowd_is_reset(): void
    {
        // Ten circuits moved 7 ms; one moved 60. That one is not part of the path
        // change and its finding stands.
        $this->fleetShift();
        $outlier = $this->anomaly(4.1, 64.0);

        (new StaleBaselineReset)->run();

        $this->assertNull($outlier->fresh()->resolved_at, 'a real deviation is not swept up');
        $this->assertSame(1, Anomaly::open()->count());
    }

    public function test_only_latency_is_resettable(): void
    {
        // Loss and discards do not shift fleet-wide because a route changed. If they
        // all move at once, something is wrong and it stays on the screen.
        $this->fleetShift();
        Anomaly::query()->update(['metric' => 'loss']);

        $this->assertSame(0, (new StaleBaselineReset)->run());
        $this->assertSame(10, Anomaly::open()->count());
    }

    public function test_a_reset_lets_a_genuine_deviation_reopen_afterwards(): void
    {
        // Resetting must not blind the detector. Once the new normal is the baseline,
        // a fresh departure from it is a fresh finding.
        $this->fleetShift();
        (new StaleBaselineReset)->run();
        $this->assertSame(0, Anomaly::open()->count());

        $fresh = $this->anomaly(11.3, 95.0, ['detected_at' => now()]);

        $this->assertSame(1, Anomaly::open()->count());
        $this->assertNull($fresh->fresh()->resolved_at);
    }
}
