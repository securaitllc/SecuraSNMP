<?php

namespace Tests\Feature;

use App\Models\Circuit;
use App\Models\Device;
use App\Models\DeviceAlarm;
use App\Models\DeviceHealth;
use App\Models\Site;
use App\Models\Tunnel;
use App\Models\User;
use App\Support\Measurement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The invariant behind every false-healthy bug this app has had.
 *
 * Absence of a signal is never a signal. A device that stops answering does not
 * become healthy and does not become broken — it becomes unknown, and everything
 * derived from it has to say so. These assertions are deliberately about the SHAPE
 * of the payload rather than about one screen, so the next view added cannot quietly
 * reintroduce the bug.
 */
class MeasurementProvenanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unmeasured_value_never_borrows_up_or_down(): void
    {
        $this->assertSame('unknown', Measurement::state('up', false));
        $this->assertSame('unknown', Measurement::state('down', false));
        $this->assertSame('up', Measurement::state('up', true));
    }

    public function test_a_stamp_carries_why_only_when_it_is_missing(): void
    {
        $measured = Measurement::stamp(['x' => 1], true, Measurement::SNMP, Carbon::now());
        $missing = Measurement::stamp(['x' => 1], false, Measurement::SSH, Carbon::now(), 'the appliance is not answering');

        $this->assertNull($measured['why'], 'a reading that happened needs no excuse');
        $this->assertSame('the appliance is not answering', $missing['why'], 'an operator asking why this is grey must not have to guess');
        $this->assertSame(Measurement::SSH, $missing['source']);
        $this->assertNotNull($missing['as_of'], 'how old the last reading is IS the useful part');
    }

    public function test_everything_derived_from_a_dark_appliance_declares_itself_unmeasured(): void
    {
        $site = Site::factory()->create(['site_number' => '045']);
        $edge = Device::factory()->create([
            'site_id' => $site->id, 'role' => 'edgeconnect',
            'name' => 'FL0027-SC045_SDW', 'ip_address' => '10.200.45.254',
        ]);
        Device::factory()->create(['site_id' => $site->id, 'role' => 'switch', 'ip_address' => '10.200.45.10']);
        Circuit::factory()->create(['site_id' => $site->id, 'wan_interface' => 'wan0', 'status' => 'down', 'monitoring_enabled' => true]);
        Tunnel::factory()->count(5)->create(['device_id' => $edge->id, 'hub' => 'AZURE', 'status' => 'up']);
        DeviceHealth::create(['device_id' => $edge->id, 'cpu_pct' => 9.25, 'mem_pct' => 93.68]);
        DeviceAlarm::factory()->create(['device_id' => $edge->id, 'alarm_id' => 'device-unreachable', 'cleared_at' => null]);

        $this->actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));
        $body = $this->getJson("/api/sites/{$site->id}/topology")->assertOk()->json();

        $node = collect($body['nodes'])->firstWhere('label', 'FL0027-SC045_SDW');

        // Health: polled over SNMP, frozen at its last values.
        $this->assertFalse($node['health']['measured']);
        $this->assertSame(Measurement::SNMP, $node['health']['source']);
        $this->assertNotNull($node['health']['why']);

        // Per-hub tunnels: polled over SSH, likewise frozen.
        foreach ($node['tunnel_hubs'] as $hub) {
            $this->assertFalse($hub['measured'], 'a frozen hub row must not read as tunnels currently up');
            $this->assertNotNull($hub['why']);
        }

        // Incident layers derived from those tables.
        foreach (['next_hop', 'tunnels'] as $layer) {
            $this->assertSame('unknown', $body['incident']['layers'][$layer]['state']);
        }

        // Links are inferred, never probed — one dark end means unknown.
        foreach ($body['edges'] as $edgeRow) {
            if (str_starts_with($edgeRow['from'], 'ec-') && str_starts_with($edgeRow['to'], 'sw-')) {
                $this->assertSame('unknown', $edgeRow['status'], 'nothing measures a cable');
            }
        }
    }

    public function test_no_layer_reports_unknown_while_also_claiming_traffic_flows(): void
    {
        // The specific contradiction that put "running on backup WAN" on a site
        // that was completely dark.
        $site = Site::factory()->create(['site_number' => '045']);
        $edge = Device::factory()->create(['site_id' => $site->id, 'role' => 'edgeconnect', 'ip_address' => '10.200.45.254']);
        Circuit::factory()->create(['site_id' => $site->id, 'wan_interface' => 'wan0', 'status' => 'down', 'monitoring_enabled' => true]);
        Circuit::factory()->create(['site_id' => $site->id, 'wan_interface' => 'wan1', 'status' => 'down', 'monitoring_enabled' => true]);
        Tunnel::factory()->count(3)->create(['device_id' => $edge->id, 'status' => 'up']);
        DeviceAlarm::factory()->create(['device_id' => $edge->id, 'alarm_id' => 'device-unreachable', 'cleared_at' => null]);

        $this->actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));
        $layers = $this->getJson("/api/sites/{$site->id}/topology")->json('incident.layers');

        $anyUnknown = collect(['circuit', 'next_hop', 'tunnels'])
            ->contains(fn ($k) => ($layers[$k]['state'] ?? null) === 'unknown');

        if ($anyUnknown) {
            $this->assertFalse($layers['passing_traffic'], 'traffic cannot be confirmed through a layer nothing measured');
        }
    }
}
