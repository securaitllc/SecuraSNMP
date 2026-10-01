<?php

namespace Tests\Feature;

use App\Models\Circuit;
use App\Models\CircuitPathProbe;
use App\Models\Site;
use App\Models\User;
use App\Services\CarrierCorrelation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The shape this was built for: Massey, 14 September 2026. Seventeen Lumen circuits
 * lossy at once, clustered by destination prefix, every other carrier clean.
 */
class CarrierCorrelationTest extends TestCase
{
    use RefreshDatabase;

    private int $octet = 1;

    private function circuit(string $isp, string $ip, int $lossPolls, int $peak = 0, array $extra = []): Circuit
    {
        return Circuit::factory()->for(Site::factory())->create(array_merge([
            'isp_name' => $isp,
            'monitored_ip' => $ip,
            'loss_polls_pct' => $lossPolls,
            'loss_peak_pct' => $peak,
            'monitoring_enabled' => true,
        ], $extra));
    }

    /** Distinct addresses inside one /16 so the group is real and the IPs are not. */
    private function inPrefix(string $isp, string $prefix, int $count, int $lossPolls, int $peak = 0): void
    {
        [$a, $b] = explode('.', $prefix);
        for ($i = 0; $i < $count; $i++) {
            $this->circuit($isp, "{$a}.{$b}.".intdiv($this->octet, 250).'.'.($this->octet % 250 + 1), $lossPolls, $peak);
            $this->octet++;
        }
    }

    public function test_it_finds_the_carrier_and_names_the_hot_prefix(): void
    {
        // Lumen: lossy in 64.159 and 4.4, perfectly clean in 4.42.
        $this->inPrefix('Lumen', '64.159.0.0', 10, 47, 90);
        $this->inPrefix('Lumen', '64.159.0.0', 4, 0);
        $this->inPrefix('Lumen', '4.4.0.0', 2, 46, 90);
        $this->inPrefix('Lumen', '4.4.0.0', 2, 0);
        $this->inPrefix('Lumen', '4.42.0.0', 34, 0);

        $findings = (new CarrierCorrelation)->findings();

        $this->assertCount(1, $findings);
        $f = $findings[0];
        $this->assertSame('Lumen', $f['isp_name']);
        $this->assertSame(12, $f['circuits_degraded']);
        $this->assertSame(52, $f['circuits_total']);
        $this->assertSame(90, $f['peak_loss_pct']);

        $hot = collect($f['hot_prefixes'])->pluck('prefix')->all();
        $this->assertContains('64.159.0.0/16', $hot);
        // 2 of 4 is 50% — over the bar, and it is where #037 lives. It must not be
        // dropped just because the group is small.
        $this->assertContains('4.4.0.0/16', $hot);

        // The exoneration: the largest group, same carrier, same collector, untouched.
        $clean = collect($f['clean_prefixes'])->firstWhere('prefix', '4.42.0.0/16');
        $this->assertNotNull($clean);
        $this->assertSame(34, $clean['total']);
        $this->assertSame(0, $clean['degraded']);
    }

    public function test_it_carries_the_references_a_carrier_asks_for(): void
    {
        // A Lumen tech asks for THEIR circuit ID, and often the LEC one instead. Both go
        // out so nobody retypes a nine-digit number off a screen onto a live call.
        $this->inPrefix('Lumen', '64.159.0.0', 3, 47, 90);
        Circuit::query()->update(['lec_circuit_id' => 'GA/KXFN/055745/LUME', 'account_number' => '88214417']);

        $row = (new CarrierCorrelation)->findings()[0]['circuits'][0];

        $this->assertSame('GA/KXFN/055745/LUME', $row['lec_circuit_id']);
        $this->assertSame('88214417', $row['account_number']);
        $this->assertNotNull($row['circuit_id']);
    }

    public function test_a_missing_lec_reference_stays_missing(): void
    {
        // Half the fleet has no LEC circuit ID. An absent reference must read as absent,
        // never be filled in with ours — a wrong ID on a carrier ticket costs a day.
        $this->inPrefix('Lumen', '64.159.0.0', 3, 47, 90);

        $row = (new CarrierCorrelation)->findings()[0]['circuits'][0];

        $this->assertNull($row['lec_circuit_id']);
    }

    public function test_a_clean_carrier_produces_nothing(): void
    {
        $this->inPrefix('Spectrum', '96.120.0.0', 47, 0);

        $this->assertSame([], (new CarrierCorrelation)->findings());
    }

    public function test_two_degraded_circuits_are_not_a_pattern(): void
    {
        $this->inPrefix('Comcast', '68.87.0.0', 2, 60, 100);
        $this->inPrefix('Comcast', '68.87.0.0', 30, 0);

        $this->assertSame([], (new CarrierCorrelation)->findings());
    }

