<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\TwoFactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Self-service TOTP two-factor enrolment. Reachable even before enrolment (so a
 * user forced into setup can complete it), but never lets an already-confirmed
 * account silently re-key without proving the new secret.
 */
class TwoFactorController extends Controller
{
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'enabled' => $user->hasTwoFactorEnabled(),
            'enforced' => (bool) config('mfa.enforced'),
            'pending' => $user->two_factor_secret !== null && ! $user->hasTwoFactorEnabled(),
        ]);
    }

    /** Generate a secret + QR to scan. Not yet active until confirm() proves a code. */
    public function enroll(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasTwoFactorEnabled()) {
            return response()->json(['message' => 'Two-factor is already enabled.'], 409);
        }

        $secret = TwoFactor::generateSecret();
        $user->update(['two_factor_secret' => $secret, 'two_factor_recovery_codes' => null]);

        $otpauth = TwoFactor::otpauthUrl($user, $secret);

        return response()->json([
            'secret' => $secret,
            'otpauth_url' => $otpauth,
            'qr_svg' => TwoFactor::qrSvg($otpauth),
        ]);
    }

    /** Prove a code from the authenticator, activate 2FA, and hand back recovery codes. */
    public function confirm(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'string']]);
        $user = $request->user();

        if (! $user->two_factor_secret) {
            return response()->json(['message' => 'Start enrolment first.'], 422);
        }

        if (! TwoFactor::verify($user->two_factor_secret, $request->input('code'))) {
            throw ValidationException::withMessages(['code' => 'That code is not valid. Check your authenticator and try again.']);
        }

        $codes = TwoFactor::recoveryCodes((int) config('mfa.recovery_codes', 10));
        $user->update([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => $codes,
        ]);

        return response()->json(['recovery_codes' => $codes]);
    }

    /** Regenerate recovery codes — requires the account password (step-up). */
    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $request->validate(['password' => ['required', 'string']]);
        $user = $request->user();

        if (! $user->hasTwoFactorEnabled() || ! \Illuminate\Support\Facades\Hash::check($request->input('password'), $user->password)) {
            throw ValidationException::withMessages(['password' => 'Password is incorrect.']);
        }

        $codes = TwoFactor::recoveryCodes((int) config('mfa.recovery_codes', 10));
        $user->update(['two_factor_recovery_codes' => $codes]);

        return response()->json(['recovery_codes' => $codes]);
    }
}
