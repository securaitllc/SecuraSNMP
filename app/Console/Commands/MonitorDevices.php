<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsPollLoop;
use App\Services\DeviceMonitor;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class MonitorDevices extends Command
{
    use RunsPollLoop;

    protected $signature = 'devices:monitor';

    protected $description = "Continuously ICMP-pings each active device and records its response time.";

    public function handle(): int
    {
        $monitor = new DeviceMonitor(function (string $ip): ?float {
            $process = new Process(['ping', '-c', '1', '-W', '2', $ip]);
            $process->run();

            if (! $process->isSuccessful()) {
                return null;
            }

            if (preg_match('/time[=<]\s*([\d.]+)\s*ms/i', $process->getOutput(), $matches)) {
                return (float) $matches[1];
            }

            return 0.0;
        });

        $this->info('Device monitor started, pinging every 60 seconds.');

        $this->pollForever('devices', 60, fn () => $monitor->checkAll());
    }
}