    public function test_paused_circuits_are_excluded(): void
    {
        // Circuit pause is a full mute. A muted circuit must not feed a fleet finding.
        for ($i = 0; $i < 4; $i++) {
            $this->circuit('Lumen', "64.159.9.{$i}", 80, 90, ['monitoring_enabled' => false]);
        }
        $this->inPrefix('Lumen', '64.159.0.0', 10, 0);

        $this->assertSame([], (new CarrierCorrelation)->findings());
    }

    public function test_it_reports_the_hop_most_circuits_blame(): void
    {
        $this->inPrefix('Lumen', '64.159.0.0', 4, 47, 90);
        $this->inPrefix('Lumen', '64.159.0.0', 4, 0);

        $lossy = Circuit::where('loss_polls_pct', 47)->get();
        foreach ($lossy->take(3) as $c) {
            CircuitPathProbe::create([
                'circuit_id' => $c->id, 'target' => $c->monitored_ip, 'trigger' => 'auto',
                'tool' => 'mtr', 'cycles' => 20, 'hops' => [],
                'worst_hop' => 4, 'worst_hop_host' => 'ae31-527.bar3.orlando1.level3.net',
                'worst_hop_loss_pct' => 45, 'ran_at' => now(),
            ]);
        }
        CircuitPathProbe::create([
            'circuit_id' => $lossy->last()->id, 'target' => $lossy->last()->monitored_ip,
            'trigger' => 'auto', 'tool' => 'mtr', 'cycles' => 20, 'hops' => [],
            'worst_hop' => 5, 'worst_hop_host' => 'elsewhere.level3.net',
            'worst_hop_loss_pct' => 10, 'ran_at' => now(),
        ]);

        $hop = (new CarrierCorrelation)->findings()[0]['suspect_hop'];

        $this->assertSame('ae31-527.bar3.orlando1.level3.net', $hop['host']);
        $this->assertSame(3, $hop['named_by']);
        $this->assertSame(4, $hop['of_probed']);
        $this->assertSame(45, $hop['worst_loss_pct']);
        // No healthy circuit was traced across it, so there is nothing to contradict
        // the finding and it stands.
        $this->assertTrue($hop['discriminating']);
    }

    public function test_a_hop_clean_for_the_healthy_and_lossy_for_the_sick_is_a_suspect(): void
    {
        // The shape that IS worth a carrier's time: the same hop, on the same paths,
        // treating the degraded circuits differently from the healthy ones. Contrast
        // with the policer case below, where it loses packets for everybody.
        $this->inPrefix('Lumen', '64.159.0.0', 3, 47, 90);
        $this->inPrefix('Lumen', '64.159.0.0', 4, 0);

        foreach (Circuit::where('loss_polls_pct', 47)->get() as $c) {
            CircuitPathProbe::create([
                'circuit_id' => $c->id, 'target' => $c->monitored_ip, 'trigger' => 'auto',
                'tool' => 'mtr', 'cycles' => 20, 'hops' => [],
                'worst_hop' => 5, 'worst_hop_host' => 'ae31-527.bar3.orlando1.level3.net',
                'worst_hop_loss_pct' => 40, 'end_loss_pct' => 40, 'ran_at' => now(),
            ]);
        }

        // The control group: healthy circuits whose path crosses the very same hop
        // without trouble.
        foreach (Circuit::where('loss_polls_pct', 0)->get() as $c) {
            CircuitPathProbe::create([
                'circuit_id' => $c->id, 'target' => $c->monitored_ip, 'trigger' => 'auto',
                'tool' => 'mtr', 'cycles' => 20,
                'hops' => [
                    ['hop' => 5, 'host' => 'ae31-527.bar3.orlando1.level3.net', 'loss_pct' => 0],
                    ['hop' => 6, 'host' => $c->monitored_ip, 'loss_pct' => 0],
                ],
                'worst_hop' => null, 'worst_hop_host' => null,
                'worst_hop_loss_pct' => null, 'end_loss_pct' => 0, 'ran_at' => now(),
            ]);
        }

        $hop = (new CarrierCorrelation)->findings()[0]['suspect_hop'];

        $this->assertSame('ae31-527.bar3.orlando1.level3.net', $hop['host']);
        $this->assertSame(4, $hop['healthy_crossings'], 'the control group must be counted');
        $this->assertSame(0, $hop['healthy_crossings_lossy']);
        // Clean for the healthy, lossy for the degraded: it DOES separate them, so it
        // stands. What sank the real ae31-527 finding was the opposite — loss there
        // for healthy circuits too.
        $this->assertTrue($hop['discriminating']);
    }

