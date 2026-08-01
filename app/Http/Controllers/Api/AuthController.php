<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->input('email'))->first();

        if (! $user || ! $user->is_active || ! Hash::check($request->input('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records or the account is inactive.',
            ]);
        }

        // Two-factor challenge — only for enrolled users. Credentials were correct,
        // so signal the frontend to collect a code rather than a generic failure.
        if ($user->hasTwoFactorEnabled()) {
            $code = trim((string) $request->input('code'));
            if ($code === '') {
                return response()->json(['two_factor_required' => true]);
            }
            if (! $this->twoFactorPasses($user, $code)) {
                throw ValidationException::withMessages(['code' => 'That two-factor code is not valid.']);
            }
        }

        Auth::login($user);
        $request->session()->regenerate();

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
