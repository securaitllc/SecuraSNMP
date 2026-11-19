<?php

namespace App\Observers;

use Illuminate\Database\Eloquent\Model;

class InterfaceAlertObserver extends AbstractAlertObserver
{
    protected function resolveField(): string
    {
        return 'ended_at';
    }

    /** An operator clearing an alert is not the port recovering — don't announce
     *  "interface up". The poller's auto-close (port back up) still notifies. */
    public function updated(Model $alert): void
    {
        if ($alert->cleared_manually) {
            return;
        }

        parent::updated($alert);
    }

    protected function describe(Model $alert): array
    {
        $interface = $alert->deviceInterface()->with('device')->first();
        $device = $interface?->device;

        // An uplink port (to another switch or an SD-WAN) is CRITICAL; a regular
        // access port is a WARNING. Severity is set by the poller at creation.
        $severity = $alert->severity === 'critical' ? 'critical' : 'warning';
        $label = strtoupper($severity);
        $kind = $severity === 'critical' ? 'Uplink' : 'Interface';

        return [
            'severity' => $severity,
            'open_subject' => "{$label} — {$kind} {$interface?->if_name} DOWN on {$device?->name}",
            'resolved_subject' => "RESOLVED — {$kind} {$interface?->if_name} up on {$device?->name}",
            'body' => "Device: {$device?->name}\nInterface: {$interface?->if_name}\nType: ".($severity === 'critical' ? 'Uplink (switch/SD-WAN)' : 'Access port'),
            'device_id' => $device?->id,
            'site_id' => $device?->site_id,
            'context' => ['type' => 'interface', 'device_id' => $device?->id, 'interface_id' => $interface?->id],
        ];
    }
}
