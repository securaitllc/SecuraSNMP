<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\LldpNeighbor;
use App\Models\MacAddress;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditAndSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_state_changing_request_is_audited(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->postJson('/api/maintenance-windows', [
            'name' => 'Audit test',
            'starts_at' => now()->toISOString(),
            'ends_at' => now()->addHour()->toISOString(),
        ])->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'method' => 'POST',
            'path' => 'api/maintenance-windows',
            'status' => 201,
        ]);
    }

    public function test_get_requests_are_not_audited(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->getJson('/api/maintenance-windows')->assertOk();

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_search_finds_devices_by_name(): void
    {
        $site = Site::factory()->create();
        Device::factory()->create(['site_id' => $site->id, 'name' => 'core-sw-01']);
        $viewer = User::factory()->create();

        $response = $this->actingAs($viewer)->getJson('/api/search?q=core-sw');

        $response->assertOk();
        $response->assertJsonPath('0.type', 'device');
        $response->assertJsonPath('0.label', 'core-sw-01');
    }

    public function test_search_ignores_short_queries(): void
    {
        $viewer = User::factory()->create();
        $this->actingAs($viewer)->getJson('/api/search?q=c')->assertOk()->assertJsonCount(0);
    }

    public function test_search_finds_isp_ticket_and_alarm_event_id(): void
    {
        $site = Site::factory()->create();
        $circuit = \App\Models\Circuit::factory()->create(['site_id' => $site->id]);
        \App\Models\CircuitAlert::create(['circuit_id' => $circuit->id, 'started_at' => now(), 'ticket_number' => 'ISP-778899']);

        $device = Device::factory()->create(['site_id' => $site->id]);
        \App\Models\DeviceAlarm::create(['device_id' => $device->id, 'alarm_id' => 'ALM-4242', 'description' => 'tunnel down', 'first_seen_at' => now()]);

        $viewer = User::factory()->create();

        $ticket = $this->actingAs($viewer)->getJson('/api/search?q=ISP-7788')->json();
        $this->assertContains('ticket', array_column($ticket, 'type'));

        $alarm = $this->actingAs($viewer)->getJson('/api/search?q=ALM-4242')->json();
        $this->assertSame('alarm', $alarm[0]['type']);
        $this->assertSame("/devices/{$device->id}", $alarm[0]['route']);
    }

    public function test_an_endpoint_is_found_by_its_mac(): void
    {
        // A MAC-learning log names a MAC and nothing else — that is the string an
        // operator arrives with, and it could not be searched at all.
        $switch = Device::factory()->create(['name' => 'SC095-SWA001']);
        LldpNeighbor::create([
            'device_id' => $switch->id,
            'local_port' => 'ge-0/0/30',
            'remote_sysname' => 'regDN 500206,MINET_6920',
            'endpoint_model' => 'Mitel 6920',
            'extension' => '500206',
            'remote_mac' => '02:00:5E:05:15:32',
            'last_seen_at' => now(),
        ]);

        $results = collect($this->actingAs(User::factory()->create())
            ->getJson('/api/search?q='.urlencode('02:00:5E:05:15:32'))->json());

        $hit = $results->firstWhere('type', 'endpoint');
        $this->assertNotNull($hit);
        $this->assertSame("/devices/{$switch->id}", $hit['route']);
        $this->assertStringContainsString('ge-0/0/30', $hit['sub']);
    }

    public function test_an_fdb_learned_mac_is_found_by_mac_or_vendor(): void
    {
        // The SNMP FDB table — where a Verkada camera or a workstation is actually
        // learned — must be searchable, not only the handful of LLDP neighbours. The
        // dashboard search claimed to cover MACs but never queried this table.
        $switch = Device::factory()->create(['name' => 'TN0002-SC185SWA001']);
        $iface = DeviceInterface::factory()->create(['device_id' => $switch->id, 'if_name' => 'ge-0/0/45']);
        MacAddress::create([
            'device_id' => $switch->id, 'device_interface_id' => $iface->id,
            'mac' => 'E0:A7:00:44:30:7B', 'vlan' => 'VERKADA_SECURITY',
            'oui_vendor' => 'Verkada Inc', 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
        $viewer = User::factory()->create();

        $byMac = collect($this->actingAs($viewer)->getJson('/api/search?q=E0A70044')->json());
        $hit = $byMac->firstWhere('type', 'endpoint');
        $this->assertNotNull($hit, 'FDB MAC must be findable by a punctuation-less fragment');
        $this->assertSame('E0:A7:00:44:30:7B', $hit['label']);
        $this->assertStringContainsString('/mac-search?q=', $hit['route']);

        // "verkada" is all hex letters (e,a,d,a) — it must still match the vendor, not be
        // misread as a MAC fragment and silently return nothing.
        $byVendor = collect($this->actingAs($viewer)->getJson('/api/search?q=verkada')->json());
        $this->assertNotNull($byVendor->firstWhere('type', 'endpoint'), 'FDB endpoints must be findable by OUI vendor');
    }

    public function test_a_mac_matches_however_it_is_punctuated(): void
    {
        $switch = Device::factory()->create();
        LldpNeighbor::create([
            'device_id' => $switch->id, 'local_port' => 'ge-0/0/1',
            'remote_sysname' => 'phone', 'remote_mac' => '02:00:5E:05:15:32', 'last_seen_at' => now(),
        ]);
        $user = User::factory()->create();

        foreach (['02005e051532', '02-00-5e-05-15-32', '051532'] as $term) {
            $results = collect($this->actingAs($user)->getJson('/api/search?q='.urlencode($term))->json());

            $this->assertNotNull($results->firstWhere('type', 'endpoint'), "{$term} should match");
        }
    }

    public function test_a_phone_is_found_by_extension(): void
    {
        $switch = Device::factory()->create();
        LldpNeighbor::create([
            'device_id' => $switch->id, 'local_port' => 'ge-0/0/2', 'remote_sysname' => 'regDN 500206,MINET_6920',
            'extension' => '500206', 'last_seen_at' => now(),
        ]);

        $results = collect($this->actingAs(User::factory()->create())
            ->getJson('/api/search?q=500206')->json());

        $this->assertNotNull($results->firstWhere('type', 'endpoint'));
    }

    public function test_a_disconnected_endpoint_is_still_findable_and_says_so(): void
    {
        $switch = Device::factory()->create();
        LldpNeighbor::create([
            'device_id' => $switch->id, 'local_port' => 'ge-0/0/10', 'remote_sysname' => 'phone',
            'remote_mac' => '02:00:5E:AA:BB:CC', 'last_seen_at' => now()->subDay(),
            'absent_since' => now()->subDay(),
        ]);

        $results = collect($this->actingAs(User::factory()->create())
            ->getJson('/api/search?q='.urlencode('02:00:5E:AA:BB:CC'))->json());

        $hit = $results->firstWhere('type', 'endpoint');
        $this->assertNotNull($hit, 'Tracing a MAC that has gone quiet is the whole point.');
        $this->assertStringContainsString('disconnected', $hit['sub']);
    }
}
