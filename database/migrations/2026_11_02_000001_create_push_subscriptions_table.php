<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per phone/browser that opted into alarm push. Personal to a user,
     * unlike notification_channels which are org-wide integrations. The endpoint
     * is the push service URL the browser hands out; a 404/410 from it means the
     * device unsubscribed and the row is dropped.
     */
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('endpoint_hash', 64)->unique(); // sha256 of the endpoint (endpoints exceed index limits)
            $table->text('endpoint');
            $table->string('p256dh', 255);
            $table->string('auth', 255);
            $table->string('user_agent', 512)->nullable();
            $table->string('min_severity', 16)->default('critical');
            $table->timestamp('last_sent_at')->nullable();
            $table->unsignedSmallInteger('failures')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
