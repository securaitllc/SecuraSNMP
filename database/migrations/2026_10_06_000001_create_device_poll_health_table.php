<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether each poller can still reach each device — the thing nobody was tracking.
     *
     * Nodus already learned this lesson one level up. `circuits:monitor` died twice on
     * production and went unnoticed for ~25 hours because its sibling loops kept
     * running, so RunsPollLoop and PollerHealthController exist to make a dead LOOP
     * visible. This is the same failure one level down: the loop is alive, sweeping
     * every cycle, and failing on the same devices every time.
     *
     * On 6 October fourteen appliances had gone two to five days without a single
     * successful SSH poll. The dashboard read 304 of 304 reachable, zero unmeasured,
     * one alarm. All green. ICMP answered and SNMP answered, so each device "counted";
     * SSH — where tunnels, next-hops and underlay loss come from — had been refused
     * since a hardware swap changed the login, and the only trace was one
     * `Log::error` per sweep in a file nobody reads.
     *
     * Keyed on TRANSPORT rather than on the individual command: an operator needs to
     * know "Nodus can no longer log into this box", not which of two SSH commands
     * noticed. Two pollers recording the same transport is correct — if either got in,
     * the credential works.
     *
     * A row exists only for a device a poller has actually attempted, which is what
     * makes the alarm sweep self-scoping: a FortiGate nobody SSHes to never gets an
     * SSH row and can never raise an SSH staleness alarm.
     */
    public function up(): void
    {
        Schema::create('device_poll_health', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            // 'ssh' | 'snmp'. Deliberately a plain string, not an enum: SQLite ignores
            // enum constraints and MySQL rejects them, and that divergence has reached
            // production in this codebase more than once.
            $table->string('transport', 16);

            // The honest measure of staleness. Age of the last SUCCESS is what tells
            // you whether the data on screen is current; a failure count does not,
            // because a device can also simply stop being attempted.
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            // Already passed through SshError::safe — never a raw library message,
            // which can echo a credential.
            $table->string('last_error')->nullable();

            $table->timestamps();

            $table->unique(['device_id', 'transport']);
            $table->index(['transport', 'last_success_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_poll_health');
    }
};
