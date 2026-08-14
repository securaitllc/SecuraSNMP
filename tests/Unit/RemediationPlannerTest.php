<?php

namespace Tests\Unit;

use App\Support\RemediationPlanner;
use PHPUnit\Framework\TestCase;

class RemediationPlannerTest extends TestCase
{
    public function test_juniper_before_constraint_yields_the_fixed_release(): void
    {
        $plan = RemediationPlanner::plan('juniper', '20.4R3-S2.6', ['20.4 before 20.4R3-S9']);
        $this->assertSame('20.4R3-S9', $plan['target']);
        $this->assertFalse($plan['eol']);
    }

    public function test_it_picks_the_highest_fix_so_one_upgrade_clears_all(): void
    {
        $plan = RemediationPlanner::plan('juniper', '20.4R3-S2.6', ['20.4 before 20.4R3-S4', '20.4 before 20.4R3-S9']);
        $this->assertSame('20.4R3-S9', $plan['target']);
    }

    public function test_fortigate_range_upper_bound_is_the_target(): void
    {
        $plan = RemediationPlanner::plan('fortigate', '7.2.10', ['≥ 7.2.0 < 7.2.12', '≥ 7.0.0 < 7.4.10']);
        $this->assertSame('7.4.10', $plan['target']); // highest upper bound across findings
        $this->assertFalse($plan['eol']);
    }

    public function test_eol_juniper_train_has_no_target_and_flags_eol(): void
    {
        $plan = RemediationPlanner::plan('juniper', '12.3R12.4', ['12.3 (end-of-life, affected)', '12.3 (affected, pre-fix)']);
        $this->assertNull($plan['target']);
        $this->assertTrue($plan['eol']);
    }

    public function test_old_train_is_eol_even_when_some_constraint_parses(): void
    {
        // 15.1X is out of support: even a parseable fix can't keep it on that train.
        $plan = RemediationPlanner::plan('juniper', '15.1X53-D58.3', ['15.1 before 15.1X53-D60']);
        $this->assertTrue($plan['eol']);
    }
}
