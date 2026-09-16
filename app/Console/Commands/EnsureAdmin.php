<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class EnsureAdmin extends Command
{
    protected $signature = 'app:ensure-admin';

    protected $description = 'Creates the initial admin user on first boot when no users exist.';

    public function handle(): int
    {
        if (User::query()->exists()) {
            return self::SUCCESS;
        }

        $email = env('ADMIN_EMAIL', 'admin@securasnmp.local');

        // Never ship a known default password. Use ADMIN_PASSWORD when the
        // operator set one; otherwise generate a strong random password and
        // print it once (retrievable from the container logs on first boot).
        $password = env('ADMIN_PASSWORD');
        $generated = $password === null || $password === '';
        if ($generated) {
            $password = Str::password(20);
        }

        User::create([
            'name' => 'Admin',
            'email' => $email,
            'password' => bcrypt($password),
            'role' => 'admin',
            'is_active' => true,
        ]);

        if ($generated) {
            $this->warn('==================================================================');
            $this->warn("Initial admin created: {$email}");
            $this->warn("Generated password:    {$password}");
            $this->warn('Save it now and change it after logging in — shown only once.');
            $this->warn('==================================================================');
        } else {
            $this->warn("Initial admin created: {$email}. Change the password after logging in.");
        }

        return self::SUCCESS;
    }
}
