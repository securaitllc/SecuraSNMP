<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Break-glass: clear a user's two-factor enrolment (lost phone / lockout). They
 * are forced to re-enrol on next sign-in when MFA is enforced.
 */
class TwoFactorReset extends Command
{
    protected $signature = '2fa:reset {email}';

    protected $description = "Clear a user's two-factor enrolment so they can set it up again.";

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();
        if (! $user) {
            $this->error("No user with email {$this->argument('email')}.");

            return self::FAILURE;
        }

        $user->update([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ]);

        $this->info("Two-factor reset for {$user->email}. They will re-enrol at next sign-in.");

        return self::SUCCESS;
    }
}
