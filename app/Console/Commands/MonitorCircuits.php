<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPollLoop;
use App\Models\Device;
use App\Services\CircuitMonitor;
use App\Support\SshSession;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Throwable;

class MonitorCircuits extends Command
{
    use RunsPollLoop;

    protected $signature = 'circuits:monitor {--once : Run a single sweep in this process and exit}';

    protected $description = 'Continuously checks each circuit (direct ICMP, or a WAN-sourced ping from the Silver Peak for DHCP/NAT circuits).';

    /** Pings run concurrently; ICMP is pure wait, so many fit in one host. */
    /**
     * Concurrent ping processes.
     *
     * Forty wedged the collector. Forty is what this swept with on 15 September when 232
     * of 255 circuits reported down at once: the host choked on its own diagnostic, the
     * replies never got processed, and every circuit read 100% loss while a single ping
     * by hand to the same address returned 0%. Running forty at once by hand froze the
     * box outright — the sweep had been doing that to itself every sixty seconds.
     *
     * Eight was too far the other way: 247 circuits at eight at a time is ~340s of
     * batch, and the batch emits no heartbeat, so the supervisor declared the poller
     * hung at 180s and killed it mid-sweep — forever. No sweep ever finished and the
     * circuit table froze on its last reading.
     *
     * Twenty-four keeps the batch near two minutes and the peak process count well
     * under what wedged the host, and pingMany() now beats while it works so a long
     * batch is never mistaken for a hang.
     */
    private const PING_CONCURRENCY = 4;

    public function handle(): int
    {
        $monitor = new CircuitMonitor(
            fn (string $ip): ?array => $this->pingOne($ip),
            fn (Device $edge, string $wan, string $target): ?float => $this->sdwanPing($edge, $wan, $target),
            fn (array $ips): array => $this->pingMany($ips),
        );
        // Path capture is production behaviour, wired here on purpose: the moment a
        // circuit crosses into loss the poller has the path traced, so the hop is on
        // record while the loss is happening rather than reconstructed after the
        // carrier fixed it. Dispatched to the queue, never run in this loop — eighteen
        // inline traces on one sweep once stretched sixty seconds past four minutes.
        $monitor->capturePaths = true;

        // One sweep per PROCESS, by design. On 15 September the long-running loop
        // reported ~200 of 244 circuits at 100% loss for hours while the identical
        // sweep — same code, same container, same addresses, same concurrency — run
        // from a fresh php process returned 228 of 244 clean. Whatever the running
        // process accumulates that poisons its pings has not been found; a fresh
        // process per sweep sidesteps it, and the supervisor's restart-on-exit
        // provides the cadence. The heartbeat is armed so the watchdog still holds.
        if ($this->option('once')) {
            $this->armHeartbeat('circuits', 60);
            $monitor->beginSweep();
            $monitor->checkAll(fn () => $this->beat());

            return self::SUCCESS;
        }

        $this->info('Circuit monitor started, checking every 60 seconds.');

        $this->pollForever('circuits', 60, function () use ($monitor) {
            // Reset the per-sweep auto-probe budget before each pass.
            $monitor->beginSweep();
            $monitor->checkAll(fn () => $this->beat());
        });

        return self::SUCCESS;
    }

    /** One circuit, direct ICMP → loss %/best RTT, or null on total failure. */
    private function pingOne(string $ip): ?array
    {
        // Ten probes so a single dropped probe is 10% (below the degraded
        // threshold) rather than 20% — finer packet-loss resolution.
        $process = new Process(['ping', '-c', '5', '-W', '2', $ip]);
        // ping -c10 -W2 finishes in well under this; the cap stops a wedged ping
        // process from burning Symfony's default 60s and slowing the sweep.
        $process->setTimeout(15);
        $process->run();

        return $this->parsePing($process->getOutput().$process->getErrorOutput(), $process->isSuccessful());
    }

