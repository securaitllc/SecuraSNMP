<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which layout a saved node position belongs to.
 *
 * Positions are absolute coordinates on the auto-layout's canvas. The site view
 * moved from left-to-right tiers to top-down tiers, so every position an operator
 * saved under the old layout now lands somewhere meaningless — HQ Orlando drew as
 * a thumbnail from coordinates that spanned the old wide canvas. Rows saved before
 * this change carry 'h1' and are no longer applied; new saves carry 'v1'. Reset
 * still clears both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('topology_positions', function (Blueprint $table) {
            $table->string('layout', 8)->default('h1')->after('node_id');
        });
    }

    public function down(): void
    {
        Schema::table('topology_positions', function (Blueprint $table) {
            $table->dropColumn('layout');
        });
    }
};
