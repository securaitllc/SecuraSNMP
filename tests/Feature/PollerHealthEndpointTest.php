<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * /api/health/pollers turns the heartbeat files into a one-glance answer to
 * "are the pollers actually running?" — so a stalled loop is caught in seconds,
 * not after a recovered circuit has failed to clear for hours.
 */
class PollerHealthEndpointTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('app/pollers');
        @mkdir($this->dir, 0775, true);
        $this->clearState();
    }

    protected function tearDown(): void
    {
        $this->clearState();
        parent::tearDown();
    }

    /**
     * Every marker the loop writes, not just the beat. A leftover .fail or .boot
     * would make the next test's fleet look like it was in a restart loop.
     */
    private function clearState(): void
    {
        foreach (['beat', 'ok', 'fail', 'boot'] as $ext) {
            foreach (glob("{$this->dir}/*.{$ext}") as $f) {
                @unlink($f);
            }
        }
    }

    private function writeBeat(string $label, int $ageSeconds, int $interval): void
    {
        file_put_contents("{$this->dir}/{$label}.beat", (time() - $ageSeconds).' '.$interval."\n");
    }

    public function test_a_fresh_beat_is_ok_and_a_stale_beat_is_flagged(): void
    {
        $this->writeBeat('circuits', ageSeconds: 10, interval: 60);    // fresh
        $this->writeBeat('devices', ageSeconds: 4000, interval: 60);   // stale (>180s)
        // 'health' and the rest: no file → reported missing/stale.

        $user = User::factory()->create(['role' => 'viewer']);

        $res = $this->actingAs($user)->getJson('/api/health/pollers');

        $res->assertStatus(503); // any stale poller → unhealthy overall
        $res->assertJsonPath('healthy', false);

        $byLabel = collect($res->json('pollers'))->keyBy('poller');
        $this->assertSame('ok', $byLabel['circuits']['status']);
        $this->assertFalse($byLabel['circuits']['stale']);
        $this->assertSame('stale', $byLabel['devices']['status']);
        $this->assertTrue($byLabel['devices']['stale']);
        $this->assertSame('missing', $byLabel['health']['status']);
    }

    public function test_all_fresh_beats_report_healthy_200(): void
    {
        // Every heartbeat poller fresh.
        foreach (['circuits', 'devices', 'interfaces', 'health', 'ec-alarms', 'fg-alarms', 'nexthops', 'tunnels-ssh', 'lldp', 'arp', 'addresses', 'macs', 'prune', 'vuln', 'anomaly'] as $label) {
            $this->writeBeat($label, ageSeconds: 5, interval: 60);
        }

        $user = User::factory()->create(['role' => 'viewer']);

        $res = $this->actingAs($user)->getJson('/api/health/pollers');

        $res->assertStatus(200);
        $res->assertJsonPath('healthy', true);
    }

    /**
     * The failure mode this endpoint used to be blind to.
     *
     * A poller killed mid-cycle restarts, writes a fresh beat, and under the old
     * rule read as `ok`. On Massey production the vulnerability loop did that for
     * two months — zero completed cycles, every finding frozen, endpoint green.
     */
    public function test_a_restart_loop_is_not_healthy_even_with_a_fresh_beat(): void
    {
        $this->writeBeat('vuln', ageSeconds: 2, interval: 86400);
        // Nine process starts, no success in between: a cycle that never finishes.
        file_put_contents("{$this->dir}/vuln.boot", time().' 9'."\n");

        $user = User::factory()->create(['role' => 'viewer']);

        $res = $this->actingAs($user)->getJson('/api/health/pollers');

        $res->assertStatus(503);
        $res->assertJsonPath('healthy', false);

        $vuln = collect($res->json('pollers'))->firstWhere('poller', 'vuln');
        $this->assertSame('failing', $vuln['status']);
        $this->assertFalse($vuln['stale'], 'the beat is fresh — staleness is not the signal here');
        $this->assertTrue($vuln['failing']);
        $this->assertSame(9, $vuln['restarts_since_success']);
        $this->assertNull($vuln['last_success_at']);
    }

    public function test_a_recorded_failure_is_reported_with_its_cause(): void
    {
        $this->writeBeat('vuln', ageSeconds: 2, interval: 86400);
        file_put_contents("{$this->dir}/vuln.fail", json_encode([
            'at' => time() - 30,
            'consecutive_failures' => 3,
            'exception' => 'RuntimeException',
            'message' => 'Allowed memory size of 134217728 bytes exhausted',
            'where' => 'ConstraintSet.php:71',
        ]));

        $user = User::factory()->create(['role' => 'viewer']);

        $res = $this->actingAs($user)->getJson('/api/health/pollers');

        $res->assertStatus(503);
        $vuln = collect($res->json('pollers'))->firstWhere('poller', 'vuln');
        $this->assertSame('failing', $vuln['status']);
        $this->assertSame(3, $vuln['last_failure']['consecutive_failures']);
        $this->assertStringContainsString('memory size', $vuln['last_failure']['message']);
        $this->assertSame('ConstraintSet.php:71', $vuln['last_failure']['where']);
    }

    public function test_one_completed_cycle_clears_the_restart_count(): void
    {
        foreach (['circuits', 'devices', 'interfaces', 'health', 'ec-alarms', 'fg-alarms', 'nexthops', 'tunnels-ssh', 'lldp', 'arp', 'addresses', 'macs', 'prune', 'vuln', 'anomaly'] as $label) {
            $this->writeBeat($label, ageSeconds: 5, interval: 60);
        }
        // Restarted twice, then finished a cycle: recordSuccess() resets the counter.
        file_put_contents("{$this->dir}/vuln.boot", time().' 0'."\n");
        file_put_contents("{$this->dir}/vuln.ok", (time() - 20)."\n");

        $user = User::factory()->create(['role' => 'viewer']);

        $res = $this->actingAs($user)->getJson('/api/health/pollers');

        $res->assertStatus(200);
        $res->assertJsonPath('healthy', true);

        $vuln = collect($res->json('pollers'))->firstWhere('poller', 'vuln');
        $this->assertSame('ok', $vuln['status']);
        $this->assertNotNull($vuln['last_success_at']);
    }

    public function test_a_single_restart_is_tolerated_so_a_deploy_is_not_an_alarm(): void
    {
        foreach (['circuits', 'devices', 'interfaces', 'health', 'ec-alarms', 'fg-alarms', 'nexthops', 'tunnels-ssh', 'lldp', 'arp', 'addresses', 'macs', 'prune', 'vuln', 'anomaly'] as $label) {
            $this->writeBeat($label, ageSeconds: 5, interval: 60);
        }
        // One boot, no success yet — a daily poller mid-way through its first cycle.
        file_put_contents("{$this->dir}/vuln.boot", time().' 1'."\n");

        $user = User::factory()->create(['role' => 'viewer']);

        $res = $this->actingAs($user)->getJson('/api/health/pollers');

        $res->assertStatus(200);
        $res->assertJsonPath('healthy', true);
    }
}
