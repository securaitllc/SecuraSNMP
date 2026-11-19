<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnforceMaxSessionAge;
use App\Http\Requests\LoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /** A verify-only bcrypt decoy, made once per process with the app's own hash
     *  config, so the unknown/inactive path spends the same time as a real check. */
    private static ?string $decoyHash = null;

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->input('email'))->first();

        // Always run one Hash::check, against the real hash or a decoy, so an
        // unknown or inactive account is not revealed by a faster response
        // (L-03: login timing enumeration). The active check comes after.
        $passwordOk = Hash::check(
            $request->input('password'),
            $user?->password ?? (self::$decoyHash ??= Hash::make(Str::random(40)))
        );

        if (! $user || ! $user->is_active || ! $passwordOk) {
            event(new \Illuminate\Auth\Events\Failed('web', $user, ['email' => $request->input('email')]));
            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records or the account is inactive.',
            ]);
        }

        // Two-factor challenge — only when the account both must use MFA and has
        // enrolled. So switching a user's "required" toggle off drops the prompt
        // even if a stale enrollment (from an earlier blanket-MFA period) lingers.
        if ($user->hasTwoFactorEnabled() && $user->mustUseTwoFactor()) {
            $code = trim((string) $request->input('code'));
            if ($code === '') {
                // The password already passed, so telling this person how many
                // recovery codes they have left gives nothing away to a stranger.
                return response()->json([
                    'two_factor_required' => true,
                    'recovery_codes_left' => count($user->two_factor_recovery_codes ?? []),
                ]);
            }
            if (! $this->twoFactorPasses($user, $code)) {
                event(new \App\Events\TwoFactorFailed($user));
                throw ValidationException::withMessages(['code' => 'That two-factor code is not valid.']);
            }
        }

        // Remember-me issues a long-lived cookie so the session re-establishes
        // itself after it expires — a wall-mounted TV logs in once and stays up.
        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        // The clock for EnforceMaxSessionAge. Written ONLY here, by a real
        // credential login — a session revived from a remember-me cookie must not
        // be able to reset it, or the limit means nothing.
        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        // The same clock, per session (AUTH-05): a login here must not extend a
        // session opened elsewhere. last_login_at stays for display and audit.
        EnforceMaxSessionAge::stamp($request, $user, now()->timestamp);

        // Pin the password fingerprint now (AUTH-06). AuthenticateSession only stores
        // it on the first request AFTER login, so a session left idle since login
        // would otherwise survive a password change.
        $request->session()->put('password_hash_web', Auth::guard('web')->hashPasswordForCookie($user->getAuthPassword()));

        return response()->json(['user' => $this->publicUser($user)]);
    }

    /** A valid current TOTP (anti-replay), or an unused recovery code (consumed). */
    private function twoFactorPasses(User $user, string $code): bool
    {
        if (\App\Support\TwoFactor::verifyForUser($user, $code)) {
            return true;
        }

        // Recovery codes are stored hashed — check against each, consume on match.
        $codes = $user->two_factor_recovery_codes ?? [];
        $target = strtoupper(trim($code));
        foreach ($codes as $i => $hash) {
            if (Hash::check($target, $hash)) {
                unset($codes[$i]);
                $user->update(['two_factor_recovery_codes' => array_values($codes)]);

                return true;
            }
        }

        return false;
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->publicUser($request->user()));
    }

    /** Self-service: the signed-in user changes their own password. */
    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()],
        ]);

        $user = $request->user();
        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Current password is incorrect.']);
        }

        $user->update(['password' => Hash::make($data['password'])]);

        // A password change must boot every OTHER session (L-05). Prod keeps sessions
        // in redis, so a sessions-table purge did nothing there (AUTH-06). Instead
        // logoutOtherDevices re-pins this session to the new password, and Sanctum's
        // AuthenticateSession ends every session still holding the old fingerprint,
        // on any driver. The remember-me token rotates first so a long-lived cookie on
        // another device can't silently revive, while this device's cookie is
        // re-queued with the new token. The current session is regenerated, not
        // dropped, so the operator who just changed it stays signed in here.
        $user->forceFill(['remember_token' => Str::random(60)])->saveQuietly();
        Auth::guard('web')->logoutOtherDevices($data['password']);
        $request->session()->regenerate();

        return response()->json(['message' => 'Password updated.']);
    }

    /** Self-service: set (or clear) the signed-in user's avatar. */
    public function updateAvatar(Request $request): JsonResponse
    {
        $data = $request->validate([
            // A small PNG/JPEG/WebP data: URI, capped so it stays a thumbnail and
            // never bloats the row (~300 KB of base64 ≈ a 256px image).
            'avatar' => ['present', 'nullable', 'string', 'max:400000', 'regex:#^data:image/(png|jpe?g|webp);base64,#'],
        ]);

        $request->user()->update(['avatar' => $data['avatar'] ?: null]);

        return response()->json($this->publicUser($request->user()->fresh()));
    }

    private function publicUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'avatar' => $user->avatar,
            'two_factor_enabled' => $user->hasTwoFactorEnabled(),
            'mfa_required' => $user->mustUseTwoFactor(),
        ];
    }
}
