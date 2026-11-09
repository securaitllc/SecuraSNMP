<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anti-replay for TOTP: the timestamp (30s step) of the last accepted code, so a
 * captured code can't be replayed within its validity window — each code must be
 * strictly newer than the one before it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('two_factor_last_ts')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('two_factor_last_ts');
        });
    }
};
