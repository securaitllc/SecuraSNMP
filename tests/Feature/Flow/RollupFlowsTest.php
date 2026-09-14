<?php

namespace Tests\Feature\Flow;

use App\Models\Device;
use App\Models\FlowRecord;
use App\Models\FlowRollup;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RollupFlowsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rolls_raw_flows_into_hourly_talker_and_app_aggregates(): void
    {
        $device = Device::factory()->create(['site_id' => Site::factory()->create()->id]);
        // Two flows in the last completed hour, same talker + app → should aggregate.
        $when = now()->subHour()->startOfHour()->addMinutes(10);
        foreach ([1200, 800] as $b) {
            FlowRecord::create([
                'device_id' => $device->id, 'if_index' => 3,
                'src_ip' => '10.86.10.42', 'dst_ip' => '52.96.7.44', 'protocol' => 'tcp',
                'app' => 'Microsoft 365', 'app_category' => 'SaaS', 'direction' => 'outbound',
                'bytes' => $b, 'packets' => 5, 'recorded_at' => $when,
            ]);
        }

        $this->artisan('flows:rollup', ['--once' => true])->assertSuccessful();

        $talker = FlowRollup::where('group_type', 'talker')->first();
        $this->assertNotNull($talker);
        $this->assertSame(2000, $talker->bytes, 'talker bytes summed');
        $this->assertSame(2, $talker->flows);
        $this->assertSame('10.86.10.42', $talker->group_key);
        $this->assertSame('52.96.7.44', $talker->sub_key);

        $app = FlowRollup::where('group_type', 'app')->where('group_key', 'Microsoft 365')->first();
        $this->assertNotNull($app);
        $this->assertSame(2000, $app->bytes);
        $this->assertSame('SaaS', $app->app_category);
    }

    public function test_a_second_pass_is_idempotent(): void
    {
        $device = Device::factory()->create(['site_id' => Site::factory()->create()->id]);
        FlowRecord::create([
            'device_id' => $device->id, 'if_index' => 3, 'src_ip' => '10.0.0.1', 'dst_ip' => '8.8.8.8',
            'protocol' => 'udp', 'app' => 'DNS', 'app_category' => 'Infrastructure', 'direction' => 'outbound',
            'bytes' => 500, 'packets' => 2, 'recorded_at' => now()->subHour()->startOfHour()->addMinutes(5),
        ]);

        $this->artisan('flows:rollup', ['--once' => true])->assertSuccessful();
        $afterFirst = FlowRollup::count();
        $this->artisan('flows:rollup', ['--once' => true])->assertSuccessful();

        // A second pass must not create ANY new rows (idempotent upsert). Assert on the
        // total row count rather than a fixed number — near UTC midnight the flow's hour
        // legitimately also produces a DAY rollup, so the count isn't always 1.
        $this->assertSame($afterFirst, FlowRollup::count(), 'a second pass adds no rows');
        $hourly = FlowRollup::where('group_type', 'talker')->where('bucket', 'hour')->first();
        $this->assertSame(500, $hourly->bytes, 'the hourly talker rollup is stable across passes');
    }

    public function test_it_prunes_raw_flows_past_the_retention_window(): void
    {
        $device = Device::factory()->create(['site_id' => Site::factory()->create()->id]);
        // Default raw retention is 7 days: a 3-day-old flow survives, a 9-day-old is pruned.
        FlowRecord::create([
            'device_id' => $device->id, 'src_ip' => '10.0.0.1', 'dst_ip' => '8.8.8.8', 'protocol' => 'udp',
            'app' => 'DNS', 'direction' => 'outbound', 'bytes' => 10, 'packets' => 1,
            'recorded_at' => now()->subDays(3),
        ]);
        FlowRecord::create([
            'device_id' => $device->id, 'src_ip' => '10.0.0.2', 'dst_ip' => '8.8.4.4', 'protocol' => 'udp',
            'app' => 'DNS', 'direction' => 'outbound', 'bytes' => 10, 'packets' => 1,
            'recorded_at' => now()->subDays(9),
        ]);

        $this->artisan('flows:rollup', ['--once' => true])->assertSuccessful();

        $this->assertSame(1, FlowRecord::count(), 'only the flow past the 7-day window is pruned');
        $this->assertSame('10.0.0.1', FlowRecord::first()->src_ip, 'the 3-day-old flow is kept');
    }
}
