<?php

use App\Models\InterfaceAlert;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Alerts opened before the ticket workflow existed have no ticket. Assign
        // one to each so every alarm the operator sees is trackable.
        InterfaceAlert::whereNull('ticket_number')->get()->each(function (InterfaceAlert $alert) {
            $alert->updateQuietly(['ticket_number' => InterfaceAlert::generateTicketNumber()]);
        });
    }

    public function down(): void
    {
        // No-op: tickets are not reverted.
    }
};
