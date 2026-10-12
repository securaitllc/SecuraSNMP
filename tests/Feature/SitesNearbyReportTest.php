<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "What else can I do the same day" — sites within a mileage band of one origin,
 * for planning an SD-WAN migration run.
 */
class SitesNearbyReportTest extends TestCase
{
    use RefreshDatabase;

    /** Orlando-ish origin, with neighbours at known distances. */
    private function fleet(): Site
    {
        $origin = Site::factory()->create(['name' => '#893 Origin', 'latitude' => 28.5383, 'longitude' => -81.3792]);

        // ~7 miles north — inside 0-10, outside 10-20.
        Site::factory()->create(['name' => '#101 Close', 'latitude' => 28.6400, 'longitude' => -81.3792]);
        // ~15 miles north — inside the 10-20 band.
        Site::factory()->create(['name' => '#102 Band', 'latitude' => 28.7550, 'longitude' => -81.3792]);
        // ~48 miles north — outside both.
        Site::factory()->create(['name' => '#103 Far', 'latitude' => 29.2350, 'longitude' => -81.3792]);

        return $origin;
    }

    private function report(array $query): array
    {
        return $this->actingAs(User::factory()->create())
            ->getJson('/api/reports/sites-nearby?'.http_build_query($query))
            ->assertOk()->json();
    }

    public function test_only_sites_inside_the_band_are_listed(): void
    {
        $origin = $this->fleet();

        $body = $this->report(['site_id' => $origin->id, 'min_miles' => 10, 'max_miles' => 20]);
        $names = collect($body['rows'])->pluck('site_name')->all();

        $this->assertSame(['#102 Band'], $names);
        $this->assertEqualsWithDelta(15.0, collect($body['rows'])->first()['miles'], 1.0);
        $this->assertSame('N', collect($body['rows'])->first()['bearing']);
    }

    public function test_the_band_is_configurable(): void
    {
        $origin = $this->fleet();

        $names = collect($this->report(['site_id' => $origin->id, 'min_miles' => 0, 'max_miles' => 10])['rows'])
            ->pluck('site_name')->all();
        $this->assertSame(['#101 Close'], $names);

        $names = collect($this->report(['site_id' => $origin->id, 'min_miles' => 0, 'max_miles' => 60])['rows'])
            ->pluck('site_name')->all();
        $this->assertSame(['#101 Close', '#102 Band', '#103 Far'], $names, 'nearest first');
    }

    public function test_a_site_without_coordinates_is_listed_and_flagged_not_dropped(): void
    {
        // Dropping it would make an UNMEASURED site read exactly like a site that is
        // not nearby — the absence-means-good trap. It must be visible and flagged.
        $origin = $this->fleet();
        Site::factory()->create(['name' => '#104 No Coords', 'latitude' => null, 'longitude' => null]);

        $body = $this->report(['site_id' => $origin->id, 'min_miles' => 10, 'max_miles' => 20]);
        $row = collect($body['rows'])->firstWhere('site_name', '#104 No Coords');

        $this->assertNotNull($row, 'an unmeasured site must still appear');
        $this->assertSame('—', $row['miles']);
        $this->assertStringContainsString('No coordinates', $row['status']);
        $this->assertSame('warning', $row['_tone']['status']);
        $this->assertSame('#104 No Coords', collect($body['rows'])->last()['site_name'], 'sorted last');
        $this->assertNotNull(collect($body['summary'])->firstWhere('label', 'Not measured'));
    }

    public function test_an_origin_without_coordinates_says_so_instead_of_returning_an_empty_list(): void
    {
        $origin = Site::factory()->create(['name' => '#900 Unplotted', 'latitude' => null, 'longitude' => null]);
        Site::factory()->create(['latitude' => 28.6, 'longitude' => -81.3]);

        $body = $this->report(['site_id' => $origin->id, 'min_miles' => 0, 'max_miles' => 20]);

        $this->assertSame([], $body['rows']);
        $this->assertStringContainsString('no coordinates', collect($body['summary'])->firstWhere('label', 'Problem')['value']);
    }

    public function test_the_summary_counts_the_work_in_the_band(): void
    {
        $origin = $this->fleet();
        $band = Site::where('name', '#102 Band')->first();
        Device::factory()->count(3)->create(['site_id' => $band->id]);

        $summary = collect($this->report(['site_id' => $origin->id, 'min_miles' => 10, 'max_miles' => 20])['summary']);

        $this->assertSame('1', $summary->firstWhere('label', 'Sites in band')['value']);
        $this->assertSame('3', $summary->firstWhere('label', 'Devices in band')['value']);
        $this->assertSame('#893 Origin', $summary->firstWhere('label', 'Origin')['value']);
    }

    public function test_the_report_appears_in_the_catalog_with_radius_support(): void
    {
        $body = $this->actingAs(User::factory()->create())->getJson('/api/reports/catalog')->assertOk()->json();
        $entry = collect($body['reports'])->firstWhere('type', 'sites-nearby');

        $this->assertNotNull($entry);
        $this->assertTrue($entry['supports_radius']);
        $this->assertFalse($entry['time_scoped'], 'a snapshot, not a window');
    }

    public function test_sites_are_grouped_into_drive_able_runs(): void
    {
        $origin = Site::factory()->create(['name' => '#893 Origin', 'latitude' => 28.5383, 'longitude' => -81.3792]);

        // Three sites in one pocket ~12 miles north, tight together.
        Site::factory()->create(['name' => '#201 A', 'latitude' => 28.7100, 'longitude' => -81.3792]);
        Site::factory()->create(['name' => '#202 B', 'latitude' => 28.7150, 'longitude' => -81.3800]);
        Site::factory()->create(['name' => '#203 C', 'latitude' => 28.7200, 'longitude' => -81.3810]);
        // One on its own, ~14 miles WEST — a long hop from that pocket.
        Site::factory()->create(['name' => '#204 D', 'latitude' => 28.5383, 'longitude' => -81.6100]);

        $rows = collect($this->report(['site_id' => $origin->id, 'min_miles' => 5, 'max_miles' => 25, 'stops_per_run' => 5])['rows']);

        $pocket = $rows->whereIn('site_name', ['#201 A', '#202 B', '#203 C'])->pluck('run')->unique();
        $this->assertCount(1, $pocket, 'the tight cluster is one run');
        $this->assertNotSame($pocket->first(), $rows->firstWhere('site_name', '#204 D')['run'],
            'a long hop starts a new run rather than dragging the trip across the metro');

        $first = $rows->firstWhere('run', $pocket->first());
        $this->assertSame(1, $first['stop'], 'stops are numbered in driving order');
    }

    public function test_stops_per_run_caps_a_trip(): void
    {
        $origin = Site::factory()->create(['latitude' => 28.5383, 'longitude' => -81.3792]);
        foreach (range(1, 4) as $i) {
            Site::factory()->create(['name' => "#30{$i}", 'latitude' => 28.7100 + ($i / 1000), 'longitude' => -81.3792]);
        }

        $rows = collect($this->report(['site_id' => $origin->id, 'min_miles' => 5, 'max_miles' => 25, 'stops_per_run' => 2])['rows']);

        $this->assertSame(2, $rows->groupBy('run')->count(), '4 sites at 2 stops each = 2 runs');
        $rows->groupBy('run')->each(fn ($r) => $this->assertLessThanOrEqual(2, $r->count()));
    }
}
