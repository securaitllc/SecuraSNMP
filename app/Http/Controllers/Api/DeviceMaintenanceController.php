<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\MaintenanceWindow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Put a device under maintenance, and take it back out.
 *
 * Maintenance already existed as a scheduled window that suppressed notifications.
 * What it never did was change how the device READS: it still alarmed, still showed
 * down, and its planned downtime still counted against availability. This is the
 * one-click NOC action, and the state it sets is honoured by the reports.
 *
 * Maintenance is deliberately its own state, never a synonym for healthy. A device
 * being worked on is genuinely unreachable — reporting it "up" would be the same
 * false-healthy mistake that hides real outages.
 */
class DeviceMaintenanceController extends Controller
{
    /** Six months. Long enough for any real project, short enough to catch a typo. */
    private const MAX_HOURS = 24 * 180;


    public function store(Request $request, Device $device): JsonResponse
    {
        // Long jobs are real — a switch replacement or a site build runs for weeks —
        // so the ceiling is generous. It is not unlimited: an unbounded window would
        // mute a device forever, and a mistyped year is the easiest way to create one.
        $data = $request->validate([
            'hours' => ['nullable', 'numeric', 'min:0.25', 'max:'.self::MAX_HOURS],
            'ends_at' => ['nullable', 'date', 'after:now', 'before:'.now()->addHours(self::MAX_HOURS)->toDateTimeString()],
            'reason' => ['nullable', 'string', 'max:255'],
        ], [
            'ends_at.before' => 'Maintenance cannot run more than '.(self::MAX_HOURS / 24).' days out.',
            'hours.max' => 'Maintenance cannot run more than '.(self::MAX_HOURS / 24).' days.',
        ]);

        // Default to four hours: long enough for real work, short enough that a
        // forgotten window expires on its own rather than muting a device for weeks.
        $endsAt = isset($data['ends_at'])
            ? \Illuminate\Support\Carbon::parse($data['ends_at'])
            : now()->addMinutes((int) round(($data['hours'] ?? 4) * 60));

        $window = MaintenanceWindow::create([
            'name' => "Maintenance — {$device->name}",
            'device_id' => $device->id,
            'starts_at' => now(),
            'ends_at' => $endsAt,
            'reason' => $data['reason'] ?? null,
        ]);

        return response()->json([
            'in_maintenance' => true,
            'maintenance_until' => $window->ends_at,
            'window' => $window,
        ], 201);
    }

    /**
     * End maintenance now.
     *
     * The window is closed rather than deleted — it is the record of why the device
     * was quiet during that period, and availability reporting reads it afterwards.
     */
    public function destroy(Device $device): JsonResponse
    {
        $closed = MaintenanceWindow::active()
            ->where('device_id', $device->id)
            ->get()
            // A second in the past, so the window is unambiguously over: the active
            // scope matches ends_at >= now(), and closing at exactly now() would leave
            // the device still reading as under maintenance.
            ->each(fn (MaintenanceWindow $w) => $w->update(['ends_at' => now()->subSecond()]))
            ->count();

        return response()->json([
            'in_maintenance' => $device->fresh()->inMaintenance(),
            'closed' => $closed,
        ]);
    }
}
