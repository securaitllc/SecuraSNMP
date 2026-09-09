<?php

namespace App\Services;

use App\Models\Device;
use App\Models\DeviceMetricHistory;

/**
 * Reads sysName — the hostname a device answers to — and records it beside the
 * name we call it by.
 *
 * This exists because hardware gets replaced. During an SD-WAN migration a new
 * EdgeConnect comes up at the old appliance's IP under a new hostname, and nothing
 * in Nodus notices: `devices.name` was typed once at import and never re-read, so
 * the record keeps describing the box that left the rack. The same happens on a
 * switch swap, or when someone simply renames a device on the console.
 *
 * It deliberately does NOT write `name`. That field resolves LLDP neighbours into
 * topology links and labels every alarm — a poller silently renaming 300 devices
 * would redraw the map with no trail. The observation is stored; adopting it is an
 * operator action (DeviceHostnameController), which can be taken for the whole
 * fleet at once but is taken knowingly.
 *
 * Its own cadence, separate from identity enrichment: a hostname can change on
 * hardware whose model and serial are long since known, so it must not sit behind
 * the "identity is complete" gate that stops those walks for good.
 */
class HostnamePoller
{
    private const OID_SYS_NAME = '.1.3.6.1.2.1.1.5';

    /** A hostname is cheap to read but not urgent — re-read a few times a day. */
    private const REFRESH_HOURS = 6;

    /** @param callable(Device, string): string $walker Returns raw snmpwalk stdout for an OID. */
    public function __construct(private $walker) {}

    public function poll(Device $device): void
    {
        if ($device->hostname_checked_at && $device->hostname_checked_at->gt(now()->subHours(self::REFRESH_HOURS))) {
            return;
        }

        // Don't spend a blocking walk on a device that isn't answering ICMP — it
        // would just time out. Same guard the identity poller uses.
        $lastPing = DeviceMetricHistory::where('device_id', $device->id)
            ->latest('recorded_at')
            ->value('response_time_ms');
        if ($lastPing === null) {
            return;
        }

        $hostname = $this->parse(($this->walker)($device, self::OID_SYS_NAME));

        // An empty answer means "no answer", not "no hostname" — this gear drops SNMP
        // responses under memory pressure. Leave the last known value and the stale
        // timestamp alone so the next cycle tries again rather than recording a blank.
        if ($hostname === null) {
            return;
        }

        $device->forceFill([
            'snmp_hostname' => $hostname,
            'hostname_checked_at' => now(),
        ])->save();
    }

    /**
     * First non-empty value from a sysName walk.
     *
     * Some agents return an FQDN (sw01.massey.local) where the device is recorded by
     * its short name; the host label is what an engineer types, so that is what is
     * compared and adopted.
     */
    private function parse(string $raw): ?string
    {
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            if (! str_contains($line, '=')) {
                continue;
            }
            $value = trim(substr($line, strpos($line, '=') + 1));
            $value = trim(preg_replace('/^(STRING|Hex-STRING|OID):\s*/i', '', $value), " \t\"");

            if ($value !== '' && ! str_contains(strtolower($value), 'no such')) {
                return $value;
            }
        }

        return null;
    }

    /** The host label alone, so an FQDN and its short form are not read as a rename. */
    public static function shortName(?string $name): string
    {
        return strtolower(trim(explode('.', trim((string) $name))[0]));
    }

    /** True when the device answers to a name that isn't the one on the record. */
    public static function drifted(Device $device): bool
    {
        $observed = trim((string) $device->snmp_hostname);

        return $observed !== '' && self::shortName($observed) !== self::shortName($device->name);
    }
}
