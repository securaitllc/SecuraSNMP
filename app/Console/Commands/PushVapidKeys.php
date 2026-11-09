<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

class PushVapidKeys extends Command
{
    protected $signature = 'push:vapid-keys';

    protected $description = 'Generate a VAPID key pair for Web Push and print the env lines to set.';

    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();

        $this->line('Add these to the environment (Portainer stack env on prod), then restart:');
        $this->newLine();
        $this->line('VAPID_SUBJECT=mailto:noc@your-domain.example');
        $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);
        $this->newLine();
        $this->warn('The private key is a secret: never commit it, never paste it in chat or tickets.');

        return self::SUCCESS;
    }
}
