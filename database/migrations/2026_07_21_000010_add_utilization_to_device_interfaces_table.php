<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_interfaces', function (Blueprint $table) {
            // Link speed (bps) from ifHighSpeed/ifSpeed, and the last computed
            // utilization % of that speed in each direction.
            $table->unsignedBigInteger('speed_bps')->default(0)->after('out_discards_delta');
            $table->decimal('in_util_pct', 5, 2)->default(0)->after('speed_bps');
            $table->decimal('out_util_pct', 5, 2)->default(0)->after('in_util_pct');
        });
    }

    public function down(): void
    {
        Schema::table('device_interfaces', function (Blueprint $table) {
            $table->dropColumn(['speed_bps', 'in_util_pct', 'out_util_pct']);
        });
    }
};
