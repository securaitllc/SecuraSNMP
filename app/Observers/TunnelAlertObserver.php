<?php

namespace App\Observers;

use Illuminate\Database\Eloquent\Model;

class TunnelAlertObserver extends AbstractAlertObserver
{
    protected function resolveField(): string
    {
        return 'ended_at';
    }

    /** An operator clearing an alert is not the tunnel recovering — don't announce
     *  "tunnel up". The verifier's auto-close (tunnel back up) still notifies. */
    public function updated(Model $alert): void
    {
        if ($alert->cleared_manually) {
            return;
        }

        parent::updated($alert);
    }

    protected function describe(Model $alert): array
    {
        $tunnel = $alert->tunnel()->with('device')->first();
        $device = $tunnel?->device;

        return [
            'severity' => 'warning',
            'open_subject' => "WARNING — Tunnel {$tunnel?->tunnel_name} DOWN on {$device?->name}",
            'resolved_subject' => "RESOLVED — Tunnel {$tunnel?->tunnel_name} up on {$device?->name}",
            'body' => "Device: {$device?->name}\nTunnel: {$tunnel?->tunnel_name}",
            'device_id' => $device?->id,
            'site_id' => $device?->site_id,
            'context' => ['type' => 'tunnel', 'device_id' => $device?->id, 'tunnel_id' => $tunnel?->id],
        ];
    }
}
