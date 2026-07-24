<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Services\CircuitMonitor;
use App\Support\SshSession;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Throwable;

class MonitorCircuits extends Command
{
    protected $signature = 'circuits:monitor';

    protected $description = "Continuously checks each circuit (direct ICMP, or a WAN-sourced ping from the Silver Peak for DHCP/NAT circuits).";

    public function handle(): int
    {
        $monitor = new CircuitMonitor(
            function (string $ip): ?float {
                $process = new Process(['ping', '-c', '1', '-W', '2', $ip]);
                $process->run();

                if (! $process->isSuccessful()) {
                    return null;
                }

                // Parse the round-trip time from a line like "time=12.3 ms".
                if (preg_match('/time[=<]\s*([\d.]+)\s*ms/i', $process->getOutput(), $matches)) {
                    return (float) $matches[1];
                }

                // Reachable but no parseable timing — treat as up with an unknown
                // (zero) latency rather than a timeout.
                return 0.0;
            },
            fn (Device $edge, string $wan, string $target): ?float => $this->sdwanPing($edge, $wan, $target),
        );

        $this->info('Circuit monitor started, checking every 60 seconds.');

        while (true) {
            $monitor->checkAll();
            sleep(60);
        }
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
