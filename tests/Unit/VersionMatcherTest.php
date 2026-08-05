<?php

namespace Tests\Unit;

use App\Services\Vuln\VersionMatcher;
use PHPUnit\Framework\TestCase;

class VersionMatcherTest extends TestCase
{
    public function test_it_strips_forti_and_silverpeak_cruft_to_a_comparable_tuple(): void
    {
        $this->assertSame([7, 2, 10], VersionMatcher::tuple('fortigate', 'v7.2.10,build1706,240918 (GA.M)'));
        $this->assertSame([9, 3, 8, 1], VersionMatcher::tuple('silverpeak', '9.3.8.1_96913'));
        $this->assertSame([20, 4, 3, 2, 6], VersionMatcher::tuple('juniper', '20.4R3-S2.6'));
        $this->assertSame([15, 1, 53, 58, 3], VersionMatcher::tuple('juniper', '15.1X53-D58.3'));
    }

    public function test_fortios_range_is_inclusive_lower_exclusive_upper(): void
    {
        // 7.2.0–7.2.6 affected, fixed 7.2.7
        $this->assertTrue(VersionMatcher::inRange('fortigate', 'v7.2.4,build1396 (GA)', '7.2.0', '7.2.7'));
        // 7.2.10 is patched → not affected
        $this->assertFalse(VersionMatcher::inRange('fortigate', 'v7.2.10,build1706,240918 (GA.M)', '7.2.0', '7.2.7'));
    }

    public function test_a_device_never_matches_another_trains_range(): void
    {
        // The bug caught in verification: a 20.4 device must NOT match a 12.3-only
        // range (from 12.3, fixed 12.4).
        $this->assertFalse(VersionMatcher::inRange('juniper', '20.4R3-S2.6', '12.3', '12.4'));
        $this->assertFalse(VersionMatcher::inRange('juniper', '20.4R3-S2.6', '15.1', '15.2'));
        // ...but it does match its own train (fixed at 20.4R3-S9).
        $this->assertTrue(VersionMatcher::inRange('juniper', '20.4R3-S2.6', '20.4', '20.4R3-S9'));
        // ...and a device below the train floor does not match.
        $this->assertFalse(VersionMatcher::inRange('juniper', '20.4R3-S2.6', '21.2', '21.2R4'));
    }

    public function test_eol_train_is_bounded_to_that_train(): void
    {
        $this->assertTrue(VersionMatcher::inRange('juniper', '12.3R12.4', '12.3', '12.4'));
        $this->assertTrue(VersionMatcher::inRange('juniper', '15.1X53-D58.3', '15.1', '15.2'));
        $this->assertFalse(VersionMatcher::inRange('juniper', '12.3R12.4', '15.1', '15.2'));
    }

    public function test_canonical_junos_separates_service_releases_from_the_base(): void
    {
        // The false positive that verification caught: NVD enumerates bare "20.4R3"
        // (S0), which must NOT be the same release as the patched "20.4R3-S9" (S9).
        $this->assertSame([20, 4, 0, 3, 0], VersionMatcher::canonical('juniper', '20.4R3'));
        $this->assertSame([20, 4, 0, 3, 2], VersionMatcher::canonical('juniper', '20.4R3-S2.6')); // build .6 ignored
        $this->assertSame([20, 4, 0, 3, 9], VersionMatcher::canonical('juniper', '20.4R3-S9'));
        $this->assertSame([15, 1, 1, 53, 58], VersionMatcher::canonical('juniper', '15.1X53-D58.3'));
    }

    public function test_exact_match_is_build_insensitive_but_service_release_precise(): void
    {
        // A device on the enumerated release matches despite its build spin...
        $this->assertTrue(VersionMatcher::matchesExact('juniper', '20.4R3-S2.6', '20.4R3-S2'));
        // ...the patched service release does NOT match the enumerated base release...
        $this->assertFalse(VersionMatcher::matchesExact('juniper', '20.4R3-S9', '20.4R3'));
        // ...and a different service release does not match.
        $this->assertFalse(VersionMatcher::matchesExact('juniper', '20.4R3-S5', '20.4R3-S2'));
    }

    public function test_inclusive_and_exclusive_bounds_are_honoured(): void
    {
        // fixed inclusive → the boundary version is still affected (versionEndIncluding)
        $this->assertTrue(VersionMatcher::inRange('fortigate', 'v7.2.7', '7.2.0', '7.2.7', true, true));
        $this->assertFalse(VersionMatcher::inRange('fortigate', 'v7.2.7', '7.2.0', '7.2.7', true, false));
        // introduced exclusive → the boundary version is NOT yet affected
        $this->assertFalse(VersionMatcher::inRange('fortigate', 'v7.2.0', '7.2.0', '7.2.9', false, false));
    }

    public function test_an_unparseable_version_never_matches(): void
    {
        $this->assertFalse(VersionMatcher::inRange('juniper', 'unknown', '12.3', '12.4'));
        $this->assertFalse(VersionMatcher::inRange('juniper', '', null, null));
    }
}
