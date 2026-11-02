<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ifInErrors/ifOutErrors — packets dropped for errors (CRC, framing,
        // alignment). Non-zero errors are the "find any CRC/drops" signal an
        // operator wants when clicking an interface in the topology.
        Schema::table('device_interfaces', function (Blueprint $table) {
            $table->unsignedBigInteger('in_errors')->default(0)->after('out_discards');
            $table->unsignedBigInteger('out_errors')->default(0)->after('in_errors');
            $table->unsignedBigInteger('in_errors_delta')->default(0)->after('out_discards_delta');
            $table->unsignedBigInteger('out_errors_delta')->default(0)->after('in_errors_delta');
        });

        Schema::table('interface_metric_history', function (Blueprint $table) {
            $table->bigInteger('in_errors_delta')->default(0)->after('out_discards_delta');
            $table->bigInteger('out_errors_delta')->default(0)->after('in_errors_delta');
        });
    }

    public function down(): void
    {
        Schema::table('device_interfaces', function (Blueprint $table) {
            $table->dropColumn(['in_errors', 'out_errors', 'in_errors_delta', 'out_errors_delta']);
        });
        Schema::table('interface_metric_history', function (Blueprint $table) {
            $table->dropColumn(['in_errors_delta', 'out_errors_delta']);
        });
    }
};
