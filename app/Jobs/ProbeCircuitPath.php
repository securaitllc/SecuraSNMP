<?php

namespace App\Jobs;

use App\Models\Circuit;
use App\Services\CircuitPathProber;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Trace a circuit's path, off the poll loop.
 *
 * v0.10.21 ran the probe INSIDE the circuit sweep, synchronously, the moment a circuit
 * crossed into loss. On the first sweep after deploy eighteen circuits crossed at once,
 * each costing a ~12-second mtr, and the 60-second sweep stretched past four minutes.
 * Circuits late in the ordering were not polled at all in that time — #037's DIA, the
 * one the whole feature was built for, sat unchecked from 17:13 to past 17:19. The
 * fleet-isolation rule this codebase already carries — one slow thing must not starve
 * the rest — broken by the fix.
 *
 * The sweep dispatches this and moves on. The worker takes the seconds.
 */
class ProbeCircuitPath implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** A trace that hangs must not hold a worker slot for long. */
    public int $timeout = 150;

    public int $tries = 1;

    public function __construct(public int $circuitId, public string $trigger = 'auto') {}

    public function handle(): void
    {
        $circuit = Circuit::find($this->circuitId);
        if ($circuit === null) {
            return;
        }

        try {
            // Resolved from the container so a test can bind a prober with a fake
            // runner; production gets the default, which detects mtr and shells out.
            app(CircuitPathProber::class)->probe($circuit, $this->trigger);
        } catch (Throwable $e) {
            // Context, not the reading. A failed trace is logged and forgotten.
            Log::warning("Path probe failed for circuit {$circuit->id}: {$e->getMessage()}");
        }
    }
}
