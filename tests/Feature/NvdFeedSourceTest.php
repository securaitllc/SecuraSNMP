<?php

namespace Tests\Feature;

use App\Models\CveAffect;
use App\Models\CveRecord;
use App\Models\Device;
use App\Models\DeviceVulnerability;
use App\Services\Vuln\CveCatalogUpdater;
use App\Services\Vuln\Feed\NvdFeedSource;
use App\Services\Vuln\VulnerabilityScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Parses fixture NVD 2.0 payloads (no network) and drives them through the catalog
 * updater + scanner, proving the two shapes NVD really uses for the fleet: FortiOS
 * clean ranges, and JunOS exact-release enumerations.
 */
class NvdFeedSourceTest extends TestCase
{
    use RefreshDatabase;

    /** A fetcher that returns one canned page then signals "no more". */
    private function fetcherReturning(array $vulnerabilities): callable
    {
        return function (string $url) use ($vulnerabilities) {
            // Only the first page (startIndex=0) has results.
            if (str_contains($url, 'startIndex=0')) {
                return ['vulnerabilities' => $vulnerabilities, 'totalResults' => count($vulnerabilities)];
            }

            return ['vulnerabilities' => [], 'totalResults' => count($vulnerabilities)];
        };
    }

    private function fortiRangeCve(): array
    {
        return ['cve' => [
            'id' => 'CVE-2024-21762', 'published' => '2024-02-09T00:00:00.000',
            'descriptions' => [['lang' => 'en', 'value' => 'FortiOS SSL VPN OOB write']],
            'metrics' => ['cvssMetricV31' => [['cvssData' => ['baseScore' => 9.8]]]],
            'configurations' => [['nodes' => [['cpeMatch' => [
                ['vulnerable' => true, 'criteria' => 'cpe:2.3:o:fortinet:fortios:*:*:*:*:*:*:*:*', 'versionStartIncluding' => '7.2.0', 'versionEndExcluding' => '7.2.7'],
            ]]]]],
        ]];
    }

    private function junosExactCve(): array
    {
        return ['cve' => [
            'id' => 'CVE-2024-21591', 'published' => '2024-01-12T00:00:00.000',
            'descriptions' => [['lang' => 'en', 'value' => 'Junos J-Web OOB write']],
            'metrics' => ['cvssMetricV31' => [['cvssData' => ['baseScore' => 9.8]]]],
            'configurations' => [['nodes' => [['cpeMatch' => [
                ['vulnerable' => true, 'criteria' => 'cpe:2.3:o:juniper:junos:20.4:r3-s2:*:*:*:*:*:*'],
                ['vulnerable' => true, 'criteria' => 'cpe:2.3:o:juniper:junos:20.4:r3:*:*:*:*:*:*'],
                // repeated → must dedupe
                ['vulnerable' => true, 'criteria' => 'cpe:2.3:o:juniper:junos:20.4:r3-s2:*:*:*:*:*:*'],
            ]]]]],
        ]];
    }

    public function test_it_parses_a_fortios_range_and_matches_correctly(): void
    {
        $source = new NvdFeedSource($this->fetcherReturning([$this->fortiRangeCve()]), 0);
        (new CveCatalogUpdater)->apply($source->fetch());

        $this->assertDatabaseHas('cve_records', ['cve_id' => 'CVE-2024-21762', 'cvss_score' => 9.8, 'severity' => 'critical']);
        $affect = CveAffect::where('cve_id', 'CVE-2024-21762')->first();
        $this->assertSame('7.2.0', $affect->version_introduced);
        $this->assertSame('7.2.7', $affect->version_fixed);
        $this->assertFalse($affect->fixed_inclusive); // versionEndExcluding

        $affected = Device::factory()->create(['vendor' => 'fortigate', 'os_version' => 'v7.2.4,build1396 (GA)']);
        $patched = Device::factory()->create(['vendor' => 'fortigate', 'os_version' => 'v7.2.10,build1706 (GA.M)']);
        (new VulnerabilityScanner)->assessAll();

        $this->assertDatabaseHas('device_vulnerabilities', ['device_id' => $affected->id, 'cve_id' => 'CVE-2024-21762']);
        $this->assertDatabaseMissing('device_vulnerabilities', ['device_id' => $patched->id]);
    }

    public function test_it_parses_junos_exact_enumerations_deduped_and_precise(): void
    {
        $source = new NvdFeedSource($this->fetcherReturning([$this->junosExactCve()]), 0);
        (new CveCatalogUpdater)->apply($source->fetch());

        // Two distinct exact releases (the duplicate r3-s2 collapsed).
        $this->assertSame(2, CveAffect::where('cve_id', 'CVE-2024-21591')->count());
        $this->assertSame(2, CveAffect::where('exact_match', true)->count());

        $onS2 = Device::factory()->create(['vendor' => 'juniper', 'os_version' => '20.4R3-S2.6']); // enumerated
        $patched = Device::factory()->create(['vendor' => 'juniper', 'os_version' => '20.4R3-S9']); // the fix
        (new VulnerabilityScanner)->assessAll();

        $this->assertDatabaseHas('device_vulnerabilities', ['device_id' => $onS2->id, 'cve_id' => 'CVE-2024-21591']);
        $this->assertDatabaseMissing('device_vulnerabilities', ['device_id' => $patched->id]);
    }

    public function test_the_feed_supersedes_a_stale_range_for_the_same_cve(): void
    {
        // Pre-existing (starter) row with an old range.
        CveRecord::create(['cve_id' => 'CVE-2024-21762', 'cvss_score' => 9.8, 'severity' => 'critical', 'summary' => 'old', 'source' => 'manual']);
        CveAffect::create(['cve_id' => 'CVE-2024-21762', 'vendor' => 'fortigate', 'version_introduced' => '7.2.0', 'version_fixed' => '7.2.99']);

        $source = new NvdFeedSource($this->fetcherReturning([$this->fortiRangeCve()]), 0);
        (new CveCatalogUpdater)->apply($source->fetch());

        // The stale 7.2.99 range is gone; only NVD's 7.2.7 remains.
        $this->assertSame(1, CveAffect::where('cve_id', 'CVE-2024-21762')->count());
        $this->assertSame('7.2.7', CveAffect::where('cve_id', 'CVE-2024-21762')->value('version_fixed'));
    }
}
