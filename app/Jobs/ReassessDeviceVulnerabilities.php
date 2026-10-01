<?php

namespace App\Jobs;

use App\Models\CveAffect;
use App\Models\Device;
use App\Services\Vuln\VulnerabilityScanner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Re-judge ONE device's CVE findings, because its firmware just changed.
 *
 * The catalog scan runs daily, which was fine while identity was written once and
 * never re-read. It is not fine now that firmware is re-read hourly: a firewall
 * upgraded at 09:00 kept showing findings against the version it had left behind
 * until the next nightly pass. AZR-FW01 sat on 48 open findings whose
 * detected_os_version was a release the box no longer ran, and an operator reading
 * that screen has no way to tell a stale verdict from a live one.
 *
 * The scanner already resolves findings whose range stops covering the firmware —
 * it simply had not been asked. A version change is exactly the moment to ask, and
 * DeviceHardwareChange is already recording that moment.
 *
 * Queued rather than inline: the poller's job is to read the box, and a CVE
 * correlation against the whole catalog has no business inside an SNMP sweep.
 */
class ReassessDeviceVulnerabilities implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public int $deviceId) {}

    /**
     * Collapse duplicates: several fields can change on one poll, and a device only
     * needs re-assessing once for the version it now has.
     */
    public function uniqueId(): string
    {
        return (string) $this->deviceId;
    }

    public function handle(VulnerabilityScanner $scanner): void
    {
        $device = Device::find($this->deviceId);

        if (! $device) {
            return;
        }

        $affects = CveAffect::where('vendor', $device->vendor)->get();

        $result = $scanner->assess($device, $affects);

        Log::info('Device vulnerabilities re-assessed after a firmware change', [
            'device_id' => $device->id,
            'name' => $device->name,
            'os_version' => $device->os_version,
            'opened' => $result['opened'],
            'resolved' => $result['resolved'],
        ]);
    }
}
