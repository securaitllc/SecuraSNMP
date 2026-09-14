<?php

namespace Tests\Feature;

use App\Models\Circuit;
use App\Models\CircuitAlert;
use App\Models\CircuitMetricHistory;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CircuitDedupeTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_reports_dupes_and_writes_nothing(): void
    {
        $admin = User::factory()->admin()->create();
        $site = Site::factory()->create();
        Circuit::factory()->create(['site_id' => $site->id, 'monitored_ip' => '72.17.32.73', 'circuit_id' => 'PENDING-wan0']);
        Circuit::factory()->create(['site_id' => $site->id, 'monitored_ip' => '72.17.32.73', 'circuit_id' => 'CKT-REAL-1', 'isp_name' => 'Spectrum']);

        $res = $this->actingAs($admin)->postJson('/api/circuits/dedupe', ['dry_run' => true])->assertOk();
        $res->assertJsonPath('dry_run', true)->assertJsonPath('groups', 1)->assertJsonPath('would_delete', 1);
        $this->assertSame(2, Circuit::count(), 'dry run must not delete');
    }

    public function test_apply_keeps_the_most_complete_row_and_moves_history(): void
    {
        $admin = User::factory()->admin()->create();
        $site = Site::factory()->create();
        $placeholder = Circuit::factory()->create(['site_id' => $site->id, 'monitored_ip' => '72.17.32.73', 'circuit_id' => 'PENDING-wan0', 'isp_name' => 'Pending']);
        $real = Circuit::factory()->create(['site_id' => $site->id, 'monitored_ip' => '72.17.32.73', 'circuit_id' => 'CKT-REAL-1', 'isp_name' => 'Spectrum', 'gateway_ip' => '72.17.32.1']);

        // History hangs off the placeholder — it must survive on the keeper.
        CircuitAlert::create(['circuit_id' => $placeholder->id, 'started_at' => now()]);
        CircuitMetricHistory::create(['circuit_id' => $placeholder->id, 'recorded_at' => now(), 'response_time_ms' => 12.5]);

        $this->actingAs($admin)->postJson('/api/circuits/dedupe', ['dry_run' => false])
            ->assertOk()->assertJsonPath('deleted', 1);

        $this->assertNull(Circuit::find($placeholder->id), 'placeholder deleted');
        $this->assertNotNull(Circuit::find($real->id), 'real CID kept');
        $this->assertSame($real->id, CircuitAlert::first()->circuit_id, 'alert moved to keeper');
        $this->assertSame($real->id, CircuitMetricHistory::first()->circuit_id, 'history moved to keeper');
    }

    public function test_distinct_ips_are_never_merged(): void
    {
        $admin = User::factory()->admin()->create();
        $site = Site::factory()->create();
        Circuit::factory()->create(['site_id' => $site->id, 'monitored_ip' => '10.0.0.1']);
        Circuit::factory()->create(['site_id' => $site->id, 'monitored_ip' => '10.0.0.2']);

        $this->actingAs($admin)->postJson('/api/circuits/dedupe', ['dry_run' => false])
            ->assertOk()->assertJsonPath('deleted', 0);
        $this->assertSame(2, Circuit::count());
    }

    public function test_a_viewer_cannot_dedupe(): void
    {
        $viewer = User::factory()->create();
        $this->actingAs($viewer)->postJson('/api/circuits/dedupe', ['dry_run' => true])->assertForbidden();
    }
}
