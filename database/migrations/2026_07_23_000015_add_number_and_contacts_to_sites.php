<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('site_number')->nullable()->after('name');
            $table->string('region')->nullable();
            $table->string('main_phone')->nullable();
            $table->string('fax')->nullable();
            // Site contacts for Layer-1 troubleshooting (who to call at the site).
            $table->string('gm_name')->nullable();
            $table->string('gm_phone')->nullable();
            $table->string('gm_ext')->nullable();
            $table->string('om_name')->nullable();
            $table->string('om_phone')->nullable();
            $table->string('om_ext')->nullable();
            $table->string('sm_name')->nullable();
            $table->string('sm_phone')->nullable();
            $table->string('sm_ext')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn([
                'site_number', 'region', 'main_phone', 'fax',
                'gm_name', 'gm_phone', 'gm_ext',
                'om_name', 'om_phone', 'om_ext',
                'sm_name', 'sm_phone', 'sm_ext',
            ]);
        });
    }
};
