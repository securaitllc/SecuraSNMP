<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Nullable only: rows written before this migration simply lack the context
    // (LOG-01 auth events, LOG-03 target + field changes).
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('action', 40)->nullable()->after('id');
            $table->string('email')->nullable()->after('user_name');
            $table->string('user_agent', 512)->nullable()->after('ip_address');
            $table->string('target_type')->nullable()->after('status');
            $table->string('target_id', 64)->nullable()->after('target_type');
            $table->json('changes')->nullable()->after('target_id');

            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['action', 'created_at']);
            $table->dropColumn(['action', 'email', 'user_agent', 'target_type', 'target_id', 'changes']);
        });
    }
};
