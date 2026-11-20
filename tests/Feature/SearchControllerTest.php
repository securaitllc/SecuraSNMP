<?php

namespace Tests\Feature;

use App\Models\Circuit;
use App\Models\Device;
use App\Models\DeviceAlarm;
use App\Models\DeviceHardwareChange;
use App\Models\DeviceMember;
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
    /** A serial off a shipping label finds the device and names its site. */
    public function test_current_serial_finds_the_device_and_its_site(): void
    {
        $site = Site::factory()->create(['name' => '#133 Charleston SC']);
        Device::factory()->create(['site_id' => $site->id, 'name' => 'SC133-ECB01', 'serial_number' => '001BBC3EB508']);

        $hits = $this->actingAs(User::factory()->create())->getJson('/api/search?q=001bbc3eb508')->assertOk()->json();

        $this->assertCount(1, $hits);
        $this->assertSame('SC133-ECB01', $hits[0]['label']);
        $this->assertStringContainsString('#133 Charleston SC', $hits[0]['sub']);
        $this->assertStringContainsString('serial 001BBC3EB508', $hits[0]['sub']);
    }

    /** The serial of a replaced unit resolves from the hardware-change trail, once. */
    public function test_old_serial_from_the_hardware_change_trail_finds_the_site(): void
    {
        $site = Site::factory()->create(['name' => '#133 Charleston SC']);
        $device = Device::factory()->create(['site_id' => $site->id, 'name' => 'SC133-ECB01', 'serial_number' => 'NEWEST', 'previous_serial_number' => 'MIDDLE', 'hardware_changed_at' => '2026-11-01 10:00:00']);
        DeviceHardwareChange::create(['device_id' => $device->id, 'field' => 'serial_number', 'old_value' => '001BBC369EA8', 'new_value' => 'MIDDLE', 'detected_at' => '2026-10-09 15:59:32']);
        DeviceHardwareChange::create(['device_id' => $device->id, 'field' => 'serial_number', 'old_value' => 'MIDDLE', 'new_value' => 'NEWEST', 'detected_at' => '2026-11-01 10:00:00']);

        $hits = $this->actingAs(User::factory()->create())->getJson('/api/search?q=001BBC369EA8')->assertOk()->json();

        $this->assertCount(1, $hits);
        $this->assertSame('device', $hits[0]['type']);
        $this->assertSame($device->id, $hits[0]['id']);
        $this->assertStringContainsString('#133 Charleston SC', $hits[0]['sub']);
        $this->assertStringContainsString('was serial 001BBC369EA8', $hits[0]['sub']);
        $this->assertStringContainsString('replaced 2026-10-09 by MIDDLE', $hits[0]['sub']);

        // previous_serial_number is matched on the device row itself, not duplicated from the trail.
        $hits = $this->actingAs(User::factory()->create())->getJson('/api/search?q=MIDDLE')->assertOk()->json();
        $this->assertCount(1, $hits);
        $this->assertStringContainsString('was serial MIDDLE · replaced 2026-11-01 by NEWEST', $hits[0]['sub']);
    }

    /** A Virtual Chassis member serial lands on the stack it belongs to. */
    public function test_virtual_chassis_member_serial_finds_the_stack(): void
    {
        $site = Site::factory()->create(['name' => 'Orlando']);
        $device = Device::factory()->create(['site_id' => $site->id, 'name' => 'ORL-SW1', 'serial_number' => 'MASTER1']);
        DeviceMember::create(['device_id' => $device->id, 'member_id' => 1, 'serial_number' => 'MEMBER2']);

        $hits = $this->actingAs(User::factory()->create())->getJson('/api/search?q=MEMBER2')->assertOk()->json();

        $this->assertCount(1, $hits);
        $this->assertSame('ORL-SW1', $hits[0]['label']);
        $this->assertStringContainsString('member serial MEMBER2', $hits[0]['sub']);
    }
}