    public function test_a_hop_lossy_for_the_healthy_too_is_a_policer_not_a_fault(): void
    {
        // The refutation, not the corroboration. If a hop loses packets for circuits
        // that are nonetheless healthy end to end, then loss at that hop does not
        // cause loss to the destination — it is policing the ICMP addressed to it.
        // ae31-527 on 18 September: 7 of the 8 healthy circuits crossing it saw loss
        // there while reaching their targets fine.
        $this->inPrefix('Lumen', '64.159.0.0', 3, 47, 90);
        $this->inPrefix('Lumen', '64.159.0.0', 2, 0);

        foreach (Circuit::where('loss_polls_pct', 47)->get() as $c) {
            CircuitPathProbe::create([
                'circuit_id' => $c->id, 'target' => $c->monitored_ip, 'trigger' => 'auto',
                'tool' => 'mtr', 'cycles' => 20, 'hops' => [],
                'worst_hop' => 5, 'worst_hop_host' => 'bad.level3.net',
                'worst_hop_loss_pct' => 40, 'end_loss_pct' => 40, 'ran_at' => now(),
            ]);
        }
        foreach (Circuit::where('loss_polls_pct', 0)->get() as $c) {
            CircuitPathProbe::create([
                'circuit_id' => $c->id, 'target' => $c->monitored_ip, 'trigger' => 'auto',
                'tool' => 'mtr', 'cycles' => 20,
                'hops' => [['hop' => 5, 'host' => 'bad.level3.net', 'loss_pct' => 12]],
                'worst_hop' => null, 'worst_hop_host' => null,
                'worst_hop_loss_pct' => null, 'end_loss_pct' => 0, 'ran_at' => now(),
            ]);
        }

        $hop = (new CarrierCorrelation)->findings()[0]['suspect_hop'];

        $this->assertSame(2, $hop['healthy_crossings']);
        $this->assertSame(2, $hop['healthy_crossings_lossy']);
        $this->assertFalse($hop['discriminating'], 'lossy for everyone means it explains nothing');
    }

    public function test_the_finding_reports_loss_that_reached_the_destination(): void
    {
        // Loss at a hop is what a carrier's ICMP policers produce and the first thing
        // they will, correctly, dismiss. Loss at the far end is the number that survives
        // the argument, so the finding has to carry it.
        $this->inPrefix('Lumen', '64.159.0.0', 3, 47, 90);

        foreach (Circuit::where('loss_polls_pct', 47)->get() as $c) {
            CircuitPathProbe::create([
                'circuit_id' => $c->id, 'target' => $c->monitored_ip, 'trigger' => 'auto',
                'tool' => 'mtr', 'cycles' => 20, 'hops' => [],
                'worst_hop' => 5, 'worst_hop_host' => 'bad.level3.net',
                'worst_hop_loss_pct' => 25, 'end_loss_pct' => 70, 'ran_at' => now(),
            ]);
        }

        $this->assertSame(70, (new CarrierCorrelation)->findings()[0]['suspect_hop']['end_loss_pct']);
    }

    public function test_only_the_newest_probe_per_circuit_counts(): void
    {
        // A hop that was to blame yesterday must not outvote today's answer.
        $this->inPrefix('Lumen', '64.159.0.0', 3, 47, 90);
        foreach (Circuit::all() as $c) {
            CircuitPathProbe::create([
                'circuit_id' => $c->id, 'target' => $c->monitored_ip, 'trigger' => 'auto',
                'tool' => 'mtr', 'cycles' => 20, 'hops' => [], 'worst_hop' => 3,
                'worst_hop_host' => 'stale.level3.net', 'worst_hop_loss_pct' => 80,
                'ran_at' => now()->subDay(),
            ]);
            CircuitPathProbe::create([
                'circuit_id' => $c->id, 'target' => $c->monitored_ip, 'trigger' => 'auto',
                'tool' => 'mtr', 'cycles' => 20, 'hops' => [], 'worst_hop' => 4,
                'worst_hop_host' => 'current.level3.net', 'worst_hop_loss_pct' => 40,
                'ran_at' => now(),
            ]);
        }

        $hop = (new CarrierCorrelation)->findings()[0]['suspect_hop'];

        $this->assertSame('current.level3.net', $hop['host']);
        $this->assertSame(3, $hop['of_probed']);
    }

    public function test_the_anomalies_feed_carries_it_and_scoped_calls_do_not(): void
    {
        $this->inPrefix('Lumen', '64.159.0.0', 5, 47, 90);
        $this->inPrefix('Lumen', '4.42.0.0', 20, 0);

        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)->getJson('/api/anomalies')
            ->assertOk()
            ->assertJsonPath('correlations.0.isp_name', 'Lumen')
            ->assertJsonPath('correlations.0.circuits_degraded', 5);

        // A single circuit cannot show a fleet pattern, and asking would cost a full scan.
        $one = Circuit::first();
        $this->actingAs($user)->getJson("/api/anomalies?circuit={$one->id}")
            ->assertOk()
            ->assertJsonPath('correlations', []);
    }
}
