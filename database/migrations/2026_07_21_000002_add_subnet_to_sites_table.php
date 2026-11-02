<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            // Management /24 (or other CIDR) used to auto-match discovered
            // devices to a site by their 3rd octet. Massey convention: the
            // 3rd octet identifies the site.
            $table->string('subnet')->nullable()->after('address');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('subnet');
        });
    }
};
