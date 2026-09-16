<?php

namespace App\Observers;

use Illuminate\Database\Eloquent\Model;

class DeviceAlarmObserver extends AbstractAlertObserver
{
    protected function resolveField(): string
    {
        return 'cleared_at';
    }

    protected function describe(Model $alert): array
    {
        $device = $alert->device;

        $severity = $alert->severity === 'critical' ? 'critical' : 'warning';
        $label = strtoupper($severity);
        $ticket = $alert->ticket_number ? " #{$alert->ticket_number}" : '';

        return [
            'severity' => $severity,
            'open_subject' => "{$label} — Alarm{$ticket} {$alert->alarm_id} on {$device?->name}",
            'resolved_subject' => "RESOLVED — Alarm{$ticket} {$alert->alarm_id} cleared on {$device?->name}",
            'body' => "Device: {$device?->name}\nTicket:{$ticket}\nAlarm: {$alert->alarm_id}\n{$alert->description}",
            'device_id' => $device?->id,
            'site_id' => $device?->site_id,
            'context' => ['type' => 'device_alarm', 'device_id' => $device?->id, 'alarm_id' => $alert->alarm_id, 'ticket_number' => $alert->ticket_number],
        ];
    }
}
