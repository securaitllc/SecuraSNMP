<?php

namespace Tests\Feature;

use App\Console\Commands\Concerns\RunsPollLoop;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

/** Thrown by the harness to break out of the otherwise infinite loop. */
class StopLoop extends RuntimeException {}

/**
 * A poller loop must survive a failing iteration.
 *
 * circuits:monitor died on Massey production at 2026-07-26 11:10:58Z and stayed
 * dead for ~25 hours. Nothing restarts an individual artisan loop, and its sibling
 * loops kept running, so the only visible symptom was circuit response-time history
 * silently stopping while every circuit still displayed its last known "up".
 */
class PollLoopSurvivesFailureTest extends TestCase
{
    /** Runs the real trait, but records sleeps and stops after N of them. */
    private function harness(int $stopAfter): object
    {
        return new class($stopAfter)
        {
            use RunsPollLoop;

            public array $slept = [];

            public array $errors = [];

            public function __construct(private int $stopAfter) {}

            protected function sleepFor(int $seconds): void
            {
                $this->slept[] = $seconds;
                if (count($this->slept) >= $this->stopAfter) {
                    throw new StopLoop;
                }
            }

            /** No-op the disk write; these tests only assert loop control flow. */
            protected function recordHeartbeat(string $label, int $intervalSeconds): void {}

            /** Stands in for Command::error(). */
            public function error($string, $verbosity = null): void
            {
                $this->errors[] = $string;
            }

            public function run(int $seconds, callable $tick): void
            {
                try {
                    $this->pollForever('test', $seconds, $tick);
                } catch (StopLoop) {
                    // expected
                }
            }
        };
    }

    public function test_an_exception_does_not_end_the_loop(): void
    {
        Log::spy();
        $h = $this->harness(stopAfter: 5);

        $seen = [];
        $h->run(60, function () use (&$seen) {
            $seen[] = count($seen) + 1;
            if (count($seen) === 2) {
                throw new RuntimeException('transient DB blip');
            }
        });

        // Iteration 2 threw; 3, 4 and 5 must still have run.
        $this->assertSame([1, 2, 3, 4, 5], $seen);
        $this->assertCount(1, $h->errors);
        $this->assertStringContainsString('transient DB blip', $h->errors[0]);
    }

    public function test_repeated_failures_back_off(): void
    {
        Log::spy();
        $h = $this->harness(stopAfter: 4);

        $h->run(60, fn () => throw new RuntimeException('database is down'));

        $this->assertSame([60, 120, 180, 240], $h->slept);
    }

    public function test_backoff_is_capped_so_recovery_is_still_noticed(): void
    {
        Log::spy();
        $h = $this->harness(stopAfter: 30);

        $h->run(300, fn () => throw new RuntimeException('down'));

        $this->assertLessThanOrEqual(600, max($h->slept));
        $this->assertSame(600, max($h->slept));
    }

    public function test_a_recovering_loop_resets_its_backoff(): void
    {
        Log::spy();
        $h = $this->harness(stopAfter: 4);

        $n = 0;
        $h->run(30, function () use (&$n) {
            $n++;
            if ($n <= 2) {
                throw new RuntimeException('blip');
            }
        });

        // fail, fail, ok, ok → 30, 60, then straight back to the base interval.
        $this->assertSame([30, 60, 30, 30], $h->slept);
    }

    public function test_a_healthy_loop_sleeps_the_plain_interval(): void
    {
        Log::spy();
        $h = $this->harness(stopAfter: 3);

        $h->run(90, fn () => null);

        $this->assertSame([90, 90, 90], $h->slept);
        $this->assertSame([], $h->errors);
    }
}
