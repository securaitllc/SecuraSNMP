<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->enum('vendor', ['juniper', 'silverpeak', 'fortigate'])->change();
            $table->enum('role', ['switch', 'edgeconnect', 'firewall'])->change();
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->enum('vendor', ['juniper', 'silverpeak'])->change();
            $table->enum('role', ['switch', 'edgeconnect'])->change();
        });
    }
};
