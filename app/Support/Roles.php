<?php

namespace App\Support;

/**
 * The role hierarchy, in one place.
 *
 * It used to live only in EnsureUserHasRole, which meant the gate knew the ranking
 * but the user editor did not — and an admin could hand themselves `super_admin`
 * through POST /api/users, walking up a rank the middleware was busy enforcing
 * everywhere else. A privilege ladder that only the doorman knows about is not a
 * ladder; anyone who can write the role column can skip it.
 */
final class Roles
{
    /**
     * Rank order. A higher rank satisfies every requirement below it.
     *
     * display (wall-TV kiosk) sits below viewer — it satisfies nothing and is further
     * fenced to the wallboard by RestrictDisplayRole. super_admin sits above admin and
     * gates the OSINT tool; existing admins do NOT inherit it.
     *
     * @var array<string, int>
     */
    public const RANK = ['display' => 0, 'viewer' => 1, 'analyst' => 2, 'admin' => 3, 'super_admin' => 4];

    /** Rank of a role name; an unknown role ranks lowest so it can never satisfy a gate. */
    public static function rank(?string $role): int
    {
        return self::RANK[$role] ?? 0;
    }

    /**
     * The roles a holder of $role may hand out.
     *
     * You may grant your own rank and below, never above. This is what stops the
     * self-escalation: an admin can mint admins, not super-admins.
     *
     * @return array<int, string>
     */
    public static function assignableBy(?string $role): array
    {
        $ceiling = self::rank($role);

        return array_keys(array_filter(self::RANK, fn (int $rank) => $rank <= $ceiling));
    }
}
