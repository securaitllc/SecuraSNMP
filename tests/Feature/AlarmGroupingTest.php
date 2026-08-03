<?php

namespace Tests\Feature;

use App\Models\Circuit;
use App\Models\CircuitAlert;
use App\Models\Device;
use App\Models\DeviceAlarm;
use App\Models\DeviceNextHop;
use App\Models\Site;
use App\Services\AlarmGroupingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reproduces the prod #024 Boca case: gateway pings up over the ISP L2VPN, so no
 * circuit outage — only tunnel-rollup + next-hop + IP-SLA device alarms. They must
 * group under the Lumen circuit (degraded) with the rollup in a site bucket.
 */
class AlarmGroupingTest extends TestCase
{
    use RefreshDatabase;

    private function seed024(): Site
    {
        $site = Site::factory()->create(['name' => '#024 Boca Commercial FL']);
        $dev = Device::factory()->create(['site_id' => $site->id, 'role' => 'edgeconnect', 'name' => 'FL0018-SC024_SDW']);

        Circuit::factory()->create(['site_id' => $site->id, 'isp_name' => 'Lumen', 'wan_interface' => 'wan1', 'gateway_ip' => '63.212.186.49', 'status' => 'up']);
        Circuit::factory()->create(['site_id' => $site->id, 'isp_name' => 'AT&T', 'wan_interface' => 'wan0', 'gateway_ip' => '23.127.131.134', 'status' => 'up']);
        DeviceNextHop::create(['device_id' => $dev->id, 'ip_address' => '63.212.186.49', 'interface' => 'wan1']);

        DeviceAlarm::factory()->create(['device_id' => $dev->id, 'alarm_id' => 'ec:65537:Tunnel', 'severity' => 'critical', 'description' => 'Many tunnels to remote sites are down']);
        DeviceAlarm::factory()->create(['device_id' => $dev->id, 'alarm_id' => 'ec:196625:gw:63.212.186.49', 'severity' => 'critical', 'description' => 'Next-hop unreachable — gw:63.212.186.49']);
        DeviceAlarm::factory()->create(['device_id' => $dev->id, 'alarm_id' => 'ec:262189:Ping on Port wan1 label DIA1', 'severity' => 'warning', 'description' => 'An IP SLA monitor is in the Down state — Ping on Port wan1 tunnel N/A label DIA1']);

        return $site;
    }

    public function test_it_groups_the_024_alarms_by_isp_circuit(): void
    {
        $site = $this->seed024();
        $out = (new AlarmGroupingService)->grouped($site->id);

        $this->assertCount(1, $out);
        $groups = $out[0]['groups'];

        // Lumen circuit group carries the next-hop + IP-SLA, and is DEGRADED (ping up).
        $lumen = collect($groups)->firstWhere('kind', 'circuit');
        $this->assertSame('Lumen', $lumen['circuit']['isp_name']);
        $this->assertSame('degraded', $lumen['state']);
        $this->assertCount(2, $lumen['alarms']);

        // The rollup has no ISP → site bucket. AT&T has no alarms → absent.
        $bucket = collect($groups)->firstWhere('kind', 'site');
        $this->assertCount(1, $bucket['alarms']);
        $this->assertSame('ec:65537:Tunnel', $bucket['alarms'][0]['alarm_id']);
        $this->assertCount(2, $groups); // Lumen + site bucket only
    }

    public function test_ticket_and_dispatch_from_the_open_circuit_alert_surface_on_the_group(): void
    {
        $site = $this->seed024();
        $lumen = Circuit::where('isp_name', 'Lumen')->first();
        CircuitAlert::factory()->create([
            'circuit_id' => $lumen->id, 'ended_at' => null,
            'ticket_number' => 'LUMEN-4471822', 'dispatch_at' => now(),
        ]);

        $group = collect((new AlarmGroupingService)->grouped($site->id)[0]['groups'])->firstWhere('kind', 'circuit');
        $this->assertSame('LUMEN-4471822', $group['ticket']['isp_ticket']);
        $this->assertNotNull($group['ticket']['dispatch_at']);
    }

    public function test_the_grouped_endpoint_requires_auth_and_returns_sites(): void
    {
        $site = $this->seed024();
        $user = \App\Models\User::factory()->create();

        $this->getJson('/api/alarms/grouped')->assertUnauthorized();
        $this->actingAs($user)->getJson("/api/alarms/grouped?site_id={$site->id}")
            ->assertOk()->assertJsonPath('sites.0.site_name', '#024 Boca Commercial FL');
    }
}
