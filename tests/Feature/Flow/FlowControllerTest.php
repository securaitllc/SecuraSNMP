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
}
