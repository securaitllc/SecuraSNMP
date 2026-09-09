<?php

namespace App\Observers;

use App\Models\DeviceNextHop;
use Illuminate\Database\Eloquent\Model;

class NextHopAlertObserver extends AbstractAlertObserver
{
    protected function resolveField(): string
    {
        return 'ended_at';
    }

    protected function describe(Model $alert): array
    {
        $device = $alert->device;
        $nh = $alert->device_next_hop_id ? DeviceNextHop::find($alert->device_next_hop_id) : null;

        $ip = $nh?->ip_address ?? $device?->next_hop_ip ?? 'next-hop';
        $intf = $nh?->interface ? " ({$nh->interface})" : '';

        // Critical only when every WAN next-hop is down; otherwise a redundant WAN
        // is still carrying traffic, so it's a warning.
        $othersUp = $device
            ? DeviceNextHop::where('device_id', $device->id)
                ->when($nh, fn ($q) => $q->where('id', '!=', $nh->id))
                ->where('status', 'up')->exists()
            : false;
        $severity = $othersUp ? 'warning' : 'critical';
        $label = strtoupper($severity);

        return [
            'severity' => $severity,
            'open_subject' => "{$label} — Next-hop {$ip}{$intf} unreachable via {$device?->name}",
            'resolved_subject' => "RESOLVED — Next-hop {$ip}{$intf} reachable via {$device?->name}",
            'body' => "Device: {$device?->name}\nNext-hop: {$ip}{$intf}\nRedundant WAN up: ".($othersUp ? 'yes' : 'no'),
            'device_id' => $device?->id,
            'site_id' => $device?->site_id,
            'context' => ['type' => 'next_hop', 'device_id' => $device?->id, 'next_hop' => $ip],
        ];
    }
}
