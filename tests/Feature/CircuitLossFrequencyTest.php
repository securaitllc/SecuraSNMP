<?php

namespace Tests\Feature;

use App\Models\Circuit;
use App\Models\CircuitMetricHistory;
use App\Models\Site;
use App\Services\CircuitMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A median cannot describe a circuit that is intermittently fine and broken.
 *
 * sustained_loss_pct is the median of the last five polls, chosen so one dropped probe
 * could not read as degraded. For a circuit that is either healthy or down, it works.
 *
 * #037 Lawrenceville's DIA, 445453113, over one day: 206 polls, 96 of them lossy — 47%
 * — peaking at 90, 70, 60 and 50 percent, continuously, all day. Fewer than three polls
 * in any five were lossy, so the middle value never moved off zero. The app reported
 * the circuit UP with sustained loss 0 and raised nothing at all, while nearly half of
 * every probe set was dropping packets.
 *
 * Frequency answers what the median cannot. The median still says how bad it is when it
 * is bad; this says how often that is.
 */
class CircuitLossFrequencyTest extends TestCase
{
    use RefreshDatabase;

    private Circuit $circuit;

    protected function setUp(): void
    {
        parent::setUp();

        $site = Site::factory()->create(['name' => '#037 Lawrenceville GA']);
        $this->circuit = Circuit::factory()->create([
            'site_id' => $site->id, 'circuit_id' => '445453113', 'isp_name' => 'Lumen',
            'monitored_ip' => '4.4.252.37', 'monitor_via' => 'icmp', 'status' => 'up',
        ]);
    }

    /** @param  array<int, int>  $losses  oldest first */
    private function history(array $losses): void
    {
        $t = now()->subMinutes(count($losses));
        foreach ($losses as $i => $loss) {
            CircuitMetricHistory::create([
                'circuit_id' => $this->circuit->id,
                'recorded_at' => $t->copy()->addMinutes($i),
                'response_time_ms' => 11.0,
                'loss_pct' => $loss,
            ]);
        }
    }

    /** Run one clean poll so the monitor recomputes the summary columns. */
    private function poll(int $loss = 0): void
    {
        (new CircuitMonitor(fn () => ['loss' => $loss, 'rtt' => 11.0]))->check($this->circuit->fresh());
        $this->circuit = $this->circuit->fresh();
    }

    public function test_the_shape_that_was_invisible(): void
    {
        // Alternating clean and lossy, exactly #037: the median of any five is 0.
        $this->history([0, 20, 0, 30, 0, 90, 0, 10, 0, 50, 0, 20, 0, 60, 0, 30, 0, 70, 0]);
        $this->poll(0);

        $this->assertSame(0, (int) $this->circuit->sustained_loss_pct, 'the median still reads zero — that is the point');
        $this->assertGreaterThanOrEqual(40, (int) $this->circuit->loss_polls_pct, 'and the frequency shows what it hides');
        $this->assertSame(90, (int) $this->circuit->loss_peak_pct, 'with the worst of it named');
    }

    public function test_a_clean_circuit_reports_nothing(): void
    {
        $this->history(array_fill(0, 20, 0));
        $this->poll(0);

        $this->assertSame(0, (int) $this->circuit->loss_polls_pct);
        $this->assertSame(0, (int) $this->circuit->loss_peak_pct);
    }

    public function test_one_bad_probe_in_twenty_stays_occasional(): void
    {
        // The reason the median existed. A single drop must not read as degraded, and
        // frequency must not undo that.
        $this->history(array_merge([40], array_fill(0, 19, 0)));
        $this->poll(0);

        $this->assertLessThan(
            CircuitMonitor::LOSSY_POLLS_DEGRADED_PCT,
            (int) $this->circuit->loss_polls_pct,
            'one drop in twenty is occasional, not degraded',
        );
    }

    public function test_a_persistently_lossy_circuit_crosses_the_line(): void
    {
        $this->history([0, 10, 0, 20, 0, 10, 0, 30, 0, 10, 0, 20, 0, 10, 0, 40, 0, 10, 0]);
        $this->poll(10);

        $this->assertGreaterThanOrEqual(
            CircuitMonitor::LOSSY_POLLS_DEGRADED_PCT,
            (int) $this->circuit->loss_polls_pct,
            'half the probes losing packets is degraded, whatever the median says',
        );
    }

    public function test_the_figures_reach_the_client(): void
    {
        $this->history([0, 50, 0, 60, 0, 40, 0, 70, 0, 30]);
        $this->poll(0);

        $this->actingAs(\App\Models\User::factory()->create(['role' => 'admin', 'is_active' => true]));
        $rows = $this->getJson('/api/circuits')->assertOk()->json();
        $row = collect($rows['data'] ?? $rows)->firstWhere('circuit_id', '445453113');

        $this->assertArrayHasKey('loss_polls_pct', $row);
        $this->assertGreaterThan(0, (int) $row['loss_polls_pct']);
        $this->assertSame(70, (int) $row['loss_peak_pct']);
    }
}
