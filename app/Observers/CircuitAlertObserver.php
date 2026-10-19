<?php

namespace App\Observers;

use Illuminate\Database\Eloquent\Model;

class CircuitAlertObserver extends AbstractAlertObserver
{
    protected function resolveField(): string
    {
        return 'ended_at';
    }

    protected function describe(Model $alert): array
    {
        $circuit = $alert->circuit()->with('site')->first();
        $name = $circuit?->circuit_id ?? 'circuit';
        $site = $circuit?->site?->name;

        return [
            'severity' => 'critical',
            'open_subject' => "CRITICAL — Circuit {$name} DOWN".($site ? " at {$site}" : ''),
            'resolved_subject' => "RESOLVED — Circuit {$name} restored".($site ? " at {$site}" : ''),
            'body' => "Site: {$site}\nISP: {$circuit?->isp_name}\nMonitored IP: {$circuit?->monitored_ip}",
            'device_id' => null,
            'site_id' => $circuit?->site_id,
            'context' => ['type' => 'circuit', 'circuit_id' => $circuit?->id],
        ];
    }
}
