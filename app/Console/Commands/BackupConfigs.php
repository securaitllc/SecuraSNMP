<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Services\ConfigBackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class BackupConfigs extends Command
{
    protected $signature = 'config:backup';

    protected $description = 'Periodically captures and versions each SSH-credentialed device config, alerting on drift.';

    public function handle(): int
    {
        $service = ConfigBackupService::forProduction();

        $this->info('Config backup started, capturing hourly.');

        while (true) {
            Device::where(function ($q) {
                $q->whereNotNull('ssh_credential_id')
                    ->orWhere(fn ($i) => $i->whereNotNull('ssh_username')->whereNotNull('ssh_credential'));
            })->each(function (Device $device) use ($service): void {
                try {
                    $service->backup($device);
                } catch (Throwable $e) {
                    Log::error("Config backup failed for device {$device->id}: {$e->getMessage()}");
                }
            });

            sleep(3600);
        }
    }
}
