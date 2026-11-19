<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // When the ISP schedules a field dispatch for a circuit outage, the NOC
    // records the promised date/time (and who logged it) for the record and for
    // accountability — so an ETA is visible and a missed dispatch is provable.
    public function up(): void
    {
        Schema::table('circuit_alerts', function (Blueprint $table) {
            $table->timestamp('dispatch_at')->nullable()->after('cleared_manually');
            $table->text('dispatch_note')->nullable()->after('dispatch_at');
            $table->foreignId('dispatch_by')->nullable()->after('dispatch_note')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('circuit_alerts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dispatch_by');
            $table->dropColumn(['dispatch_at', 'dispatch_note']);
        });
    }
};
