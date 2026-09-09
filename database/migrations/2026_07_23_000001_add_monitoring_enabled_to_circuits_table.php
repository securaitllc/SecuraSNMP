<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Temporarily take a circuit out of monitoring (planned disconnect /
        // maintenance) without deleting it — so it stops pinging and never raises
        // a false "circuit down". Re-enable later, editing the monitored IP if the
        // circuit came back with new info.
        Schema::table('circuits', function (Blueprint $table) {
            $table->boolean('monitoring_enabled')->default(true)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('circuits', function (Blueprint $table) {
            $table->dropColumn('monitoring_enabled');
        });
    }
};