    /**
     * Ping many IPs CONCURRENTLY (bounded pool) and return ip => result. A
     * 240-circuit sweep collapses from minutes of sequential waits to seconds,
     * so no circuit late in the ordering is starved of a fresh check.
     *
     * @param  list<string>  $ips
     * @return array<string, array{loss:int, rtt:?float}|null>
     */
    private function pingMany(array $ips): array
    {
        // The batch runs before any per-circuit work, so without this the whole of it
        // is heartbeat-silent and a slow sweep is indistinguishable from a hung one.
        $this->beat();

        $queue = array_values(array_unique($ips));
        $running = [];   // ip => Process
        $results = [];
        $launchFailure = null;

        $fill = function () use (&$queue, &$running, &$results, &$launchFailure) {
            while (count($running) < self::PING_CONCURRENCY && $queue) {
                $ip = array_shift($queue);
                // Default 1s interval, deliberately. Packing the same ten probes into
                // two seconds (-i 0.2) multiplied the outbound rate fivefold on a host
                // that was already starved — the cure for a slow ping is a longer budget,
                // not a faster one. 10 probes ≈ 10s; 30s leaves real headroom.
                $p = new Process(['ping', '-c', '5', '-W', '2', $ip]);
                $p->setTimeout(30);
                try {
                    $p->start();
                    $running[$ip] = $p;
                } catch (Throwable $e) {
                    // Unmeasured, NOT down — and say why. This exception was swallowed
                    // silently on 15 September while every launch on the host failed,
                    // so the fleet read as down with nothing in the log to explain it.
                    $results[$ip] = null;
                    if (! $launchFailure) {
                        $launchFailure = $e->getMessage();
                    }
                }
            }
        };

        $fill();
        while ($running) {
            foreach ($running as $ip => $p) {
                try {
                    // Enforce setTimeout: start() never checks it on its own, so a
                    // hung ping would spin this loop forever and starve the sweep.
                    $p->checkTimeout();
                } catch (Throwable $e) {
                    $results[$ip] = null; // hung ping → treat as down
                    $p->stop(0);
                    unset($running[$ip]);

                    continue;
                }
                if ($p->isRunning()) {
                    continue;
                }
                try {
                    $results[$ip] = $this->parsePing($p->getOutput().$p->getErrorOutput(), $p->isSuccessful());
                } catch (Throwable $e) {
                    $results[$ip] = null;
                }
                unset($running[$ip]);
            }
            if ($running) {
                usleep(100_000); // 100ms between polls of the running set
            }
            // Beat as the batch drains: liveness is proven by progress, not by silence.
            $this->beat();
            $fill(); // top the pool back up as slots free
        }

        if ($launchFailure !== null) {
            Log::error("Circuit sweep: could not launch ping — {$launchFailure}");
        }

        return $results;
    }

    /** Parse `ping` output → loss %/best RTT, or null on total failure. */
    private function parsePing(string $out, bool $ok): ?array
    {
        // "X% packet loss" — the authoritative loss figure from ping itself.
        $loss = preg_match('/([\d.]+)\s*%\s*packet loss/i', $out, $m) ? (int) round((float) $m[1]) : null;

        // Total failure (host unresolvable, network unreachable) → hard down.
        if ($loss === null) {
            return $ok ? ['loss' => 0, 'rtt' => 0.0] : null;
        }

        // Best round-trip time from any reply ("time=12.3 ms").
        $rtt = preg_match('/time[=<]\s*([\d.]+)\s*ms/i', $out, $t) ? (float) $t[1] : null;

        return ['loss' => $loss, 'rtt' => $loss < 100 ? ($rtt ?? 0.0) : null];
    }

    /**
     * Source a ping from the Silver Peak out a specific WAN — proof the circuit
     * passes traffic even when its DHCP public IP is unreachable behind ISP NAT.
     * Returns the RTT (ms) on any reply, null if it can't pass traffic / SSH fails.
     */
    private function sdwanPing(Device $edge, string $wan, string $target): ?float
    {
        // Whitelist to keep the SSH command injection-free.
        if (! preg_match('/^wan\d{1,2}$/', $wan) || ! filter_var($target, FILTER_VALIDATE_IP)) {
            return null;
        }

        try {
            $out = SshSession::run($edge, ["ping {$target} -I {$wan}"]);
            $text = implode("\n", $out);

            // Any reply = the circuit passes traffic.
            $received = preg_match('/(\d+)\s+(?:packets\s+)?received/i', $text, $m) ? (int) $m[1] : 0;
            if ($received === 0 && ! preg_match('/bytes from/i', $text)) {
                return null;
            }

            return preg_match('/time[=<]\s*([\d.]+)\s*ms/i', $text, $t) ? (float) $t[1] : 0.0;
        } catch (Throwable $e) {
            return null;
        }
    }
}
