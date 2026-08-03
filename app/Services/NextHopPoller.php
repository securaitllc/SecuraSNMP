<?php

namespace App\Services;

use App\Models\Circuit;
use App\Models\Device;
use App\Models\DeviceNextHop;
use App\Models\NextHopAlert;
use App\Support\SshSession;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Collects a Silver Peak's WAN next-hops over SSH (`show system nexthops`) — one
 * per uplink, with the appliance's OWN reachability (the correct vantage point;
 * the collector can't reach an ISP-side gateway). Reconciles them into
 * device_next_hops and opens/closes a NextHopAlert per next-hop on transitions.
 */
class NextHopPoller
{
    private const COMMAND = 'show system nexthops';

    /**
     * @param  callable(Device, array): array<string, string>  $executor
     */
    public function __construct(private $executor)
    {
    }

    public static function forProduction(): self
    {
        return new self(fn (Device $device, array $commands): array => SshSession::run($device, $commands));
    }

    public function pollAll(): void
    {
        Device::where('role', 'edgeconnect')->get()->each(function (Device $device) {
            try {
                $this->poll($device);
            } catch (Throwable $e) {
                Log::warning("Next-hop poll failed for device {$device->id}: ".\App\Support\SshError::safe($e->getMessage()));
            }
        });
    }

    public function poll(Device $device): void
    {
        $output = ($this->executor)($device, [self::COMMAND]);
        $rows = self::parse($output[self::COMMAND] ?? '');

        // No parsable rows = unreachable or unexpected output; leave state as-is
        // rather than wiping every next-hop (and its alarms) on a transient.
        if ($rows === []) {
            return;
        }

        // WAN interfaces of paused circuits (monitoring_enabled = false) — their
        // next-hops are kept in inventory but must never alarm (a flapping LTE
        // backup that's been paused shouldn't raise/hold a next-hop-down alert).
        $mutedWans = $device->site_id === null ? [] : Circuit::where('site_id', $device->site_id)
            ->where('monitoring_enabled', false)
            ->whereNotNull('wan_interface')
            ->pluck('wan_interface')
            ->map(fn ($w) => strtolower(trim((string) $w)))
            ->filter()
            ->all();

        $now = now();
        $seen = [];
        foreach ($rows as $r) {
            $status = $r['reachability'] === 'reachable' ? 'up' : 'down';

            $nh = DeviceNextHop::updateOrCreate(
                ['device_id' => $device->id, 'ip_address' => $r['ip']],
                ['interface' => $r['interface'], 'reachability' => $r['reachability'], 'uptime' => $r['uptime'], 'status' => $status, 'last_checked_at' => $now],
            );
            $seen[] = $r['ip'];

            $open = NextHopAlert::where('device_next_hop_id', $nh->id)->whereNull('ended_at')->first();
            $muted = in_array(strtolower((string) $r['interface']), $mutedWans, true);
            if ($muted) {
                $open?->update(['ended_at' => $now]); // paused WAN: close, never open
            } elseif ($status === 'down' && ! $open) {
                NextHopAlert::create(['device_id' => $device->id, 'device_next_hop_id' => $nh->id, 'started_at' => $now]);
            } elseif ($status === 'up' && $open) {
                $open->update(['ended_at' => $now]); // single-model update → observer resolves it
            }
        }

        // Prune next-hops that vanished from the appliance; close their open alerts first.
        foreach (DeviceNextHop::where('device_id', $device->id)->whereNotIn('ip_address', $seen ?: ['__none__'])->get() as $stale) {
            $stale->alerts()->whereNull('ended_at')->get()->each(fn ($a) => $a->update(['ended_at' => $now]));
            $stale->delete();
        }
    }

    /**
     * Parse `show system nexthops` table output.
     *
     * @return list<array{ip: string, interface: string, reachability: string, uptime: string}>
     */
    public static function parse(string $output): array
    {
        $rows = [];
        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '-' || stripos($line, 'Next-hop') === 0) {
                continue;
            }
            $parts = preg_split('/\s+/', $line);
            if ($parts === false || count($parts) < 3 || ! filter_var($parts[0], FILTER_VALIDATE_IP)) {
                continue;
            }
            $rows[] = [
                'ip' => $parts[0],
                'interface' => $parts[1],
                'reachability' => strtolower($parts[2]),
                'uptime' => implode(' ', array_slice($parts, 3)),
            ];
        }

        return $rows;
    }
}
