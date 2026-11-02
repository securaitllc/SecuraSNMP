<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_vlans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('vlan_id');
            $table->string('name')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['device_id', 'vlan_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_vlans');
    }
};
