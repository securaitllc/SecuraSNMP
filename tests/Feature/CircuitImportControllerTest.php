<?php

namespace Tests\Feature;

use App\Models\Circuit;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CircuitImportControllerTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $circuits): array
    {
        return ['circuits' => $circuits];
    }

    public function test_it_imports_a_circuit_matched_to_its_site_and_starts_monitoring(): void
    {
        $site = Site::factory()->create(['site_number' => '208']);
        $admin = User::factory()->admin()->create();

        $res = $this->actingAs($admin)->postJson('/api/circuits/import', $this->payload([
            ['site' => 'AL0001-SC208', 'ip' => '24.192.96.1', 'interface' => 'wan0'],
        ]));

        $res->assertOk()->assertJsonPath('created_count', 1);
        $c = Circuit::firstWhere('monitored_ip', '24.192.96.1');
        $this->assertNotNull($c);
        $this->assertSame($site->id, $c->site_id);
        $this->assertSame('wan0', $c->wan_interface);
        $this->assertSame('PENDING-wan0', $c->circuit_id);
        $this->assertSame('static', $c->ip_assignment);
        $this->assertTrue((bool) $c->monitoring_enabled);
    }

    public function test_a_192_168_ip_is_flagged_dhcp(): void
    {
        Site::factory()->create(['site_number' => '003']);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->postJson('/api/circuits/import', $this->payload([
            ['site' => 'FL0003-SC003', 'ip' => '192.168.1.1', 'interface' => 'wan0'],
        ]))->assertOk();

        $this->assertSame('dhcp', Circuit::firstWhere('monitored_ip', '192.168.1.1')->ip_assignment);
    }

    public function test_an_existing_circuit_ip_is_skipped(): void
    {
        $site = Site::factory()->create(['site_number' => '208']);
        Circuit::factory()->create(['site_id' => $site->id, 'monitored_ip' => '24.192.96.1']);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->postJson('/api/circuits/import', $this->payload([
            ['site' => 'AL0001-SC208', 'ip' => '24.192.96.1', 'interface' => 'wan0'],
        ]))->assertJsonPath('created_count', 0)->assertJsonPath('skipped_existing_count', 1);
    }

    public function test_unmatched_site_is_reported_not_created(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->postJson('/api/circuits/import', $this->payload([
            ['site' => 'TX9999-SC777', 'ip' => '10.9.9.1', 'interface' => 'wan0'],
        ]))->assertJsonPath('created_count', 0)->assertJsonPath('unmatched_site_count', 1);
    }

    public function test_dry_run_writes_nothing(): void
    {
        Site::factory()->create(['site_number' => '208']);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->postJson('/api/circuits/import', [
            'circuits' => [['site' => 'AL0001-SC208', 'ip' => '24.192.96.1', 'interface' => 'wan0']],
            'dry_run' => true,
        ])->assertJsonPath('created_count', 1);
        $this->assertDatabaseCount('circuits', 0);
    }

    public function test_viewer_cannot_import_circuits(): void
    {
        $this->postJson('/api/circuits/import', $this->payload([['site' => 'x', 'ip' => '1.1.1.1', 'interface' => 'wan0']]))
            ->assertUnauthorized();
    }
}
