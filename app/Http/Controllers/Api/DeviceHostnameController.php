<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DeviceHardwareChange;
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
                // Names that differ only by the operator's convention (a _SDW
                // suffix, zero-padded SC numbers, a site-code prefix). Counted so
                // the number is honest, excluded so the review list is short.
                'convention_only' => $devices->filter(fn (Device $d) => HostnamePoller::conventionOnly($d))->count(),
            ],
        ]);
    }

    /**
     * The trail of what devices used to be — serial, name, model, OS.
     *
     * `devices.previous_serial_number` only ever holds the last hop, so a unit
     * replaced twice reads as though it were replaced once. This is the whole
     * sequence, newest first, for one device or for the fleet.
     */
    public function changes(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_id' => ['nullable', 'integer', 'exists:devices,id'],
            'field' => ['nullable', 'string', 'in:serial_number,name,model,os_version'],
            'days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $rows = DeviceHardwareChange::with(['device:id,name,site_id', 'device.site:id,name'])
            ->when($data['device_id'] ?? null, fn ($q, $id) => $q->where('device_id', $id))
            ->when($data['field'] ?? null, fn ($q, $f) => $q->where('field', $f))
            ->where('detected_at', '>=', now()->subDays($data['days'] ?? 365))
            ->orderByDesc('detected_at')
            ->limit($data['limit'] ?? 200)
            ->get();

        return response()->json([
            'data' => $rows->map(fn (DeviceHardwareChange $c) => [
                'id' => $c->id,
                'device_id' => $c->device_id,
                'device_name' => $c->device?->name,
                'site_id' => $c->device?->site_id,
                'site_name' => $c->device?->site?->name,
                'field' => $c->field,
                'old_value' => $c->old_value,
                'new_value' => $c->new_value,
                // 'snmp' = the box told us, 'operator' = someone adopted or edited.
                // A serial that moved on its own is a replacement; a name adopted by
                // a person is bookkeeping. They read very differently in an audit.
                'source' => $c->source,
                'user_name' => $c->user_name,
                'detected_at' => $c->detected_at?->toIso8601String(),
            ])->values(),
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

            DeviceHardwareChange::record($device, 'name', $was, $device->name, 'operator', $request->user());

            Log::info('Device renamed to its own hostname', [
                'device_id' => $device->id, 'ip' => $device->ip_address,
                'was' => $was, 'now' => $device->name, 'by' => $request->user()->id,
            ]);

            $renamed[] = ['id' => $device->id, 'was' => $was, 'now' => $device->name];
        }

        return response()->json(['renamed' => count($renamed), 'devices' => $renamed]);
    }
}
