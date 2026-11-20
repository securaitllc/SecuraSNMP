<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\TopologyPosition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * A saved node position only means something on the canvas it was saved for.
 *
 * The site view moved from left-to-right tiers to top-down tiers. HQ Orlando had
 * been hand-arranged under the old layout, so its saved coordinates spanned the
 * old wide canvas and the new drawing came out as a thumbnail. Old positions are
 * kept but no longer applied; a new save writes the current layout's tag.
 */
class TopologyPositionsLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_positions_saved_under_the_old_layout_are_not_applied(): void
    {
        $site = Site::factory()->create();
        TopologyPosition::create(['site_id' => $site->id, 'node_id' => 'sw-1', 'layout' => 'h1', 'x' => 1800, 'y' => 40]);
        Cache::flush();

        $positions = $this->actingAs(User::factory()->create())
            ->getJson("/api/sites/{$site->id}/topology")->assertOk()->json('positions');

        $this->assertSame([], (array) $positions, 'coordinates from the old canvas must not drag nodes off the new one');
    }

    public function test_a_new_save_is_tagged_with_the_current_layout_and_applied(): void
    {
        $site = Site::factory()->create();

        $this->actingAs(User::factory()->create(['role' => 'analyst']))
            ->postJson("/api/sites/{$site->id}/topology/positions", ['positions' => ['sw-1' => ['x' => 120, 'y' => 300]]])
            ->assertOk();
        Cache::flush();

        $this->assertSame(TopologyPosition::LAYOUT, TopologyPosition::where('node_id', 'sw-1')->value('layout'));
        $positions = $this->actingAs(User::factory()->create())
            ->getJson("/api/sites/{$site->id}/topology")->assertOk()->json('positions');
        $this->assertEquals(['x' => 120, 'y' => 300], $positions['sw-1']);
    }
}
