<?php

namespace Tests\Feature;

use App\Jobs\ProbeCircuitPath;
use App\Models\Circuit;
use App\Models\CircuitMetricHistory;
use App\Models\CircuitPathProbe;
use App\Models\Site;
use App\Models\User;
use App\Services\CircuitLossEpisodes;
use App\Services\CircuitMonitor;
use App\Services\CircuitPathProber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Which hop, and since when.
 *
 * When #037's DIA was losing packets on half its probes, the carrier asked two things:
 * where along the path, and since when. The app could answer neither. The history held
 * the second and nothing read it; nothing had ever looked for the first. Both had to
 * be worked out live on the call, with the carrier waiting.
 *
 * The path answer has one judgement in it, and it is the reason a raw trace is not
 * enough: a hop that drops probes while every later hop is clean is a router declining
 * to answer ICMP about itself, not a path losing traffic. Naming it would send the
 * carrier at the wrong box. Real loss persists to the end of the path.
 */
class CircuitPathProbeTest extends TestCase
{
    use RefreshDatabase;

    private Circuit $circuit;

    private const MTR = <<<'TXT'
    Start: 2026-09-14T15:34:29+0000
    HOST: nodus                                    Loss%   Snt   Last   Avg  Best  Wrst StDev
      1.|-- 10.200.24.254                           0.0%    10    0.4   0.5   0.3   0.9   0.2
      2.|-- 4.4.252.37                              0.0%    10    9.8  10.1   9.5  11.2   0.5
      3.|-- ae1.cr2.orlando1.level3.net            60.0%    10   11.0  11.4  10.8  12.9   0.7
      4.|-- ae31-527.bar3.orlando1.level3.net      40.0%    10   12.1  13.0  11.8  18.4   2.1
      5.|-- ???                                   100.0    10    0.0   0.0   0.0   0.0   0.0
      6.|-- 8.8.8.8                                30.0%    10   14.2  14.9  13.7  19.0   1.8
    TXT;

    protected function setUp(): void
    {
        parent::setUp();
        $site = Site::factory()->create(['name' => '#037 Lawrenceville GA']);
        $this->circuit = Circuit::factory()->create([
            'site_id' => $site->id, 'circuit_id' => '445453113', 'isp_name' => 'Lumen',
            'monitored_ip' => '8.8.8.8', 'monitor_via' => 'icmp', 'status' => 'up',
        ]);
    }

    public function test_the_mtr_report_is_read_hop_by_hop(): void
    {
        $hops = CircuitPathProber::parseMtr(self::MTR);

        $this->assertCount(6, $hops);
        $this->assertSame('ae31-527.bar3.orlando1.level3.net', $hops[3]['host']);
        $this->assertSame(40, $hops[3]['loss_pct']);
        $this->assertSame(13.0, $hops[3]['avg_ms']);
        $this->assertNull($hops[4]['host'], '??? is an unanswered hop, not a host called ???');
        $this->assertSame(100, $hops[4]['loss_pct']);
        $this->assertSame('8.8.8.8', $hops[5]['ip']);
    }

    public function test_the_hop_named_is_the_one_whose_loss_persists(): void
    {
        // Hop 3 reads 60% and hop 4 reads 40%: hop 3 is a core router declining to
        // answer for itself. Hop 4's 40% carries through to the target at 30%. Hop 4 is
        // the answer — the one the carrier actually needed.
        $worst = CircuitPathProber::worstHop(CircuitPathProber::parseMtr(self::MTR));

        $this->assertSame(4, $worst['hop']);
        $this->assertSame('ae31-527.bar3.orlando1.level3.net', $worst['host']);
    }

    public function test_a_rate_limited_hop_with_a_clean_path_behind_it_is_not_blamed(): void
    {
        $hops = CircuitPathProber::parseMtr(<<<'TXT'
          1.|-- 10.200.24.254     0.0%    10    0.4   0.5   0.3   0.9   0.2
          2.|-- 4.4.252.37       80.0%    10    9.8  10.1   9.5  11.2   0.5
          3.|-- 8.8.8.8           0.0%    10   14.2  14.9  13.7  19.0   1.8
        TXT);

        $this->assertNull(CircuitPathProber::worstHop($hops), 'nothing after hop 2 lost anything — the path is fine');
    }

