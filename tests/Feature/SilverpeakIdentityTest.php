<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceMetricHistory;
use App\Services\SnmpIdentityPoller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * EdgeConnect firmware version must be captured even when the enterprise
 * spsSystemSWVersion scalar is dropped — 113 of 130 Massey appliances had a model
 * but no os_version, leaving them unassessable for vulnerabilities.
 */
class SilverpeakIdentityTest extends TestCase
{
    use RefreshDatabase;

    private const GROUP = <<<'TXT'
    iso.3.6.1.4.1.23867.3.1.1.1.1.0 = STRING: "9.3.8.1_96913"
    iso.3.6.1.4.1.23867.3.1.1.1.2.0 = STRING: "EC10104"
    iso.3.6.1.4.1.23867.3.1.1.1.3.0 = STRING: "Normal"
    iso.3.6.1.4.1.23867.3.1.1.1.6.0 = STRING: "00-1B-BC-36-9E-18"
    TXT;

    private const SYSDESCR = 'iso.3.6.1.2.1.1.1.0 = STRING: "Silver Peak Systems, Inc. EC10104 Linux FL0042-SC065 4.19.87-sps ECOS 9.3.8.1_96913 #1-dev x86_64"';

    private function poll(callable $walker): Device
    {
        $device = Device::factory()->create(['vendor' => 'silverpeak', 'model' => 'EC10104', 'os_version' => null]);
        DeviceMetricHistory::create(['device_id' => $device->id, 'recorded_at' => now(), 'response_time_ms' => 13.4]);
        (new SnmpIdentityPoller($walker))->poll($device->fresh());

        return $device->fresh();
    }

    public function test_version_comes_from_the_system_group_when_it_answers(): void
    {
        $device = $this->poll(fn (Device $d, string $oid) => str_starts_with($oid, '.1.3.6.1.4.1.23867.3.1.1.1') ? self::GROUP : '');

        $this->assertSame('9.3.8.1_96913', $device->os_version);
        $this->assertSame('001BBC369E18', $device->serial_number);
    }

    public function test_version_falls_back_to_sysdescr_ecos_when_the_scalar_is_dropped(): void
    {
        // Group walk drops (empty) — the version must still be recovered from sysDescr.
        $device = $this->poll(function (Device $d, string $oid) {
            if (str_starts_with($oid, '.1.3.6.1.2.1.1.1')) {
                return self::SYSDESCR;
            }

            return ''; // enterprise group dropped
        });

        $this->assertSame('9.3.8.1_96913', $device->os_version);
    }

    public function test_nothing_is_invented_when_the_device_is_silent(): void
    {
        $device = $this->poll(fn (Device $d, string $oid) => '');

        $this->assertNull($device->os_version);
    }
}
