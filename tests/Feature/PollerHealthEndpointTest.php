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
        foreach (glob("{$this->dir}/*.beat") as $f) {
            @unlink($f);
        }
    }

    protected function tearDown(): void
    {
        foreach (glob("{$this->dir}/*.beat") as $f) {
            @unlink($f);
        }
        parent::tearDown();
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
        foreach (['circuits', 'devices', 'interfaces', 'health', 'ec-alarms', 'nexthops', 'tunnels-ssh', 'lldp', 'prune', 'vuln'] as $label) {
            $this->writeBeat($label, ageSeconds: 5, interval: 60);
        }

        $user = User::factory()->create(['role' => 'viewer']);

        $res = $this->actingAs($user)->getJson('/api/health/pollers');

        $res->assertStatus(200);
        $res->assertJsonPath('healthy', true);
    }
}
