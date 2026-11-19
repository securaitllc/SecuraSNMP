<?php

namespace Tests\Feature;

use App\Console\Commands\Concerns\RunsPollLoop;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

/**
 * A live poller must emit a heartbeat every cycle so the supervisor can tell a
 * healthy loop from a hung one. A tick that HANGS never completes, so its beat
 * goes stale — that is the signal that restarts it.
 */
class PollerHeartbeatTest extends TestCase
{
    private string $beat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->beat = storage_path('app/pollers/hbtest.beat');
        @unlink($this->beat);
    }

    protected function tearDown(): void
    {
        @unlink($this->beat);
        parent::tearDown();
    }

    /** Runs the REAL trait (real heartbeat write), stopping after N sleeps. */
    private function harness(int $stopAfter): object
    {
        return new class($stopAfter)
        {
            use RunsPollLoop;

            public function __construct(private int $stopAfter) {}

            protected function sleepFor(int $seconds): void
            {
                static $n = 0;
                if (++$n >= $this->stopAfter) {
                    throw new StopLoop;
                }
            }

            public function error($string, $verbosity = null): void {}

            public function run(int $seconds, callable $tick): void
            {
                try {
                    $this->pollForever('hbtest', $seconds, $tick);
                } catch (StopLoop) {
                    // expected
                }
            }

            /** Exposes the protected beat() for the mid-sweep progress test. */
            public function beatNow(): void
            {
                $this->beat();
            }
        };
    }

    public function test_beat_keeps_a_long_running_tick_fresh(): void
    {
        Log::spy();

        // A tick that beats mid-flight must refresh the heartbeat BEFORE the
        // iteration completes — this is what stops the watchdog killing a slow
        // sweep. Beat inside the tick, then assert the file already exists.
        $h = $this->harness(stopAfter: 2);
        $h->run(60, function () use ($h) {
            @unlink($this->beat);            // drop the boot beacon so we isolate beat()
            $this->assertFileDoesNotExist($this->beat);
            $h->beatNow();
            $this->assertFileExists($this->beat);
            [$ts, $interval] = preg_split('/\s+/', trim(file_get_contents($this->beat)));
            $this->assertSame(60, (int) $interval, 'beat() re-uses the running loop cadence');
        });
    }

    public function test_a_completed_iteration_writes_a_fresh_heartbeat(): void
    {
        Log::spy();
        $before = time();

        $this->harness(stopAfter: 3)->run(90, fn () => null);

        $this->assertFileExists($this->beat);
        [$ts, $interval] = preg_split('/\s+/', trim(file_get_contents($this->beat)));
        $this->assertGreaterThanOrEqual($before, (int) $ts);
        $this->assertSame(90, (int) $interval, 'the beat records the poller cadence');
    }

    public function test_the_boot_beacon_is_written_before_the_first_tick(): void
    {
        Log::spy();

        // The very first tick throws; without the boot beacon there would be no
        // heartbeat at all until an iteration completes. Prove one exists anyway.
        $this->harness(stopAfter: 2)->run(60, function () {
            throw new RuntimeException('first tick blows up');
        });

        $this->assertFileExists($this->beat);
    }
}
