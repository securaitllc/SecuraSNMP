<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discovered_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('discovery_scan_id')->constrained()->cascadeOnDelete();
            $table->string('ip_address');
            $table->string('sys_name')->nullable();
            $table->text('sys_descr')->nullable();
            $table->string('sys_object_id')->nullable();
            $table->string('vendor')->nullable();
            $table->string('model')->nullable();
            $table->string('serial_number')->nullable();
            // Role suggested by the Massey convention (.10 = switch, .254 = SD-WAN).
            $table->string('suggested_role')->nullable();
            $table->foreignId('suggested_site_id')->nullable()->constrained('sites')->nullOnDelete();
            // Existing device this host already matches (by IP or serial) — dedup.
            $table->foreignId('matched_device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->foreignId('imported_device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->enum('status', ['new', 'existing', 'imported', 'ignored'])->default('new');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discovered_devices');
    }
};
