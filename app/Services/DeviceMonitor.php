<?php

namespace App\Services;

use App\Models\Device;
use App\Models\DeviceAlarm;
use App\Models\DeviceMetricHistory;
use Illuminate\Support\Facades\Log;
use Throwable;

class DeviceMonitor
{
    /** Stable alarm id for the "device is unreachable" (ICMP down) condition. */
    private const ALARM_UNREACHABLE = 'device-unreachable';

    /**
     * @param callable(string): ?float $pinger Returns the ICMP round-trip time in
     *        milliseconds, or null if the device did not respond (timeout).
     * @param  (callable(list<string>): array<string, ?float>)|null  $batchPinger
     *        Pings MANY device IPs concurrently → ip => rtt|null. When provided, a
     *        full fleet sweep is measured in parallel (seconds) instead of one at a
     *        time (minutes), so device-down detection isn't gated on a slow sweep.
     */
    public function __construct(private $pinger, private $batchPinger = null)
    {
    }

    /**
     * @param  (callable(): void)|null  $onProgress  Called after each device so a
     *         long fleet sweep emits a liveness heartbeat and is not mistaken for a
     *         hung loop (see RunsPollLoop::beat).
     */
    public function checkAll(?callable $onProgress = null): void
    {
        $devices = Device::where('status', 'active')->get();

        // Measure the whole fleet's reachability in ONE parallel batch.
        $measured = [];
        if ($this->batchPinger) {
            $ips = $devices->pluck('ip_address')->filter()->unique()->values()->all();
            if ($ips) {
                $measured = ($this->batchPinger)($ips);
            }
        }

        $devices->each(function (Device $device) use ($measured, $onProgress) {
            try {
                $responseMs = array_key_exists($device->ip_address, $measured)
                    ? $measured[$device->ip_address]
                    : ($this->pinger)($device->ip_address);
                $this->checkWith($device, $responseMs);
            } catch (Throwable $e) {
                Log::error("Device monitor failed for device {$device->id}: {$e->getMessage()}");
            }

            if ($onProgress) {
                $onProgress();
            }
        });
    }

    public function check(Device $device): void
    {
        $this->checkWith($device, ($this->pinger)($device->ip_address));
    }

    /** Record one measured reachability result and drive the down/up alarm. */
    private function checkWith(Device $device, ?float $responseMs): void
    {
        DeviceMetricHistory::create([
            'device_id' => $device->id,
            'recorded_at' => now(),
            'response_time_ms' => $responseMs,
        ]);

        $this->reconcileReachability($device, $responseMs);
    }

    /**
     * Raise a CRITICAL alarm when a device stops answering ICMP, and clear it when
     * it answers again. A short debounce (consecutive missed polls) avoids
     * alarming on a single dropped ping; a device is only declared down once the
     * last N polls in a row all timed out.
     */
    private function reconcileReachability(Device $device, ?float $responseMs): void
    {
        // Responded now → reachable. Auto-clear any open unreachable alarm.
        if ($responseMs !== null) {
            $this->clearUnreachable($device);

            return;
        }

        $threshold = max(1, (int) config('monitoring.down_threshold', 3));

        // Down only once the most recent `threshold` polls in a row all timed out.
        $recent = DeviceMetricHistory::where('device_id', $device->id)
            ->latest('recorded_at')
            ->take($threshold)
            ->pluck('response_time_ms');

        if ($recent->count() < $threshold || $recent->contains(fn ($v) => $v !== null)) {
            return;   // not yet enough consecutive failures
        }

        $this->openUnreachable($device, $threshold);
    }

    private function openUnreachable(Device $device, int $threshold): void
    {
        $alarm = DeviceAlarm::firstOrNew([
            'device_id' => $device->id,
            'alarm_id' => self::ALARM_UNREACHABLE,
        ]);

        $description = "Device is DOWN — unreachable via ICMP for {$threshold} consecutive polls.";

        if (! $alarm->exists) {
            // First time down.
            $alarm->fill([
                'severity' => 'critical',
                'description' => $description,
                'first_seen_at' => now(),
                'active_on_device' => true,
            ])->save();

            return;
        }

        if ($alarm->cleared_at !== null && ! $alarm->active_on_device) {
            // Recovered before, now down again — a flap. Reopen with a new ticket.
            $alarm->fill([
                'ticket_number' => DeviceAlarm::generateTicketNumber(),
                'severity' => 'critical',
                'description' => $description,
                'first_seen_at' => now(),
                'cleared_at' => null,
                'cleared_by' => null,
                'clear_note' => null,
                'cleared_manually' => false,
                'active_on_device' => true,
            ])->save();

            return;
        }

        // Otherwise: still open (leave it), or manually cleared while still down —
        // respect the NOC's clear and do not resurrect it. Just keep the
        // device-state flag raised so a later recovery→down cycle reads as a flap.
        if (! $alarm->active_on_device) {
            $alarm->update(['active_on_device' => true]);
        }
    }

    private function clearUnreachable(Device $device): void
    {
        // Auto-clear the open alarm (fires the resolved notification via the
        // observer), then lower the device-state flag on any already-cleared row.
        DeviceAlarm::where('device_id', $device->id)
            ->where('alarm_id', self::ALARM_UNREACHABLE)
            ->whereNull('cleared_at')
            ->get()
            ->each(fn (DeviceAlarm $alarm) => $alarm->update([
                'cleared_at' => now(),
                'cleared_manually' => false,
                'active_on_device' => false,
            ]));

        DeviceAlarm::where('device_id', $device->id)
            ->where('alarm_id', self::ALARM_UNREACHABLE)
            ->whereNotNull('cleared_at')
            ->where('active_on_device', true)
            ->update(['active_on_device' => false]);
    }
}
