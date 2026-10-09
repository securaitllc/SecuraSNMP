<?php

namespace Tests\Feature\Flow;

use App\Models\Device;
use App\Models\FlowRecord;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlowControllerTest extends TestCase
{
    use RefreshDatabase;

    private function seedFlows(Device $device): void
    {
        $now = now()->subMinutes(5);
        $flows = [
            ['10.86.10.42', '52.96.7.44', 443, 'tcp', 'Microsoft 365', 'SaaS', 'outbound', 1_000_000],
            ['10.86.10.42', '52.96.7.44', 443, 'tcp', 'Microsoft 365', 'SaaS', 'outbound', 240_000],
            ['10.86.10.15', '34.120.88.9', 443, 'tcp', 'Video surveillance', 'Streaming', 'outbound', 980_000],
            ['10.86.10.55', '10.0.0.20', 5060, 'udp', 'Voice (SIP)', 'Voice', 'east-west', 420_000],
        ];
        foreach ($flows as [$s, $d, $p, $proto, $app, $cat, $dir, $bytes]) {
            FlowRecord::create([
                'device_id' => $device->id, 'if_index' => 3, 'src_ip' => $s, 'dst_ip' => $d,
                'dst_port' => $p, 'protocol' => $proto, 'app' => $app, 'app_category' => $cat,
                'direction' => $dir, 'bytes' => $bytes, 'packets' => 10, 'recorded_at' => $now,
            ]);
        }
    }

    public function test_top_talkers_aggregates_and_ranks_by_bytes(): void
    {
        $device = Device::factory()->create(['site_id' => Site::factory()->create()->id]);
        $this->seedFlows($device);

        $res = $this->actingAs(User::factory()->create())->getJson("/api/devices/{$device->id}/flows/top-talkers");

        $res->assertOk();
        $talkers = $res->json('talkers');
        // The M365 pair (1.24M summed) is #1; the two rows collapse into one.
        $this->assertSame('10.86.10.42', $talkers[0]['src_ip']);
        $this->assertSame(1_240_000, (int) $talkers[0]['bytes']);
        $this->assertSame(2, (int) $talkers[0]['flows']);
    }

    public function test_apps_breakdown_sums_per_application(): void
    {
        $device = Device::factory()->create(['site_id' => Site::factory()->create()->id]);
        $this->seedFlows($device);

        $apps = $this->actingAs(User::factory()->create())->getJson("/api/devices/{$device->id}/flows/apps")->json('apps');
        $byApp = collect($apps)->keyBy('app');

        $this->assertSame(1_240_000, (int) $byApp['Microsoft 365']['bytes']);
        $this->assertSame('SaaS', $byApp['Microsoft 365']['app_category']);
    }

    public function test_exporters_lists_devices_with_flows_busiest_first(): void
    {
        $busy = Device::factory()->create(['site_id' => Site::factory()->create()->id, 'name' => 'busy-fw']);
        $quiet = Device::factory()->create(['site_id' => Site::factory()->create()->id, 'name' => 'quiet-sw']);
        FlowRecord::create(['device_id' => $busy->id, 'src_ip' => '10.0.0.1', 'dst_ip' => '8.8.8.8', 'protocol' => 'udp', 'bytes' => 5000, 'packets' => 1, 'recorded_at' => now()->subMinutes(2)]);
        FlowRecord::create(['device_id' => $quiet->id, 'src_ip' => '10.0.0.2', 'dst_ip' => '8.8.8.8', 'protocol' => 'udp', 'bytes' => 100, 'packets' => 1, 'recorded_at' => now()->subMinutes(2)]);

        $ex = $this->actingAs(User::factory()->create())->getJson('/api/flows/exporters?hours=6')->assertOk()->json('exporters');

        $this->assertCount(2, $ex);
        $this->assertSame($busy->id, $ex[0]['device_id'], 'busiest exporter first');
        $this->assertSame('busy-fw', $ex[0]['name']);
    }

    public function test_overview_is_fleet_wide_and_kql_filterable(): void
    {
        $fw = Device::factory()->create(['site_id' => Site::factory()->create()->id, 'name' => 'HQ-FW']);
        $sw = Device::factory()->create(['site_id' => Site::factory()->create()->id, 'name' => 'BR-SW']);
        $this->seedFlows($fw);
        FlowRecord::create(['device_id' => $sw->id, 'src_ip' => '10.9.9.9', 'dst_ip' => '1.1.1.1', 'protocol' => 'udp', 'app' => 'DNS', 'app_category' => 'Infrastructure', 'direction' => 'outbound', 'bytes' => 999, 'packets' => 1, 'recorded_at' => now()->subMinutes(3)]);

        $user = User::factory()->create();
        // Fleet-wide: both devices' flows counted.
        $all = $this->actingAs($user)->getJson('/api/flows/overview')->assertOk()->json();
        $this->assertSame(5, $all['summary']['flows'], '4 fw + 1 sw');

        // Narrowed by KQL device filter.
        $scoped = $this->actingAs($user)->getJson('/api/flows/overview?q='.urlencode('Device == "BR-SW"'))->assertOk()->json();
        $this->assertSame(1, $scoped['summary']['flows'], 'only the switch flow');
    }

    public function test_timeseries_buckets_bytes_by_app(): void
    {
        $d = Device::factory()->create(['site_id' => Site::factory()->create()->id]);
        FlowRecord::create(['device_id' => $d->id, 'src_ip' => '10.0.0.1', 'dst_ip' => '8.8.8.8', 'protocol' => 'tcp', 'app' => 'Microsoft 365', 'app_category' => 'SaaS', 'direction' => 'outbound', 'bytes' => 5000, 'packets' => 3, 'recorded_at' => now()->subMinutes(10)]);
        FlowRecord::create(['device_id' => $d->id, 'src_ip' => '10.0.0.2', 'dst_ip' => '9.9.9.9', 'protocol' => 'udp', 'app' => 'DNS', 'app_category' => 'Infrastructure', 'direction' => 'outbound', 'bytes' => 200, 'packets' => 1, 'recorded_at' => now()->subMinutes(10)]);

        $res = $this->actingAs(User::factory()->create())->getJson('/api/flows/timeseries?hours=6')->assertOk()->json();

        $names = array_column($res['series'], 'name');
        $this->assertContains('Microsoft 365', $names);
        // Each series has time-bucketed [ts_ms, bytes] points, and M365's bytes sum to 5000.
        $m365 = collect($res['series'])->firstWhere('name', 'Microsoft 365');
        $this->assertSame(5000, array_sum(array_map(fn ($p) => $p[1], $m365['data'])));
    }

    public function test_overview_apps_merge_rollups_with_the_current_raw_hour(): void
    {
        $device = Device::factory()->create(['site_id' => Site::factory()->create()->id]);
        // A completed-hour rollup (2h ago) for Microsoft 365, plus a raw flow in the
        // current hour for the same app — the overview must SUM both.
        \App\Models\FlowRollup::create([
            'device_id' => $device->id, 'if_index' => 0, 'bucket' => 'hour',
            'bucket_start' => now()->subHours(2)->startOfHour(), 'group_type' => 'app',
            'group_key' => 'Microsoft 365', 'sub_key' => '', 'app_category' => 'SaaS',
            'bytes' => 1_000_000, 'packets' => 500, 'flows' => 50,
        ]);
        FlowRecord::create(['device_id' => $device->id, 'src_ip' => '10.0.0.1', 'dst_ip' => '52.96.7.44',
            'protocol' => 'tcp', 'app' => 'Microsoft 365', 'app_category' => 'SaaS', 'direction' => 'outbound',
            'bytes' => 250_000, 'packets' => 5, 'recorded_at' => now()->subMinutes(5)]);

        // 6h window → rollup-backed apps path (unfiltered).
        $apps = $this->actingAs(User::factory()->create())->getJson('/api/flows/overview?hours=6')->assertOk()->json('apps');
        $m365 = collect($apps)->firstWhere('app', 'Microsoft 365');
        $this->assertNotNull($m365);
        $this->assertSame(1_250_000, (int) $m365['bytes'], 'rollup 1M + raw 250k current hour');
    }

    public function test_values_autocompletes_apps_and_devices(): void
    {
        $fw = Device::factory()->create(['site_id' => Site::factory()->create()->id, 'name' => 'Edge-FW']);
        $this->seedFlows($fw);
        $user = User::factory()->create();

        $apps = $this->actingAs($user)->getJson('/api/flows/values?field=app')->assertOk()->json('values');
        $this->assertContains('Microsoft 365', $apps);

        $devs = $this->actingAs($user)->getJson('/api/flows/values?field=device&term=Edge')->assertOk()->json('values');
        $this->assertContains('Edge-FW', $devs);
    }

    public function test_resolve_returns_a_names_map(): void
    {
        $res = $this->actingAs(User::factory()->create())->getJson('/api/flows/resolve?ips=10.0.0.1,not-an-ip')->assertOk()->json();
        $this->assertArrayHasKey('10.0.0.1', $res['names']); // private IP → null PTR, but key present
        $this->assertArrayNotHasKey('not-an-ip', $res['names']);
    }

    public function test_kql_search_filters_rows(): void
    {
        $device = Device::factory()->create(['site_id' => Site::factory()->create()->id]);
        $this->seedFlows($device);

        $res = $this->actingAs(User::factory()->create())
            ->getJson('/api/flows/search?q='.urlencode('where App == "Microsoft 365" and Bytes > 500K'));

        $res->assertOk();
        $res->assertJsonPath('mode', 'rows');
        $this->assertSame(1, $res->json('count'), 'only the 1.0M M365 flow passes Bytes > 500K');
    }

    public function test_kql_summarize_returns_an_aggregate(): void
    {
        $device = Device::factory()->create(['site_id' => Site::factory()->create()->id]);
        $this->seedFlows($device);

        $res = $this->actingAs(User::factory()->create())
            ->getJson('/api/flows/search?q='.urlencode('Flows | summarize sum(Bytes) by App | top 10'));

        $res->assertOk();
        $res->assertJsonPath('mode', 'summarize');
        $rows = collect($res->json('rows'))->keyBy('key');
        $this->assertSame(1_240_000, (int) $rows['Microsoft 365']['value']);
    }

    public function test_a_bad_query_returns_422_not_a_crash(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/flows/search?q='.urlencode('DROP TABLE flow_records'))
            ->assertStatus(422);
    }

    public function test_cidr_membership_matches_the_subnet(): void
    {
        $device = Device::factory()->create(['site_id' => Site::factory()->create()->id]);
        $this->seedFlows($device);

        $res = $this->actingAs(User::factory()->create())
            ->getJson('/api/flows/search?q='.urlencode('SrcIP in (cidr("10.86.10.0/24"))'));

        $res->assertOk();
        $this->assertSame(4, $res->json('count'), 'all four flows are in 10.86.10.0/24… wait, .55 and .15 too');
    }

    /**
     * The rollup job runs after an hour closes. Between the hour ending and the job
     * finishing, that hour was read by neither branch of the timeseries — not a
     * completed rollup, not the current partial hour — so its traffic disappeared from
     * the chart entirely. It recurred every hour and surfaced here as a flaky test.
     *
     * @dataProvider clockPositions
     */
    public function test_traffic_in_a_closed_hour_with_no_rollup_yet_is_still_charted(int $minutesPastTheHour): void
    {
        $this->travelTo(now()->startOfHour()->addMinutes($minutesPastTheHour));

        $d = Device::factory()->create(['site_id' => Site::factory()->create()->id]);
        FlowRecord::create([
            'device_id' => $d->id, 'src_ip' => '10.0.0.1', 'dst_ip' => '8.8.8.8',
            'protocol' => 'tcp', 'app' => 'Microsoft 365', 'app_category' => 'SaaS',
            'direction' => 'outbound', 'bytes' => 5000, 'packets' => 3,
            'recorded_at' => now()->subMinutes(10),
        ]);

        $res = $this->actingAs(User::factory()->create())
            ->getJson('/api/flows/timeseries?hours=6')->assertOk()->json();

        $m365 = collect($res['series'])->firstWhere('name', 'Microsoft 365');
        $this->assertNotNull($m365, "lost the flow with 'now' at :{$minutesPastTheHour}");
        $this->assertSame(5000, array_sum(array_map(fn ($p) => $p[1], $m365['data'])));
    }

    public static function clockPositions(): array
    {
        // :05 puts the flow in the previous hour (the case that broke); :30 keeps it in
        // the current one. Both must chart the same bytes.
        return ['five past' => [5], 'half past' => [30], 'on the hour' => [1]];
    }

    /**
     * Same gap as the timeseries, in the fleet overview: a closed hour whose rollup has
     * not been written yet was read by neither the rollup side nor the raw side, so its
     * traffic disappeared from the overview until the job caught up.
     *
     * @dataProvider clockPositions
     */
    public function test_overview_counts_a_closed_hour_that_has_no_rollup_yet(int $minutesPastTheHour): void
    {
        $this->travelTo(now()->startOfHour()->addMinutes($minutesPastTheHour));

        $d = Device::factory()->create(['site_id' => Site::factory()->create()->id]);
        FlowRecord::create([
            'device_id' => $d->id, 'src_ip' => '10.0.0.1', 'dst_ip' => '8.8.8.8',
            'protocol' => 'tcp', 'app' => 'Microsoft 365', 'app_category' => 'SaaS',
            'direction' => 'outbound', 'bytes' => 5000, 'packets' => 3,
            'recorded_at' => now()->subMinutes(10),
        ]);

        $res = $this->actingAs(User::factory()->create())
            ->getJson('/api/flows/overview?hours=6')->assertOk()->json();

        $this->assertSame(1, $res['summary']['flows'], "lost the flow with 'now' at :{$minutesPastTheHour}");
        $m365 = collect($res['apps'])->firstWhere('app', 'Microsoft 365');
        $this->assertSame(5000, (int) $m365['bytes']);
    }

    public function test_a_covered_hour_is_not_double_counted_against_raw(): void
    {
        // The boundary must not overlap: an hour with a rollup is read from the rollup
        // only, even though the raw rows are still sitting in the table.
        $this->travelTo(now()->startOfHour()->addMinutes(30));

        $d = Device::factory()->create(['site_id' => Site::factory()->create()->id]);
        $hour = now()->subHour()->startOfHour();

        FlowRecord::create([
            'device_id' => $d->id, 'src_ip' => '10.0.0.1', 'dst_ip' => '8.8.8.8',
            'protocol' => 'tcp', 'app' => 'Microsoft 365', 'app_category' => 'SaaS',
            'direction' => 'outbound', 'bytes' => 400_000, 'packets' => 10,
            'recorded_at' => $hour->copy()->addMinutes(20),
        ]);
        \App\Models\FlowRollup::create([
            'device_id' => $d->id, 'if_index' => 0, 'bucket' => 'hour',
            'bucket_start' => $hour, 'group_type' => 'app',
            'group_key' => 'Microsoft 365', 'sub_key' => '', 'app_category' => 'SaaS',
            'bytes' => 400_000, 'packets' => 10, 'flows' => 1,
        ]);

        $apps = $this->actingAs(User::factory()->create())
            ->getJson('/api/flows/overview?hours=6')->assertOk()->json('apps');
        $m365 = collect($apps)->firstWhere('app', 'Microsoft 365');

        $this->assertSame(400_000, (int) $m365['bytes'], 'counted once, from the rollup');
    }
}
