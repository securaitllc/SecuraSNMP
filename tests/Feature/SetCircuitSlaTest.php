<?php

namespace Tests\Feature;

use App\Models\Circuit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SetCircuitSlaTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_dry_run_writes_nothing(): void
    {
        Circuit::factory()->count(3)->create(['sla_target_pct' => 99.5]);

        $this->artisan('circuits:set-sla', ['target' => '99.9'])->assertSuccessful();

        $this->assertSame([99.5, 99.5, 99.5], Circuit::pluck('sla_target_pct')->map(fn ($v) => (float) $v)->all());
    }

    public function test_apply_moves_every_circuit_including_the_unset_one(): void
    {
        // One circuit in the fleet has no target at all; "all" has to mean all.
        Circuit::factory()->count(2)->create(['sla_target_pct' => 99.5]);
        Circuit::factory()->create(['sla_target_pct' => null]);

        $this->artisan('circuits:set-sla', ['target' => '99.9', '--apply' => true])->assertSuccessful();

        $this->assertCount(3, Circuit::where('sla_target_pct', 99.9)->get());
    }

    public function test_it_can_be_limited_to_one_circuit_type(): void
    {
        Circuit::factory()->create(['circuit_type' => 'cable', 'sla_target_pct' => 99.5]);
        $fiber = Circuit::factory()->create(['circuit_type' => 'fiber', 'sla_target_pct' => 99.5]);

        $this->artisan('circuits:set-sla', ['target' => '99.9', '--apply' => true, '--type' => 'cable'])
            ->assertSuccessful();

        $this->assertSame(99.5, (float) $fiber->fresh()->sla_target_pct, 'a scoped run must not touch other types');
    }

    public function test_a_target_the_column_cannot_hold_is_refused(): void
    {
        // decimal(5,2) — MySQL would reject or truncate three decimals while SQLite
        // accepts it, which is exactly how this repo's dev/prod divergence bites.
        Circuit::factory()->create(['sla_target_pct' => 99.5]);

        $this->artisan('circuits:set-sla', ['target' => '99.999', '--apply' => true])->assertFailed();
        $this->artisan('circuits:set-sla', ['target' => '150', '--apply' => true])->assertFailed();

        $this->assertSame(99.5, (float) Circuit::first()->sla_target_pct);
    }

    public function test_running_it_twice_is_a_no_op_the_second_time(): void
    {
        Circuit::factory()->count(2)->create(['sla_target_pct' => 99.5]);

        $this->artisan('circuits:set-sla', ['target' => '99.9', '--apply' => true])->assertSuccessful();
        $this->artisan('circuits:set-sla', ['target' => '99.9', '--apply' => true])
            ->expectsOutputToContain('nothing to do')
            ->assertSuccessful();
    }
}
