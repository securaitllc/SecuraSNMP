<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteImportControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_imports_new_sites_dedups_by_building_and_skips_existing(): void
    {
        $admin = User::factory()->admin()->create();
        Site::factory()->create(['name' => '#003 Winter Garden']); // already in the app

        $payload = ['sites' => [
            ['number' => '01', 'name' => 'North Orlando', 'address' => '1710 W Fairbanks Ave Ste 120, Winter Park FL 32789', 'region' => 'FL', 'gm_name' => 'Pat Lee', 'gm_ext' => '400101'],
            ['number' => '02', 'name' => 'GU Orange', 'address' => '1710 W Fairbanks Ave Ste 140, Winter Park FL 32789'], // same building → merged into 001
            ['number' => '03', 'name' => 'Winter Garden', 'address' => '12857 W Colonial Dr'], // exists → skipped
            ['number' => '04', 'name' => 'Cocoa', 'address' => '571-R Haverty Ct Rockledge FL 32955'], // new
        ]];

        $res = $this->actingAs($admin)->postJson('/api/sites/import', $payload)->assertOk();
        $res->assertJsonPath('created_count', 2);
        $res->assertJsonPath('merged_duplicates', ['002']);
        // #003 existed (by #NNN in name) but with no site_number → enriched, not skipped.
        $res->assertJsonPath('enriched', ['003']);

        $this->assertDatabaseHas('sites', ['site_number' => '001', 'name' => '#001 North Orlando', 'gm_name' => 'Pat Lee', 'gm_ext' => '400101']);
        $this->assertDatabaseHas('sites', ['site_number' => '004']);
        $this->assertDatabaseMissing('sites', ['site_number' => '002']);
    }

    public function test_it_enriches_an_existing_site_filling_only_empty_contacts(): void
    {
        $admin = User::factory()->admin()->create();
        // Site exists by #NNN in name, no site_number, GM already set, no SM.
        Site::factory()->create(['name' => '#208 Huntsville AL', 'site_number' => null, 'gm_name' => 'Existing GM', 'sm_name' => null]);

        $res = $this->actingAs($admin)->postJson('/api/sites/import', ['sites' => [[
            'number' => '208', 'name' => 'Huntsville', 'main_phone' => '256-000-1111',
            'gm_name' => 'Directory GM', 'sm_name' => 'New SM', 'sm_phone' => '256-222-3333',
        ]]])->assertOk();

        $res->assertJsonPath('enriched', ['208']);
        $site = Site::firstWhere('name', '#208 Huntsville AL');
        $this->assertSame('208', $site->site_number);          // filled
        $this->assertSame('256-000-1111', $site->main_phone);  // filled
        $this->assertSame('New SM', $site->sm_name);           // filled (was empty)
        $this->assertSame('Existing GM', $site->gm_name);      // NOT overwritten
    }

    public function test_dry_run_reports_but_writes_nothing(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->postJson('/api/sites/import', [
            'dry_run' => true,
            'sites' => [['number' => '05', 'name' => 'Ocala', 'address' => '10 SW 49th Ave']],
        ])->assertOk()->assertJsonPath('created_count', 1)->assertJsonPath('dry_run', true);

        $this->assertDatabaseMissing('sites', ['site_number' => '005']);
    }

    public function test_a_viewer_cannot_import(): void
    {
        $viewer = User::factory()->create();
        $this->actingAs($viewer)->postJson('/api/sites/import', ['sites' => [['number' => '01', 'name' => 'X']]])->assertForbidden();
    }
}
