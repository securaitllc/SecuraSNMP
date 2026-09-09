<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Services\HostnamePoller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Reconciles the name we call a device by with the hostname it answers to.
 *
 * HostnamePoller records sysName; it never writes `devices.name`, because that
 * field resolves LLDP neighbours into topology links and labels every alarm. This
 * is where a human decides — for one device or for the whole fleet in a single
 * action, which is the point: an SD-WAN migration renames dozens of appliances and
 * nobody should retype them.
 */
class DeviceHostnameController extends Controller
{
    /**
     * Every device whose hostname disagrees with its record, plus the fleet's
     * coverage — how many devices have actually been read.
     *
     * The coverage figures matter: "0 mismatches" out of 40 devices checked means
     * something very different from "0 mismatches" out of 302, and without them a
     * poller that stopped running looks exactly like a fleet in perfect order.
     */
    public function drift(): JsonResponse
    {
        $devices = Device::with('site:id,name')
            ->orderBy('name')
            ->get(['id', 'site_id', 'name', 'snmp_hostname', 'hostname_checked_at', 'previous_name', 'renamed_at',
                'role', 'ip_address', 'model', 'serial_number', 'previous_serial_number', 'hardware_changed_at']);

        $checked = $devices->filter(fn (Device $d) => $d->hostname_checked_at !== null);

        return response()->json([
            'data' => $devices->filter(fn (Device $d) => HostnamePoller::drifted($d))
                ->map(fn (Device $d) => [
                    'id' => $d->id,
                    'name' => $d->name,
                    'snmp_hostname' => $d->snmp_hostname,
                    'site_id' => $d->site_id,
                    'site_name' => $d->site?->name,
                    'role' => $d->role,
                    'model' => $d->model,
                    'ip_address' => $d->ip_address,
                    'checked_at' => $d->hostname_checked_at?->toIso8601String(),
                    // WHY the name moved, which is the difference between a decision
                    // and a guess. A serial that changed with it is a unit that was
                    // physically swapped — the new name is the new box's own. A serial
                    // that did not is someone renaming the appliance on the console,
                    // which is just as valid but worth reading differently.
                    'hardware_changed_at' => $d->hardware_changed_at?->toIso8601String(),
                    'previous_serial_number' => $d->previous_serial_number,
                    'serial_number' => $d->serial_number,
                ])->values(),
            'coverage' => [
                'devices' => $devices->count(),
                'checked' => $checked->count(),
                // Never read = never contradicted. Said out loud so an unpolled fleet
                // cannot be mistaken for an agreeing one.
                'unchecked' => $devices->count() - $checked->count(),
            ],
        ]);
    }

    /**
     * Adopt the observed hostname as the device's name.
     *
     * `device_ids` names the devices to rename; omit it to adopt every drifted
     * device at once. The outgoing name is kept in `previous_name` with the date —
     * a rename that leaves no trail makes the topology impossible to reason about
     * three months later, and this action can move hundreds of rows.
     */
    public function adopt(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_ids' => ['nullable', 'array'],
            'device_ids.*' => ['integer', 'exists:devices,id'],
        ]);

        $devices = Device::query()
            ->when($data['device_ids'] ?? null, fn ($q, $ids) => $q->whereIn('id', $ids))
            ->get()
            // Re-checked server-side: the list the operator saw may be minutes old,
            // and renaming a device that has since come back into agreement would
            // rewrite it for no reason.
            ->filter(fn (Device $d) => HostnamePoller::drifted($d));

        $renamed = [];
        foreach ($devices as $device) {
            $was = $device->name;
            $device->forceFill([
                'previous_name' => $was,
                'name' => trim((string) $device->snmp_hostname),
                'renamed_at' => now(),
            ])->save();

            Log::info('Device renamed to its own hostname', [
                'device_id' => $device->id, 'ip' => $device->ip_address,
                'was' => $was, 'now' => $device->name, 'by' => $request->user()->id,
            ]);

            $renamed[] = ['id' => $device->id, 'was' => $was, 'now' => $device->name];
        }

        return response()->json(['renamed' => count($renamed), 'devices' => $renamed]);
    }
}
