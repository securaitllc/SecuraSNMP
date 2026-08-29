<?php

namespace Tests\Unit;

use App\Models\Circuit;
use App\Models\Site;
use App\Services\LecResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The output of this class sends somebody to phone a carrier about a modem swap, so
 * the property under test is not "does it usually guess right" — it is "does it ever
 * answer confidently when it should not".
 */
class LecResolverTest extends TestCase
{
    use RefreshDatabase;

    private function circuit(?string $monitored = '71.43.240.89', ?string $gateway = null): Circuit
    {
        return Circuit::factory()->create([
            'site_id' => Site::factory()->create()->id,
            'monitored_ip' => $monitored,
            'gateway_ip' => $gateway,
        ]);
    }

    private function resolver(array $rdap, ?string $ptr, ?string $asn): LecResolver
    {
        return new LecResolver(fn () => $rdap, fn () => $ptr, fn () => $asn);
    }

    public function test_three_agreeing_signals_give_a_high_confidence_carrier(): void
    {
        // The common case: registry, BGP and rDNS all say Charter/Spectrum.
        $r = $this->resolver(
            ['org' => 'Charter Communications Inc', 'net_name' => 'RCSW'],
            'syn-071-043-240-089.biz.spectrum.com',
            'BHN-33363 - Charter Communications, Inc, US',
        )->resolve($this->circuit());

        $this->assertSame('Spectrum', $r['lec']);
        $this->assertSame('high', $r['confidence']);
    }

    public function test_an_aggregator_owned_ip_is_reported_as_masked_not_as_lumen(): void
    {
        // THE important case. Lumen bills the circuit and here also owns the address
        // space, which HIDES whose coax it is. Answering "Lumen" would send Reynaldo
        // back to the aggregator he is trying to cut out; the honest answer is that
        // the IP cannot tell us.
        $r = $this->resolver(
            ['org' => 'Level 3 Parent, LLC', 'net_name' => 'LVLT-ORG-4-8'],
            'ae2-306.edge4.Atlanta2.Level3.net',
            'LEVEL3 - Level 3 Parent, LLC, US',
        )->resolve($this->circuit());

        $this->assertNull($r['lec'], 'an aggregator IP must never be reported as the last-mile carrier');
        $this->assertSame('masked', $r['confidence']);
    }

    public function test_conflicting_signals_are_flagged_for_review_not_majority_voted(): void
    {
        // Two different carriers named by two sources is a real conflict. Picking a
        // winner here would be the one way this produces a confidently wrong phone call.
        $r = $this->resolver(
            ['org' => 'Comcast Cable Communications, LLC', 'net_name' => 'ATLANTA-CBC-20'],
            'wsip-70-171-147-209.ga.at.cox.net',
            null,
        )->resolve($this->circuit());

        $this->assertSame('verify', $r['confidence']);
    }

    public function test_a_single_signal_is_medium_confidence(): void
    {
        // Only rDNS answered. Usable, but not something to write without review.
        $r = $this->resolver([], '50-73-68-254-static.hfc.comcastbusiness.net', null)->resolve($this->circuit());

        $this->assertSame('Comcast', $r['lec']);
        $this->assertSame('medium', $r['confidence']);
    }

    public function test_a_lumen_signal_does_not_dilute_a_real_carrier(): void
    {
        // Transit shows up as Level3 on the AS while the block itself is Charter's.
        // Dropping the masking signal must leave the real carrier at full strength.
        $r = $this->resolver(
            ['org' => 'Charter Communications LLC', 'net_name' => 'CC04'],
            'syn-131-148-004-069.biz.spectrum.com',
            'LEVEL3 - Level 3 Parent, LLC, US',
        )->resolve($this->circuit());

        $this->assertSame('Spectrum', $r['lec']);
        $this->assertSame('high', $r['confidence']);
    }

    public function test_a_private_ip_yields_no_evidence(): void
    {
        // An RFC1918 address belongs to the site, not to any carrier.
        $r = $this->resolver(['org' => 'Charter'], null, null)->resolve($this->circuit('10.200.86.254'));

        $this->assertNull($r['lec']);
        $this->assertSame('no-evidence', $r['confidence']);
        $this->assertNull($r['ip']);
    }

    public function test_the_gateway_is_used_when_the_monitored_ip_is_private(): void
    {
        $r = $this->resolver(
            ['org' => 'Cox Communications Inc.', 'net_name' => 'NETBLK-COX-ATLANTA-10'],
            'wsip-70-171-147-209.ga.at.cox.net',
            null,
        )->resolve($this->circuit('192.168.1.1', '70.171.147.209'));

        $this->assertSame('70.171.147.209', $r['ip']);
        $this->assertSame('COX', $r['lec']);
    }

    public function test_an_unrecognised_carrier_is_left_unnamed_rather_than_guessed(): void
    {
        $r = $this->resolver(['org' => 'Some Regional Telco LLC', 'net_name' => 'SRT-1'], null, null)
            ->resolve($this->circuit());

        $this->assertNull($r['lec']);
        $this->assertSame('no-evidence', $r['confidence']);
    }

    public function test_the_billed_isp_corroborates_a_lone_technical_signal(): void
    {
        // Only the BGP AS answered — one signal is normally just "medium". But the
        // circuit is invoiced by Comcast too, and an independently-recorded biller
        // agreeing with the wire is real corroboration, so it becomes writable.
        $c = $this->circuit();
        $c->ispProvider()->associate(\App\Models\IspProvider::factory()->create(['name' => 'Comcast']))->save();

        $r = $this->resolver([], null, 'COMCAST-7922 - Comcast Cable Communications, LLC, US')->resolve($c->fresh());

        $this->assertSame('Comcast', $r['lec']);
        $this->assertSame('high', $r['confidence']);
    }

    public function test_the_billed_isp_never_answers_on_its_own(): void
    {
        // With no technical signal at all, echoing the invoice back would just restate
        // the record we are trying to verify — and prove nothing about the coax.
        $c = $this->circuit();
        $c->ispProvider()->associate(\App\Models\IspProvider::factory()->create(['name' => 'Comcast']))->save();

        $r = $this->resolver([], null, null)->resolve($c->fresh());

        $this->assertNull($r['lec']);
        $this->assertSame('no-evidence', $r['confidence']);
    }

    public function test_the_wire_disagreeing_with_the_invoice_is_flagged_not_decided(): void
    {
        // The real #DSLTL18-23702254 shape: invoiced as one carrier, every packet says
        // another. Picking a side automatically is how a wrong phone call gets made.
        $c = $this->circuit();
        $c->ispProvider()->associate(\App\Models\IspProvider::factory()->create(['name' => 'Spectrum']))->save();

        $r = $this->resolver([], null, 'ASN-CXA-ALL-CCI-22773-RDC - Cox Communications Inc., US')->resolve($c->fresh());

        $this->assertSame('verify', $r['confidence']);
    }

    public function test_being_billed_by_the_aggregator_does_not_corroborate_anything(): void
    {
        // Lumen is the biller on the very circuits whose last mile we cannot see from
        // the invoice. It must be dropped, leaving the lone signal at medium.
        $c = $this->circuit();
        $c->ispProvider()->associate(\App\Models\IspProvider::factory()->create(['name' => 'Lumen']))->save();

        $r = $this->resolver([], null, 'COMCAST-7922 - Comcast Cable Communications, LLC, US')->resolve($c->fresh());

        $this->assertSame('Comcast', $r['lec']);
        $this->assertSame('medium', $r['confidence']);
    }
}
