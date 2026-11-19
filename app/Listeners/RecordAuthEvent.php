<?php

namespace App\Listeners;

use App\Events\TwoFactorFailed;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Events\Dispatcher;

/**
 * Writes authentication outcomes to audit_logs (LOG-01) so spraying, lockouts and
 * bad second factors leave a trail. Only the identity is kept — the password and
 * the TOTP/recovery code never reach this class's rows.
 *
 * Method names deliberately avoid the handle* prefix: this is registered as a
 * subscriber by AuditServiceProvider, and event discovery would otherwise bind
 * each method a second time.
 */
class RecordAuthEvent
{
    public function onLogin(Login $event): void
    {
        $this->record('auth.login', 200, $event->user, $event->user->email ?? null);
    }

    public function onFailed(Failed $event): void
    {
        $this->record('auth.failed', 422, $event->user, $event->credentials['email'] ?? null);
    }

    public function onLockout(Lockout $event): void
    {
        $this->record('auth.lockout', 429, null, $event->request->input('email'));
    }

    public function onLogout(Logout $event): void
    {
        $this->record('auth.logout', 200, $event->user, $event->user->email ?? null);
    }

    public function onTwoFactorFailed(TwoFactorFailed $event): void
    {
        $this->record('auth.mfa_failed', 422, $event->user, $event->user->email);
    }

    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'onLogin',
            Failed::class => 'onFailed',
            Lockout::class => 'onLockout',
            Logout::class => 'onLogout',
            TwoFactorFailed::class => 'onTwoFactorFailed',
        ];
    }

    private function record(string $action, int $status, mixed $user, mixed $email): void
    {
        $request = request();
        $user = $user instanceof User ? $user : null;

        AuditLog::create([
            'action' => $action,
            'user_id' => $user?->id,
            'user_name' => $user?->name,
            // Attacker-supplied on a failed attempt — bounded, and only ever a string.
            'email' => is_string($email) ? mb_substr($email, 0, 255) : null,
            'method' => $request->method(),
            'path' => mb_substr(ltrim($request->path(), '/'), 0, 255),
            'status' => $status,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 512) ?: null,
            'created_at' => now(),
        ]);
    }
}
