<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index(): JsonResponse
    {
        $users = User::orderBy('name')->get()->map(fn (User $user) => $this->publicUser($user))->values();

        return response()->json($users);
    }

    public function store(UserRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['password'] = Hash::make($data['password']);

        $user = User::create($data);

        return response()->json($this->publicUser($user), 201);
    }

    public function update(UserRequest $request, User $user): JsonResponse
    {
        $data = $request->validated();

        if ($user->id === $request->user()->id && $request->boolean('is_active') === false) {
            abort(422, 'You cannot deactivate your own account.');
        }

        if (empty($data['password'])) {
            unset($data['password']);
        } else {
            $data['password'] = Hash::make($data['password']);
        }

        $user->update($data);

        // Turning "require MFA" off clears any lingering enrollment (e.g. from an
        // earlier blanket-MFA period) so the account is truly password-only again.
        if ($user->mfa_required === false && $user->hasTwoFactorEnabled()) {
            $user->update([
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
                'two_factor_last_ts' => null,
            ]);
        }

        return response()->json($this->publicUser($user->fresh()));
    }

    public function destroy(User $user, Request $request): JsonResponse
    {
        if ($user->id === $request->user()->id) {
            abort(422, 'You cannot delete your own account.');
        }

        $user->delete();

        return response()->json(null, 204);
    }

    /**
     * Admin unenroll: wipe a user's two-factor enrollment so they can register a
     * fresh authenticator. The `mfa_required` flag is left as-is — if MFA is still
     * required the user is funnelled back into setup on their next login. This is
     * the escape hatch for "I lost my phone / authenticator" without disabling MFA.
     */
    public function resetTwoFactor(User $user): JsonResponse
    {
        $user->update([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_ts' => null,
        ]);

        return response()->json($this->publicUser($user->fresh()));
    }

    private function publicUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'is_active' => $user->is_active,
            'mfa_required' => (bool) $user->mfa_required,
            'two_factor_enabled' => $user->hasTwoFactorEnabled(),
        ];
    }
}
