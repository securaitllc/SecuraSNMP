<?php

namespace Tests\Feature;

use App\Models\Circuit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CircuitContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_contract_status_buckets_by_days_to_expiry(): void
    {
        $mk = fn ($date) => new Circuit(['contract_end_date' => $date]);

        $this->assertSame('none', $mk(null)->contractStatus());
        $this->assertSame('expired', $mk(now()->subDay())->contractStatus());
        $this->assertSame('warning', $mk(now()->addDays(20))->contractStatus());
        $this->assertSame('notice', $mk(now()->addDays(50))->contractStatus());
        $this->assertSame('ok', $mk(now()->addDays(200))->contractStatus());
    }

    public function test_expiring_within_scope(): void
    {
        Circuit::factory()->create(['contract_end_date' => now()->addDays(45)]);
        Circuit::factory()->create(['contract_end_date' => now()->addDays(200)]);
        Circuit::factory()->create(['contract_end_date' => null]);

        $this->assertSame(1, Circuit::expiringWithin(60)->count());
    }

    public function test_renew_with_explicit_date_records_the_trail(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $circuit = Circuit::factory()->create(['contract_end_date' => '2026-09-01']);

        $this->actingAs($admin)->postJson("/api/circuits/{$circuit->id}/renew", [
            'new_end_date' => '2027-09-01', 'note' => 'Renewed 12mo @ same MRC',
        ])->assertOk();

        $circuit->refresh();
        $this->assertSame('2027-09-01', $circuit->contract_end_date->toDateString());
        $renewal = $circuit->renewals()->first();
        $this->assertSame('2026-09-01', $renewal->previous_end_date->toDateString());
        $this->assertSame('2027-09-01', $renewal->new_end_date->toDateString());
        $this->assertSame($admin->id, $renewal->renewed_by);
    }

    public function test_renew_with_term_computes_off_the_current_end(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $circuit = Circuit::factory()->create(['contract_end_date' => Carbon::parse('2026-09-01')]);

        $this->actingAs($admin)->postJson("/api/circuits/{$circuit->id}/renew", ['term_months' => 24])->assertOk();

        $this->assertSame('2028-09-01', $circuit->refresh()->contract_end_date->toDateString());
        $this->assertSame(24, $circuit->contract_term_months);
    }

    public function test_renew_needs_a_date_or_a_term(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $circuit = Circuit::factory()->create();

        $this->actingAs($admin)->postJson("/api/circuits/{$circuit->id}/renew", ['note' => 'x'])->assertStatus(422);
    }

    public function test_renew_is_admin_only(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);
        $circuit = Circuit::factory()->create();

        $this->actingAs($analyst)->postJson("/api/circuits/{$circuit->id}/renew", ['term_months' => 12])->assertForbidden();
    }
}
