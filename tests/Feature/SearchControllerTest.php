<?php

namespace Tests\Feature;

use App\Models\Circuit;
use App\Models\Device;
use App\Models\DeviceAlarm;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchControllerTest extends TestCase
{
    use RefreshDatabase;

    /** INJ-01: `%%` is two literal percent signs, not a scan of five tables. */
    public function test_inj01_wildcard_search_term_does_not_match_every_row(): void
    {
        $site = Site::factory()->create(['name' => 'Orlando']);
        Device::factory()->create(['site_id' => $site->id, 'name' => 'ORL-SW1']);
        Circuit::factory()->create(['site_id' => $site->id]);

        $results = $this->actingAs(User::factory()->create())->getJson('/api/search?q=%25%25')->assertOk()->json();

        $this->assertSame([], $results, 'a literal %% must not act as a wildcard');
    }

    /** INJ-02: an escaped `_` matches only itself on every driver. */
    public function test_inj02_underscore_matches_literally(): void
    {
        $site = Site::factory()->create();
        Device::factory()->create(['site_id' => $site->id, 'name' => 'core_sw']);
        Device::factory()->create(['site_id' => $site->id, 'name' => 'corexsw']);

        $labels = array_column($this->actingAs(User::factory()->create())->getJson('/api/search?q=core_')->assertOk()->json(), 'label');

        $this->assertSame(['core_sw'], $labels);
    }

    /** INJ-02: the alarm log search escapes through the same ESCAPE-clause helper. */
    public function test_inj02_alarm_log_search_matches_wildcards_literally(): void
    {
        $device = Device::factory()->create(['site_id' => Site::factory()->create()->id]);
        DeviceAlarm::factory()->create(['device_id' => $device->id, 'description' => 'Disk 95% full']);
        DeviceAlarm::factory()->create(['device_id' => $device->id, 'description' => 'Disk 950 full']);
        $user = User::factory()->create();

        $this->assertSame(['Disk 95% full'], array_column($this->actingAs($user)->getJson('/api/alarms/log?q='.urlencode('95%'))->assertOk()->json('alarms'), 'description'));
    }
}
