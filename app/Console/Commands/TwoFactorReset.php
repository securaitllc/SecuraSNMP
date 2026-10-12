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
    protected $signature = '2fa:reset {email} {--disable : Also turn off the MFA requirement so the account can sign in with just a password}';

    protected $description = "Clear a user's two-factor enrolment so they can set it up again (break-glass for a lost authenticator).";

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();
        if (! $user) {
            $this->error("No user with email {$this->argument('email')}.");

            return self::FAILURE;
        }

        $updates = [
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_ts' => null,
        ];

        // --disable is the strongest escape: the account becomes password-only
        // immediately (no forced re-enrol), for when someone must get in NOW.
        if ($this->option('disable')) {
            $updates['mfa_required'] = false;
        }

        $user->update($updates);

        $this->info($this->option('disable')
            ? "Two-factor reset AND requirement disabled for {$user->email}. They can sign in with just a password."
            : "Two-factor reset for {$user->email}. They will re-enrol at next sign-in.");

        return self::SUCCESS;
    }
}
