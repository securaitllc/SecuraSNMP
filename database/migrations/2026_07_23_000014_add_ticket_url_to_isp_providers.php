<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('isp_providers', function (Blueprint $table) {
            // Portal URL for opening a support/outage ticket online with this ISP.
            $table->string('ticket_url')->nullable()->after('support_phone');
        });
    }

    public function down(): void
    {
        Schema::table('isp_providers', function (Blueprint $table) {
            $table->dropColumn('ticket_url');
        });
    }
};
