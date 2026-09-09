<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceMetricHistory;
use App\Models\Site;
use App\Models\User;
use App\Services\HostnamePoller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A device that has been swapped answers to a new hostname at the old address.
 *
 * Nodus reads that hostname but never adopts it on its own: `devices.name` is what
 * LldpCollector matches neighbours against and what every alarm is labelled with, so
 * a poller renaming 300 devices would redraw the topology with no trail. The fleet
 * is renamed in ONE operator action instead — which is the difference between a
 * migration you can do and one you type out 300 times.
 */
class DeviceHostnameTest extends TestCase
{
    use RefreshDatabase;

    /** A device that answers ICMP, so the poller will spend a walk on it. */
    private function reachable(array $attributes = []): Device
    {
        $device = Device::factory()->create($attributes + ['site_id' => Site::factory()->create()->id]);
        DeviceMetricHistory::create([
            'device_id' => $device->id, 'recorded_at' => now(), 'response_time_ms' => 4.2, 'status' => 'up',
        ]);

        return $device;
    }

    private function walker(string $sysName): callable
    {
        return fn (Device $d, string $oid) => $oid === '.1.3.6.1.2.1.1.5'
            ? "iso.3.6.1.2.1.1.5.0 = STRING: {$sysName}\n"
            : '';
    }

    public function test_the_poller_records_the_hostname_without_touching_the_name(): void
    {
        $device = $this->reachable(['name' => 'AL0001-SC208_SDW']);

        (new HostnamePoller($this->walker('EC-AL0001-NEW')))->poll($device);

        $device->refresh();
        $this->assertSame('EC-AL0001-NEW', $device->snmp_hostname);
        $this->assertSame('AL0001-SC208_SDW', $device->name, 'a poller must never rename the fleet on its own');
        $this->assertNotNull($device->hostname_checked_at);
    }

    public function test_an_empty_answer_never_erases_a_known_hostname(): void
    {
        $device = $this->reachable();
        (new HostnamePoller($this->walker('sw01')))->poll($device);
        $device->forceFill(['hostname_checked_at' => now()->subDay()])->save();

        // This gear drops SNMP responses under memory pressure; a blank is "no
        // answer", not "no hostname".
        (new HostnamePoller(fn () => ''))->poll($device);

        $this->assertSame('sw01', $device->refresh()->snmp_hostname);
        $this->assertTrue($device->hostname_checked_at->lt(now()->subHours(12)), 'a failed read must not count as a read');
    }

    public function test_an_unreachable_device_is_not_walked(): void
    {
        $device = Device::factory()->create(['site_id' => Site::factory()->create()->id]);

        (new HostnamePoller(fn () => "iso.3.6.1.2.1.1.5.0 = STRING: nope\n"))->poll($device);

        $this->assertNull($device->refresh()->snmp_hostname);
    }

    public function test_an_fqdn_is_not_mistaken_for_a_rename(): void
    {
        $device = $this->reachable(['name' => 'sw01']);
        (new HostnamePoller($this->walker('sw01.massey.local')))->poll($device);

        $this->assertFalse(HostnamePoller::drifted($device->refresh()), 'sw01 and sw01.massey.local are the same box');
    }

    public function test_the_drift_list_names_what_has_not_been_read(): void
    {
        $drifted = $this->reachable(['name' => 'OLD-EDGE']);
        (new HostnamePoller($this->walker('NEW-EDGE')))->poll($drifted);
        $this->reachable(['name' => 'never-polled']);

        $body = $this->actingAs(User::factory()->create())
            ->getJson('/api/devices/hostname-drift')->assertOk()->json();

        $this->assertCount(1, $body['data']);
        $this->assertSame('NEW-EDGE', $body['data'][0]['snmp_hostname']);
        // Coverage is the point: one mismatch out of one device READ is a different
        // fact from one out of two hundred, and an unread fleet must not look agreed.
        $this->assertSame(2, $body['coverage']['devices']);
        $this->assertSame(1, $body['coverage']['unchecked']);
    }

    public function test_adopting_renames_the_whole_fleet_in_one_call_and_keeps_the_trail(): void
    {
        $devices = collect(['A-OLD', 'B-OLD'])->map(function (string $name) {
            $d = $this->reachable(['name' => $name]);
            (new HostnamePoller($this->walker(str_replace('OLD', 'NEW', $name))))->poll($d);

            return $d;
        });

        $body = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson('/api/devices/adopt-hostnames')->assertOk()->json();

        $this->assertSame(2, $body['renamed']);
        $this->assertSame('A-NEW', $devices[0]->refresh()->name);
        $this->assertSame('A-OLD', $devices[0]->previous_name, 'a rename with no trail cannot be reasoned about later');
        $this->assertNotNull($devices[0]->renamed_at);
        $this->assertSame('B-NEW', $devices[1]->refresh()->name);
    }

    public function test_adopting_a_subset_leaves_the_exceptions_alone(): void
    {
        $keep = $this->reachable(['name' => 'KEEP-THIS']);
        (new HostnamePoller($this->walker('ugly-autogen-name')))->poll($keep);
        $take = $this->reachable(['name' => 'TAKE-OLD']);
        (new HostnamePoller($this->walker('TAKE-NEW')))->poll($take);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson('/api/devices/adopt-hostnames', ['device_ids' => [$take->id]])->assertOk();

        $this->assertSame('KEEP-THIS', $keep->refresh()->name);
        $this->assertSame('TAKE-NEW', $take->refresh()->name);
    }

    public function test_renaming_the_fleet_is_admin_only(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'analyst']))
            ->postJson('/api/devices/adopt-hostnames')->assertForbidden();
    }
}
