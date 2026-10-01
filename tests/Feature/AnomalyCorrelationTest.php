<?php

namespace Tests\Feature;

use App\Models\Anomaly;
use App\Models\Circuit;
use App\Models\Site;
use App\Models\User;
use App\Services\AnomalyCorrelation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 19 September: the collector's internet egress moved from one carrier to another at
 * 17:37. Every circuit's RTT shifted by about the same 7 ms and the detector opened
 * FIFTY latency anomalies at 17:55. Each was correct; together they were noise.
 */
class AnomalyCorrelationTest extends TestCase
{
    use RefreshDatabase;

    private function anomaly(array $over = []): Anomaly
    {
        return Anomaly::create(array_merge([
            'entity_type' => 'circuit',
            'entity_id' => Circuit::factory()->for(Site::factory())->create()->id,
            'metric' => 'latency',
            'direction' => 'up',
            'baseline' => 4.1,
            'observed' => 11.3,
            'z_score' => 23.6,
            'detected_at' => now(),
            'last_seen_at' => now(),
        ], $over));
    }

    /** @param  array<int, float>  $observed */
    private function group(array $observed, array $over = []): void
    {
        foreach ($observed as $v) {
            $this->anomaly(array_merge(['observed' => $v], $over));
        }
    }

    public function test_a_uniform_shift_across_many_circuits_is_one_event(): void
    {
        // The real shape: baseline ~4.1, observed ~11.3, everything within a minute.
        $this->group([11.3, 11.4, 11.2, 11.7, 11.3, 11.2, 11.5, 11.3, 11.4, 11.3]);

        $found = (new AnomalyCorrelation)->findings(Anomaly::open()->get());

        $this->assertCount(1, $found, 'ten circuits moving together is one event, not ten');
        $this->assertSame(10, $found[0]['members']);
        $this->assertSame('latency', $found[0]['metric']);
        $this->assertEqualsWithDelta(7.2, $found[0]['shift'], 0.3);
    }

    public function test_the_finding_names_the_carriers_it_spans(): void
    {
        // The giveaway that no carrier is the cause: the group crosses several of them.
        foreach (['Lumen', 'Comcast', 'Spectrum'] as $isp) {
            for ($i = 0; $i < 4; $i++) {
                $circuit = Circuit::factory()->for(Site::factory())->create(['isp_name' => $isp]);
                $this->anomaly(['entity_id' => $circuit->id, 'observed' => 11.3 + ($i / 10)]);
            }
        }

        $found = (new AnomalyCorrelation)->findings(Anomaly::open()->get());

        $this->assertCount(1, $found);
        $this->assertCount(3, $found[0]['carriers']);
        $this->assertStringContainsString('3 carriers', $found[0]['reason']);
        $this->assertStringContainsString('path they share', $found[0]['reason']);
    }

    public function test_a_handful_of_deviations_is_not_a_pattern(): void
    {
        $this->group([11.3, 11.4, 11.2]);

        $this->assertSame([], (new AnomalyCorrelation)->findings(Anomaly::open()->get()));
    }

    public function test_deviations_of_different_sizes_are_separate_problems(): void
    {
        // Same metric, same minute, but nothing alike about the magnitudes — these are
        // genuinely separate faults and must not be swept into one reassuring row.
        $this->group([11.3, 40.0, 6.2, 95.0, 12.9, 60.0, 8.1, 140.0, 22.0, 75.0]);

        $this->assertSame([], (new AnomalyCorrelation)->findings(Anomaly::open()->get()));
    }

    public function test_deviations_hours_apart_are_separate_events(): void
    {
        $this->group([11.3, 11.4, 11.2, 11.5, 11.3], ['detected_at' => now()->subHours(6), 'last_seen_at' => now()->subHours(6)]);
        $this->group([11.3, 11.4, 11.2, 11.5, 11.3], ['detected_at' => now()]);

        // Five and five, each under the floor — and crucially NOT merged into ten.
        // Carbon 3 returns a signed diff, so diffing backwards makes every gap
        // negative, which would have swept the whole fleet into one cluster.
        $this->assertSame([], (new AnomalyCorrelation)->findings(Anomaly::open()->get()));
    }

    public function test_different_metrics_are_not_grouped_together(): void
    {
        $this->group([11.3, 11.4, 11.2, 11.5, 11.3, 11.2, 11.4, 11.3]);
        $this->group([11.3, 11.4, 11.2, 11.5, 11.3, 11.2, 11.4, 11.3], ['metric' => 'loss']);

        $found = (new AnomalyCorrelation)->findings(Anomaly::open()->get());

        $this->assertCount(2, $found);
        $this->assertNotSame($found[0]['metric'], $found[1]['metric']);
    }

    public function test_it_resolves_and_suppresses_nothing(): void
    {
        // The anomalies stay exactly as they are. This only says they belong together
        // — an operator who disagrees still has every individual reading.
        $this->group([11.3, 11.4, 11.2, 11.7, 11.3, 11.2, 11.5, 11.3, 11.4, 11.3]);

        (new AnomalyCorrelation)->findings(Anomaly::open()->get());

        $this->assertSame(10, Anomaly::open()->count());
    }

    public function test_the_feed_carries_shared_events_and_scoped_calls_do_not(): void
    {
        $this->group([11.3, 11.4, 11.2, 11.7, 11.3, 11.2, 11.5, 11.3, 11.4, 11.3]);
        $user = User::factory()->create(['role' => 'viewer']);

        $this->actingAs($user)->getJson('/api/anomalies')
            ->assertOk()
            ->assertJsonCount(1, 'shared_events');

        // A single circuit cannot show a fleet pattern, and asking for one on every
        // detail-page load would be a full scan for nothing.
        $circuit = Anomaly::open()->first()->entity_id;
        $this->actingAs($user)->getJson("/api/anomalies?circuit={$circuit}")
            ->assertOk()
            ->assertJsonCount(0, 'shared_events');
    }
}
