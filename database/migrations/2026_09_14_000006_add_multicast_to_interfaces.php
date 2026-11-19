<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Received multicast, so a discard can be explained instead of assumed.
     *
     * ifInDiscards counts every frame the interface chose not to deliver. On a routed
     * WAN port sitting on a shared bridge, that is overwhelmingly multicast the device
     * had no reason to accept — correct behaviour, not congestion. The proof came off
     * an EdgeConnect:
     *
     *     RX mcast packets:   1759152
     *     RX discards:        1759152
     *
     * Exactly equal. Every "discard" was a multicast frame. Nodus had been reading that
     * counter alone, grading those ports "congested", and raising anomalies on them —
     * 150 of 172 open findings were this artifact. Without the multicast counter beside
     * it a discard cannot be told apart from a drop, so the poller now collects both.
     */
    public function up(): void
    {
        Schema::table('device_interfaces', function (Blueprint $table) {
            $table->unsignedBigInteger('in_multicast')->default(0)->after('in_errors_delta');
            $table->unsignedBigInteger('in_multicast_delta')->default(0)->after('in_multicast');
        });

        Schema::table('interface_metric_history', function (Blueprint $table) {
            $table->unsignedBigInteger('in_multicast_delta')->default(0)->after('in_discards_delta');
        });
    }

    public function down(): void
    {
        Schema::table('device_interfaces', function (Blueprint $table) {
            $table->dropColumn(['in_multicast', 'in_multicast_delta']);
        });
        Schema::table('interface_metric_history', function (Blueprint $table) {
            $table->dropColumn('in_multicast_delta');
        });
    }
};