    public function test_traceroute_is_read_the_same_way(): void
    {
        $hops = CircuitPathProber::parseTraceroute(<<<'TXT'
        traceroute to 8.8.8.8 (8.8.8.8), 20 hops max, 60 byte packets
         1  10.200.24.254 (10.200.24.254)  0.412 ms  0.380 ms  0.351 ms
         2  ae31-527.bar3.orlando1.level3.net (4.69.150.10)  12.1 ms  * 13.4 ms
         3  * * *
         4  dns.google (8.8.8.8)  14.2 ms  * *
        TXT);

        $this->assertCount(4, $hops);
        $this->assertSame('ae31-527.bar3.orlando1.level3.net', $hops[1]['host']);
        $this->assertSame('4.69.150.10', $hops[1]['ip']);
        $this->assertSame(33, $hops[1]['loss_pct'], 'one star in three');
        $this->assertSame(100, $hops[2]['loss_pct']);
        $this->assertSame(67, $hops[3]['loss_pct']);
    }

    public function test_a_probe_is_recorded_with_the_hop_and_a_plain_sentence(): void
    {
        $probe = (new CircuitPathProber(fn () => self::MTR, 'mtr'))->probe($this->circuit, 'manual', 'R Abreu');

        $this->assertNotNull($probe);
        $this->assertSame(4, $probe->worst_hop);
        $this->assertSame('ae31-527.bar3.orlando1.level3.net', $probe->worst_hop_host);
        $this->assertSame(30, $probe->end_loss_pct);
        $this->assertStringContainsString('hop 4 (ae31-527.bar3.orlando1.level3.net) at 40%', $probe->summary);
        $this->assertSame('R Abreu', $probe->ran_by);
    }

    public function test_traceroute_records_three_probes_not_twenty(): void
    {
        // The fallback sends three per hop. Stamping mtr's twenty on it claims a
        // resolution the reading does not have.
        $probe = (new CircuitPathProber(fn () => "1  10.200.24.254 (10.200.24.254)  0.4 ms  0.3 ms  0.3 ms\n", 'traceroute'))
            ->probe($this->circuit);

        $this->assertSame(3, $probe->cycles);
    }

    public function test_a_path_that_goes_dark_says_so_instead_of_naming_a_hop(): void
    {
        // Everything from hop 5 on is unanswered. "Loss starts at hop 5 (hop 5)" is not
        // an answer; "no reply beyond hop 5, last to answer was X" is.
        $probe = (new CircuitPathProber(fn () => <<<'TXT'
          1.|-- 10.200.24.254                  0.0%    20    0.4   0.5   0.3   0.9   0.2
          2.|-- 4.4.252.37                     0.0%    20    9.8  10.1   9.5  11.2   0.5
          3.|-- ae1.cr2.orlando1.level3.net    0.0%    20   11.0  11.4  10.8  12.9   0.7
          4.|-- ???                          100.0    20    0.0   0.0   0.0   0.0   0.0
          5.|-- ???                          100.0    20    0.0   0.0   0.0   0.0   0.0
        TXT, 'mtr'))->probe($this->circuit);

        $this->assertStringContainsString('No reply beyond hop 4', $probe->summary);
        $this->assertStringContainsString('ae1.cr2.orlando1.level3.net', $probe->summary, 'the last hop that answered is the one to name');
    }

    public function test_an_empty_trace_records_nothing(): void
    {
        // A failed probe is not an empty path. Recording it would be a clean-looking
        // row saying nothing was wrong.
        $this->assertNull((new CircuitPathProber(fn () => '', 'mtr'))->probe($this->circuit));
        $this->assertSame(0, CircuitPathProbe::count());
    }

