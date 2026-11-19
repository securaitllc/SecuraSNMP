<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('circuits', function (Blueprint $table) {
            $table->foreignId('isp_provider_id')->nullable()->after('site_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('circuits', function (Blueprint $table) {
            $table->dropConstrainedForeignId('isp_provider_id');
        });
    }
};
