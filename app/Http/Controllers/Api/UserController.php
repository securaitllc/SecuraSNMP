<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserRequest;
use App\Models\User;
use App\Support\Roles;
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

        // Setting a password or dropping the MFA requirement (which also wipes the
        // enrollment below) is an account takeover when aimed at a peer (ACL-01), so
        // those need a strictly higher rank. Resending the unchanged flag is not a change.
        $stripsMfa = array_key_exists('mfa_required', $data) && ! $data['mfa_required']
            && ($user->mfa_required || $user->hasTwoFactorEnabled());
        if (! empty($data['password']) || $stripsMfa) {
            $this->assertStrictlyOutranks($user, $request);
        }

        // A new password or a deactivation is how an admin locks out a compromised
        // account, so it must also end the sessions and remember-me cookie already in
        // hand — or reactivating the account later would hand them back (AUTH-01/02).
        $revoke = ! empty($data['password']) || ($user->is_active && ! $data['is_active']);

        if (empty($data['password'])) {
            unset($data['password']);
        } else {
            $data['password'] = Hash::make($data['password']);
        }

        $user->update($data);

        if ($revoke) {
            $user->revokeSessions();
        }

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
        $this->assertOutranks($user, $request);

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
    public function resetTwoFactor(User $user, Request $request): JsonResponse
    {
        $this->assertStrictlyOutranks($user, $request);

        $user->update([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_ts' => null,
        ]);

        // Whoever lost the authenticator may also have lost the device it was on.
        $user->revokeSessions();

        return response()->json($this->publicUser($user->fresh()));
    }

    /**
     * You may not act on an account that outranks you — the same fence UserRequest
     * puts on create/update, applied to the two routes that carry no request body.
     * Deleting or unenrolling the super-admin is how an ordinary admin session would
     * knock out the one account that gates OSINT.
     */
    private function assertOutranks(User $target, Request $request): void
    {
        if (Roles::rank($target->role) > Roles::rank($request->user()?->role)) {
            abort(403, 'You do not have permission to act on this account.');
        }
    }

    /**
     * Credentials and MFA need a strictly higher rank (ACL-01): one compromised admin
     * session must not be able to reset a peer admin's password or strip their MFA
     * and take over that identity. Your own password goes through the self-service
     * route, which asks for the current one. super_admin over super_admin is allowed
     * on purpose: there is no higher rank, so otherwise nobody could recover one.
     */
    private function assertStrictlyOutranks(User $target, Request $request): void
    {
        $actor = Roles::rank($request->user()?->role);
        $peerSuperAdmins = $actor === Roles::rank('super_admin') && Roles::rank($target->role) === $actor;

        if (! $peerSuperAdmins && Roles::rank($target->role) >= $actor) {
            abort(403, 'Only a higher-ranked user can change this account\'s password or two-factor settings.');
        }
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