    private function seedLossyHistory(): void
    {
        $t = now()->subMinutes(25);
        foreach ([0, 20, 0, 30, 0, 90, 0, 10, 0, 50, 0, 20, 0, 60, 0, 30, 0, 70, 0] as $i => $loss) {
            CircuitMetricHistory::create([
                'circuit_id' => $this->circuit->id, 'recorded_at' => $t->copy()->addMinutes($i),
                'response_time_ms' => 11.0, 'loss_pct' => $loss,
            ]);
        }
    }

    public function test_crossing_into_loss_stamps_since_when_and_captures_the_path(): void
    {
        // The whole point: captured at the crossing, while it is happening.
        $this->seedLossyHistory();
        $monitor = new CircuitMonitor(fn () => ['loss' => 10, 'rtt' => 11.0]);
        $monitor->capturePaths = true;
        $monitor->prober = new CircuitPathProber(fn () => self::MTR, 'mtr');

        $this->assertNull($this->circuit->degraded_since);
        $monitor->check($this->circuit->fresh());

        $c = $this->circuit->fresh();
        $this->assertNotNull($c->degraded_since, 'the crossing is stamped when the poller sees it, not reconstructed later');
        $probe = CircuitPathProbe::where('circuit_id', $c->id)->first();
        $this->assertNotNull($probe, 'and the path was traced at that moment');
        $this->assertSame('auto', $probe->trigger);
        $this->assertSame('ae31-527.bar3.orlando1.level3.net', $probe->worst_hop_host);
    }

    public function test_production_dispatches_the_probe_instead_of_running_it_in_the_sweep(): void
    {
        // The regression this guards: eighteen inline traces on one sweep stretched a
        // sixty-second poll past four minutes and starved the circuits late in the
        // ordering. The sweep must hand the work off and keep moving.
        Queue::fake();
        $this->seedLossyHistory();
        $monitor = new CircuitMonitor(fn () => ['loss' => 10, 'rtt' => 11.0]);
        $monitor->capturePaths = true;   // production wiring, no inline prober

        $monitor->check($this->circuit->fresh());

        Queue::assertPushed(ProbeCircuitPath::class, fn ($job) => $job->circuitId === $this->circuit->id && $job->trigger === 'auto');
        $this->assertSame(0, CircuitPathProbe::count(), 'nothing traced inside the sweep');
        $this->assertNotNull($this->circuit->fresh()->degraded_since, 'the crossing is still stamped immediately');
    }

    public function test_the_job_records_the_probe(): void
    {
        // The other half: when the worker runs it, the row lands.
        $this->app->bind(CircuitPathProber::class, fn () => new CircuitPathProber(fn () => self::MTR, 'mtr'));
        $job = new ProbeCircuitPath($this->circuit->id, 'auto');

        $job->handle();

        $this->assertSame(1, CircuitPathProbe::count());
    }

    public function test_recovery_clears_since_when(): void
    {
        $this->seedLossyHistory();
        $monitor = new CircuitMonitor(fn () => ['loss' => 0, 'rtt' => 11.0]);
        $monitor->capturePaths = true;
        $monitor->prober = new CircuitPathProber(fn () => self::MTR, 'mtr');
        $monitor->check($this->circuit->fresh());
        $this->assertNotNull($this->circuit->fresh()->degraded_since);

        // Twenty clean polls: the window drains.
        for ($i = 0; $i < 22; $i++) {
            $monitor->check($this->circuit->fresh());
        }

        $this->assertNull($this->circuit->fresh()->degraded_since, 'the episode ended');
    }

