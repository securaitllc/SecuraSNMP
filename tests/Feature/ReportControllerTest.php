<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_lists_report_types_with_fields(): void
    {
        $viewer = User::factory()->create();

        $res = $this->actingAs($viewer)->getJson('/api/reports/catalog');

        $res->assertOk()->assertJsonStructure(['reports' => [['type', 'label', 'time_scoped', 'supports_role', 'fields']]]);
        $this->assertContains('circuit-availability', array_column($res->json('reports'), 'type'));
    }

    public function test_generate_returns_columns_and_rows(): void
    {
        $viewer = User::factory()->create();
        Device::factory()->create(['site_id' => Site::factory()->create()->id, 'name' => 'SW1']);

        $res = $this->actingAs($viewer)->getJson('/api/reports/device-inventory?fields[]=name&fields[]=vendor');

        $res->assertOk()
            ->assertJsonPath('columns.0.key', 'name')
            ->assertJsonPath('rows.0.name', 'SW1');
    }

    public function test_unknown_report_type_is_404(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/api/reports/not-a-report')->assertNotFound();
    }

    public function test_export_streams_a_spreadsheet(): void
    {
        $viewer = User::factory()->create();
        Device::factory()->create(['site_id' => Site::factory()->create()->id]);

        $res = $this->actingAs($viewer)->get('/api/reports/device-inventory/export');

        $res->assertOk();
        $this->assertStringContainsString('spreadsheetml', $res->headers->get('Content-Type'));
    }

    public function test_guest_cannot_access_reports(): void
    {
        $this->getJson('/api/reports/catalog')->assertStatus(401);
    }
}
