<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_active',
        'avatar',
        'mfa_required',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
        'two_factor_last_ts',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'mfa_required' => 'boolean',
            // Secret + recovery codes are encrypted at rest.
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /** Top tier — gates the super-admin-only OSINT tool. Existing admins are NOT super-admins. */
    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    /** Two-factor is only "on" once the user has proven a code (confirmed). */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }

    /** Whether this account must use two-factor: per-user opt-in, or the global master. */
    public function mustUseTwoFactor(): bool
    {
        // Wall-display kiosk accounts never do MFA — they log in once on a TV and
        // stay authenticated via remember-me; a TOTP prompt would defeat that.
        if ($this->role === 'display') {
            return false;
        }

        return (bool) $this->mfa_required || (bool) config('mfa.enforced');
    }
}
