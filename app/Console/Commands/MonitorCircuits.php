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

    public function handle(): int
    {
        $monitor = new CircuitMonitor(
            function (string $ip): ?array {
                // Five probes so we can measure packet loss, not just up/down.
                $process = new Process(['ping', '-c', '5', '-W', '2', $ip]);
                $process->run();
                $out = $process->getOutput().$process->getErrorOutput();

                // "X% packet loss" — the authoritative loss figure from ping itself.
                $loss = preg_match('/([\d.]+)\s*%\s*packet loss/i', $out, $m) ? (int) round((float) $m[1]) : null;

                // Total failure (host unresolvable, network unreachable) → hard down.
                if ($loss === null) {
                    return $process->isSuccessful() ? ['loss' => 0, 'rtt' => 0.0] : null;
                }

                // Best round-trip time from any reply ("time=12.3 ms").
                $rtt = preg_match('/time[=<]\s*([\d.]+)\s*ms/i', $out, $t) ? (float) $t[1] : null;

                return ['loss' => $loss, 'rtt' => $loss < 100 ? ($rtt ?? 0.0) : null];
            },
            fn (Device $edge, string $wan, string $target): ?float => $this->sdwanPing($edge, $wan, $target),
        );

        $this->info('Circuit monitor started, checking every 60 seconds.');

        $this->pollForever('circuits', 60, fn () => $monitor->checkAll());

        return self::SUCCESS;
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
