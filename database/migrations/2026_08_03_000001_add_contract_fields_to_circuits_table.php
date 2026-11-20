<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('circuits', function (Blueprint $table) {
            // Contract accountability: when the circuit went live and when the
            // service contract expires. term_months lets a renewal auto-compute the
            // next end date. Plain dates — no enums (SQLite/MySQL enum divergence).
            $table->date('install_date')->nullable()->after('lec_support_phone');
            $table->date('contract_end_date')->nullable()->after('install_date');
            $table->unsignedSmallInteger('contract_term_months')->nullable()->after('contract_end_date');
            $table->index('contract_end_date');
        });
    }

    public function down(): void
    {
        Schema::table('circuits', function (Blueprint $table) {
            $table->dropColumn(['install_date', 'contract_end_date', 'contract_term_months']);
        });
    }
};
