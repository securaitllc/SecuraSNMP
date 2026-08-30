<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceAlarm;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DashboardInsightsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush(); // the endpoint caches 60s
    }

    public function test_insights_report_24h_raised_and_a_positive_mttr(): void
    {
        $site = Site::factory()->create();
        $device = Device::factory()->create(['site_id' => $site->id]);
        // Raised 2h ago, cleared 90 min later — inside the 24h window.
        DeviceAlarm::create([
            'device_id' => $device->id, 'alarm_id' => 'ec:1:test', 'severity' => 'critical',
            'description' => 'x', 'first_seen_at' => now()->subHours(2), 'cleared_at' => now()->subMinutes(30),
        ]);

        $res = $this->actingAs(User::factory()->create())->getJson('/api/dashboard/insights');

        $res->assertOk();
        $this->assertGreaterThanOrEqual(1, $res->json('raised_24h'), 'a past alarm must bucket into the 24h trend (Carbon-3 signed-diff guard)');
        // 2h open then cleared 30m ago = ~90 min — must be POSITIVE, not the -Xm the signed diff produced.
        $this->assertGreaterThan(0, $res->json('mttr_minutes'));
        $this->assertSame($site->id, $res->json('top_offenders.0.site_id'));
    }
}