    public function test_the_episodes_say_since_when_and_how_bad(): void
    {
        $this->seedLossyHistory();

        $episodes = (new CircuitLossEpisodes)->forCircuit($this->circuit, 1);

        $this->assertCount(1, $episodes);
        $this->assertTrue($episodes[0]['ongoing']);
        $this->assertSame(90, $episodes[0]['peak_pct']);
        $this->assertGreaterThanOrEqual(40, $episodes[0]['lossy_pct']);
        // It began at the first lossy poll, not at whichever poll tipped the share.
        $this->assertSame(now()->subMinutes(24)->format('Y-m-d H:i'), (new \DateTime($episodes[0]['started_at']))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i'));
    }

    public function test_the_endpoints_answer_the_carrier(): void
    {
        $this->seedLossyHistory();
        CircuitPathProbe::create([
            'circuit_id' => $this->circuit->id, 'target' => '8.8.8.8', 'trigger' => 'auto', 'tool' => 'mtr',
            'cycles' => 10, 'hops' => CircuitPathProber::parseMtr(self::MTR),
            'worst_hop' => 4, 'worst_hop_host' => 'ae31-527.bar3.orlando1.level3.net', 'worst_hop_loss_pct' => 40,
            'end_loss_pct' => 30, 'summary' => 'x', 'ran_at' => now(),
        ]);
        $viewer = User::factory()->create(['role' => 'viewer', 'is_active' => true]);

        $traces = $this->actingAs($viewer)->getJson("/api/circuits/{$this->circuit->id}/traces")->assertOk()->json('data');
        $episodes = $this->actingAs($viewer)->getJson("/api/circuits/{$this->circuit->id}/loss-episodes?days=1")->assertOk()->json('episodes');

        $this->assertSame('ae31-527.bar3.orlando1.level3.net', $traces[0]['worst_hop_host']);
        $this->assertCount(1, $episodes);
    }

    public function test_a_viewer_cannot_trace_on_demand(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'viewer', 'is_active' => true]))
            ->postJson("/api/circuits/{$this->circuit->id}/trace")
            ->assertForbidden();
    }

    public function test_a_hop_whose_loss_does_not_persist_is_marked_rate_limited(): void
    {
        // The real shape off PROD: our own Docker host reports 45%, a Level3 aggregate
        // reports 20%, and every probe still reaches the destination at 0%. Both are
        // routers declining to answer about themselves.
        $hops = CircuitPathProber::classifyHops([
            ['hop' => 1, 'host' => 's-4wca', 'loss_pct' => 45],
            ['hop' => 2, 'host' => '10.11.10.253', 'loss_pct' => 0],
            ['hop' => 3, 'host' => '10.11.9.230', 'loss_pct' => 0],
            ['hop' => 4, 'host' => '4.18.134.161', 'loss_pct' => 0],
            ['hop' => 5, 'host' => 'ae31-527.bar3.orlando1.level3.net', 'loss_pct' => 20],
            ['hop' => 6, 'host' => '64.159.248.137', 'loss_pct' => 0],
        ]);

        $this->assertTrue($hops[0]['rate_limited'], 'our own host rate-limits its ICMP');
        $this->assertTrue($hops[4]['rate_limited'], 'the carrier aggregate does too');
        $this->assertFalse($hops[1]['rate_limited']);
        $this->assertFalse($hops[5]['rate_limited']);
        // And the verdict must agree with the table: nothing to blame.
        $this->assertNull(CircuitPathProber::worstHop($hops));
    }

    public function test_loss_that_persists_to_the_target_is_never_excused(): void
    {
        $hops = CircuitPathProber::classifyHops([
            ['hop' => 1, 'host' => 'a', 'loss_pct' => 0],
            ['hop' => 2, 'host' => 'b', 'loss_pct' => 40],
            ['hop' => 3, 'host' => 'c', 'loss_pct' => 42],
            ['hop' => 4, 'host' => 'd', 'loss_pct' => 45],
        ]);

        $this->assertFalse($hops[1]['rate_limited'], 'it carries to the end — real loss');
        $this->assertSame(2, CircuitPathProber::worstHop($hops)['hop']);
    }

    public function test_loss_at_the_destination_is_never_excused(): void
    {
        // Nothing follows the last hop to corroborate, so it can never be explained away.
        $hops = CircuitPathProber::classifyHops([
            ['hop' => 1, 'host' => 'a', 'loss_pct' => 0],
            ['hop' => 2, 'host' => 'target', 'loss_pct' => 30],
        ]);

        $this->assertFalse($hops[1]['rate_limited']);
    }
}
