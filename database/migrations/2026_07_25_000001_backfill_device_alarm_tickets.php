<?php

use App\Models\DeviceAlarm;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // 2026_07_22_000004 added ticket_number to device_alarms but shipped no
        // backfill, unlike the interface_alerts and tunnel_alerts equivalents.
        // Alarms opened before that migration therefore carry a NULL ticket, so
        // the NOC sees an alarm it cannot reference. Assign one to each.
        DeviceAlarm::whereNull('ticket_number')->get()->each(function (DeviceAlarm $alarm) {
            $alarm->updateQuietly(['ticket_number' => DeviceAlarm::generateTicketNumber()]);
        });
    }

    public function down(): void
    {
        // No-op: tickets are not reverted.
    }
};
