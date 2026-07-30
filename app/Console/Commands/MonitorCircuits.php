<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPollLoop;
use App\Models\Device;
use App\Services\CircuitMonitor;
use App\Support\SshSession;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Throwable;

class MonitorCircuits extends Command
{
    use RunsPollLoop;

    protected $signature = 'circuits:monitor';

    protected $description = "Continuously checks each circuit (direct ICMP, or a WAN-sourced ping from the Silver Peak for DHCP/NAT circuits).";

    /** Pings run concurrently; ICMP is pure wait, so many fit in one host. */
    private const PING_CONCURRENCY = 40;

    public function handle(): int
    {
        $monitor = new CircuitMonitor(
            fn (string $ip): ?array => $this->pingOne($ip),
            fn (Device $edge, string $wan, string $target): ?float => $this->sdwanPing($edge, $wan, $target),
            fn (array $ips): array => $this->pingMany($ips),
        );

        $this->info('Circuit monitor started, checking every 60 seconds.');

        $this->pollForever('circuits', 60, fn () => $monitor->checkAll(fn () => $this->beat()));

        return self::SUCCESS;
    }

    /** One circuit, direct ICMP → loss %/best RTT, or null on total failure. */
    private function pingOne(string $ip): ?array
    {
        // Ten probes so a single dropped probe is 10% (below the degraded
        // threshold) rather than 20% — finer packet-loss resolution.
        $process = new Process(['ping', '-c', '10', '-W', '2', $ip]);
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
        $queue = array_values(array_unique($ips));
        $running = [];   // ip => Process
        $results = [];

        $fill = function () use (&$queue, &$running) {
            while (count($running) < self::PING_CONCURRENCY && $queue) {
                $ip = array_shift($queue);
                $p = new Process(['ping', '-c', '10', '-W', '2', $ip]);
                $p->setTimeout(15);
                try {
                    $p->start();
                    $running[$ip] = $p;
                } catch (Throwable $e) {
                    $results[$ip] = null; // couldn't even launch → treat as down
                }
            }
        };

        $fill();
        while ($running) {
            foreach ($running as $ip => $p) {
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
            $fill(); // top the pool back up as slots free
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
