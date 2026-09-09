<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPollLoop;
use App\Services\DeviceMonitor;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Throwable;

class MonitorDevices extends Command
{
    use RunsPollLoop;

    /** Pings run concurrently; ICMP is pure wait, so many fit in one host. */
    private const PING_CONCURRENCY = 40;

    protected $signature = 'devices:monitor';

    protected $description = "Continuously ICMP-pings each active device and records its response time.";

    public function handle(): int
    {
        $monitor = new DeviceMonitor(
            fn (string $ip): ?float => $this->pingOne($ip),
            fn (array $ips): array => $this->pingMany($ips),
        );

        $interval = max(15, (int) config('monitoring.device_interval'));
        $this->info("Device monitor started, pinging every {$interval}s.");

        $this->pollForever('devices', $interval, fn () => $monitor->checkAll(fn () => $this->beat()));
    }

    /** One device, single ICMP probe → RTT ms, or null if it did not answer. */
    private function pingOne(string $ip): ?float
    {
        $process = new Process(['ping', '-c', '1', '-W', '2', $ip]);
        // Cap a wedged ping so one device cannot stall the sweep (Symfony's default
        // is 60s; this ping needs a couple of seconds at most).
        $process->setTimeout(10);
        $process->run();

        return $this->parsePing($process->getOutput(), $process->isSuccessful());
    }

    /**
     * Ping many device IPs CONCURRENTLY (bounded pool) → ip => rtt|null. A
     * 270-device fleet is measured in seconds instead of minutes, so a device that
     * goes down is detected on the next cycle rather than after a long sweep.
     *
     * @param  list<string>  $ips
     * @return array<string, ?float>
     */
    private function pingMany(array $ips): array
    {
        $queue = array_values(array_unique($ips));
        $running = [];   // ip => Process
        $results = [];

        $fill = function () use (&$queue, &$running) {
            while (count($running) < self::PING_CONCURRENCY && $queue) {
                $ip = array_shift($queue);
                $p = new Process(['ping', '-c', '1', '-W', '2', $ip]);
                $p->setTimeout(10);
                try {
                    $p->start();
                    $running[$ip] = $p;
                } catch (Throwable $e) {
                    $results[$ip] = null; // couldn't launch → treat as down
                }
            }
        };

        $fill();
        while ($running) {
            foreach ($running as $ip => $p) {
                try {
                    // Enforce setTimeout: start() alone never checks it, so a ping
                    // stuck in DNS / an uninterruptible state would spin the loop
                    // forever and starve the fleet. checkTimeout() kills it on breach.
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
                    $results[$ip] = $this->parsePing($p->getOutput(), $p->isSuccessful());
                } catch (Throwable $e) {
                    $results[$ip] = null;
                }
                unset($running[$ip]);
            }
            if ($running) {
                usleep(100_000); // 100ms between polls of the running set
            }
            $fill();
        }

        return $results;
    }

    /** ping output → RTT ms, or null when the host did not answer. */
    private function parsePing(string $output, bool $ok): ?float
    {
        if (! $ok) {
            return null;
        }

        if (preg_match('/time[=<]\s*([\d.]+)\s*ms/i', $output, $matches)) {
            return (float) $matches[1];
        }

        return 0.0;
    }
}
