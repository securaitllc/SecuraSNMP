<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/** A password-verified user presented a wrong second factor (login or enrolment). */
class TwoFactorFailed
{
    use Dispatchable;

    public function __construct(public User $user) {}
}
