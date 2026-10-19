<?php

namespace App\Services\Vuln;

/**
 * Does a platform-restricted CVE apply to this device's hardware?
 *
 * NVD states a hardware restriction as a configuration with `"operator": "AND"`: one
 * node holds the vulnerable OS CPE, a sibling node holds the hardware CPEs it runs on.
 * The feed parser used to flatten configurations → nodes → cpeMatch and discard that
 * structure, so the restriction vanished and an SRX-firewall CVE landed on every EX
 * access switch. 375 of 792 JunOS CVEs (47%) carry such a gate, which across Massey's
 * 168 Juniper devices was 4,911 findings for hardware the estate does not own.
 *
 * Matching is at FAMILY level — `srx`, `mx`, `ex`, `qfx` — not model level. NVD cites
 * hardware at wildly inconsistent granularity (`ex2200`, `ex2200-c`, `ex2200\/vc`,
 * `ex2300-48mp`), and a stricter comparison buys a little precision at the cost of
 * silently hiding a real CVE because two spellings of the same switch did not line up.
 * In this direction the errors are not symmetric: a false positive is noise in a queue,
 * a false negative is an unpatched box nobody is looking at.
 *
 * The same asymmetry governs the unknown case. A model whose family is not a Juniper
 * platform we recognise — `JNP48Y8C-CHAS`, the EX4650 chassis, is a real example from
 * the Massey fleet — is never filtered out. We suppress only when we can positively
 * say the device belongs to a different, known family.
 */
class PlatformMatcher
{
    /**
     * Hardware families we are willing to reason about.
     *
     * Read off the live NVD catalog: these are the families that actually appear in
     * AND-gated JunOS configurations. A family missing from this list is one we have
     * never seen gate a CVE, so a device in it must not be filtered on that basis.
     */
    private const KNOWN = [
        'srx', 'mx', 'ex', 'qfx', 'ptx', 'nfx', 'acx', 'csrx', 'vsrx', 'vmx',
        'mpc', 'lc', 't', 'qfk', 'fgt', 'fortigate', 'ec', 'vx',
    ];

    /**
     * The family token of a model string, or null when there isn't one.
     *
     * `EX2300-48P` → ex · `ex2200-c-12p-2g` → ex · `JNP48Y8C-CHAS` → jnp
     */
    public static function familyOf(?string $model): ?string
    {
        if (! $model) {
            return null;
        }

        return preg_match('/^[a-z]+/i', trim($model), $m) ? strtolower($m[0]) : null;
    }

    /** The family token of an NVD hardware CPE product segment (`ex2200\/vc` → ex). */
    public static function familyOfCpeProduct(string $product): ?string
    {
        $product = str_replace('\\', '', strtolower($product));

        return preg_match('/^[a-z]+/', $product, $m) ? $m[0] : null;
    }

    /**
     * Does a range restricted to $platforms apply to a device of model $model?
     *
     * @param  array<int, string>|null  $platforms  family tokens, or null for "any platform"
     */
    public static function applies(?array $platforms, ?string $model): bool
    {
        if ($platforms === null || $platforms === []) {
            return true; // platform-independent
        }

        $family = self::familyOf($model);

        // No model, or a family we have never seen gate a CVE: we cannot rule the device
        // out, so we do not. Better a finding to dismiss than a box nobody looks at.
        if ($family === null || ! in_array($family, self::KNOWN, true)) {
            return true;
        }

        return in_array($family, $platforms, true);
    }
}
