<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\MaintenanceWindow;
use App\Models\Site;
use App\Services\AnomalyDetector;
use App\Support\Maintenance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A memo must not outlive the thing it remembers.
 *
 * Every poller is ONE artisan process started by the entrypoint and expected to run
 * for weeks. Anything a service caches "for the life of the process" is therefore
 * cached until somebody restarts the container. AnomalyDetector did exactly that with
 * its maintenance windows and its device-to-site map — the comment said "resolved
 * once per sweep", but the detector is constructed once outside pollForever() and
 * nothing ever reset it. A window opened after boot was invisible; one that closed
 * went on suppressing findings forever; a device added later resolved to no site.
 *
 * These tests drive the detector the way the poller does — one instance, many
 * sweeps — and pin that a change made between sweeps is seen.
 */
class MaintenanceStalenessTest extends TestCase
{
    use RefreshDatabase;

    /** The detector's window check, which the sweep calls per entity. */
    private function detectorSeesMaintenance(AnomalyDetector $detector, ?int $deviceId, ?int $siteId = null): bool
    {
        $check = new \ReflectionMethod($detector, 'underMaintenance');
        $check->setAccessible(true);

        return $check->invoke($detector, $deviceId, $siteId);
    }

    public function test_a_window_opened_between_sweeps_is_seen(): void
    {
        $site = Site::factory()->create();
        $device = Device::factory()->create(['site_id' => $site->id]);

        // One detector, reused — exactly how anomaly:monitor holds it.
        $detector = new AnomalyDetector;

        $this->assertFalse($this->detectorSeesMaintenance($detector, $device->id), 'first sweep: nothing planned');

        MaintenanceWindow::create([
            'name' => 'Opened while the poller was running', 'device_id' => $device->id,
            'starts_at' => now()->subMinute(), 'ends_at' => now()->addDays(30),
        ]);
        $this->travel(31)->seconds();

        $this->assertTrue(
            $this->detectorSeesMaintenance($detector, $device->id),
            'the detector must not still be answering from a map it built at boot',
        );
    }

    public function test_a_window_closed_between_sweeps_stops_suppressing(): void
    {
        $site = Site::factory()->create();
        $device = Device::factory()->create(['site_id' => $site->id]);
        $window = MaintenanceWindow::create([
            'name' => 'Short', 'device_id' => $device->id,
            'starts_at' => now()->subMinute(), 'ends_at' => now()->addMinutes(5),
        ]);

        $detector = new AnomalyDetector;
        $this->assertTrue($this->detectorSeesMaintenance($detector, $device->id));

        $window->update(['ends_at' => now()->subSecond()]);
        $this->travel(31)->seconds();

        $this->assertFalse(
            $this->detectorSeesMaintenance($detector, $device->id),
            'a finished window must stop hiding real findings',
        );
    }

    public function test_a_device_added_after_boot_is_covered_by_its_site_window(): void
    {
        // The device-to-site map was built on first use and never rebuilt, so a device
        // onboarded afterwards had no site and a site-wide window skipped it.
        $site = Site::factory()->create();
        $early = Device::factory()->create(['site_id' => $site->id]);
        MaintenanceWindow::create([
            'name' => 'Site cutover', 'site_id' => $site->id,
            'starts_at' => now()->subMinute(), 'ends_at' => now()->addDays(2),
        ]);

        $detector = new AnomalyDetector;
        $this->assertTrue($this->detectorSeesMaintenance($detector, $early->id));

        $late = Device::factory()->create(['site_id' => $site->id]);
        $this->travel(31)->seconds();

        $this->assertTrue(
            $this->detectorSeesMaintenance($detector, $late->id),
            'a device racked after the poller started is still at the site being worked on',
        );
    }

    public function test_a_circuit_at_a_site_under_maintenance_is_covered(): void
    {
        // The circuit path has a site and no device at all.
        $site = Site::factory()->create();
        MaintenanceWindow::create([
            'name' => 'Site cutover', 'site_id' => $site->id,
            'starts_at' => now()->subMinute(), 'ends_at' => now()->addDays(2),
        ]);

        $this->assertTrue(Maintenance::coversSite($site->id));
        $this->assertFalse(Maintenance::coversSite(Site::factory()->create()->id));
    }

    public function test_a_device_window_does_not_put_the_whole_site_under_maintenance(): void
    {
        $site = Site::factory()->create();
        $device = Device::factory()->create(['site_id' => $site->id]);
        MaintenanceWindow::create([
            'name' => 'One switch', 'device_id' => $device->id,
            'starts_at' => now()->subMinute(), 'ends_at' => now()->addDays(2),
        ]);

        $this->assertTrue(Maintenance::covers($device->id));
        $this->assertFalse(
            Maintenance::coversSite($site->id),
            'replacing one switch says nothing about the rest of the building',
        );
    }

    public function test_a_fleet_wide_window_covers_every_site(): void
    {
        $site = Site::factory()->create();
        MaintenanceWindow::create([
            'name' => 'Fleet upgrade',
            'starts_at' => now()->subMinute(), 'ends_at' => now()->addHours(4),
        ]);

        $this->assertTrue(Maintenance::coversSite($site->id));
    }

    public function test_the_memo_is_reused_inside_one_request(): void
    {
        // The other half of the contract: a dashboard build walks thousands of rows
        // and must not re-query per row. Nothing may change under it mid-request.
        $site = Site::factory()->create();
        $device = Device::factory()->create(['site_id' => $site->id]);

        $this->assertFalse(Maintenance::covers($device->id));

        MaintenanceWindow::create([
            'name' => 'Opened a moment ago', 'device_id' => $device->id,
            'starts_at' => now()->subMinute(), 'ends_at' => now()->addDays(30),
        ]);

        $this->assertFalse(Maintenance::covers($device->id), 'still inside the memo window');
        Maintenance::flush();
        $this->assertTrue(Maintenance::covers($device->id), 'and flush() is the escape hatch');
    }
}
