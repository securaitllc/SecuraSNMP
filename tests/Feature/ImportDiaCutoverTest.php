<?php

namespace Tests\Feature;

use App\Models\Circuit;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportDiaCutoverTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_stamps_the_fiber_circuit_at_a_listed_site(): void
    {
        $site = Site::factory()->create(['site_number' => '008']);
        $dia = Circuit::factory()->create(['site_id' => $site->id, 'circuit_type' => 'fiber', 'install_date' => null]);

        $this->artisan('circuits:import-dia-cutover', ['--apply' => true])->assertSuccessful();

        $this->assertSame('2024-04-02', $dia->fresh()->install_date?->toDateString());
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $site = Site::factory()->create(['site_number' => '008']);
        $dia = Circuit::factory()->create(['site_id' => $site->id, 'circuit_type' => 'fiber', 'install_date' => null]);

        $this->artisan('circuits:import-dia-cutover')->assertSuccessful();

        $this->assertNull($dia->fresh()->install_date);
    }

    public function test_it_does_not_touch_the_cable_circuit_at_the_same_site(): void
    {
        // The cutover was a DIA/transport event; the broadband at that address is
        // a different circuit with its own install history.
        $site = Site::factory()->create(['site_number' => '008']);
        $cable = Circuit::factory()->create(['site_id' => $site->id, 'circuit_type' => 'cable', 'install_date' => null]);

        $this->artisan('circuits:import-dia-cutover', ['--apply' => true])->assertSuccessful();

        $this->assertNull($cable->fresh()->install_date, 'only the DIA circuit carries the transport cutover date');
    }

    public function test_an_existing_install_date_is_kept_unless_forced(): void
    {
        $site = Site::factory()->create(['site_number' => '008']);
        $dia = Circuit::factory()->create(['site_id' => $site->id, 'circuit_type' => 'fiber', 'install_date' => '2019-01-15']);

        $this->artisan('circuits:import-dia-cutover', ['--apply' => true])->assertSuccessful();
        $this->assertSame('2019-01-15', $dia->fresh()->install_date?->toDateString());

        $this->artisan('circuits:import-dia-cutover', ['--apply' => true, '--force' => true])->assertSuccessful();
        $this->assertSame('2024-04-02', $dia->fresh()->install_date?->toDateString());
    }

    public function test_a_site_with_two_dias_gets_both_stamped(): void
    {
        // #887 Customer Care genuinely has two 100M DIAs — the source list says so,
        // and the correlation reproducing that is part of why it is trusted.
        $site = Site::factory()->create(['site_number' => '887']);
        $a = Circuit::factory()->create(['site_id' => $site->id, 'circuit_type' => 'fiber', 'install_date' => null]);
        $b = Circuit::factory()->create(['site_id' => $site->id, 'circuit_type' => 'fiber', 'install_date' => null]);

        $this->artisan('circuits:import-dia-cutover', ['--apply' => true])->assertSuccessful();

        $this->assertSame('2024-04-22', $a->fresh()->install_date?->toDateString());
        $this->assertSame('2024-04-22', $b->fresh()->install_date?->toDateString());
    }

    public function test_a_site_that_is_not_in_the_system_is_reported_not_fatal(): void
    {
        $this->artisan('circuits:import-dia-cutover', ['--apply' => true])
            ->expectsOutputToContain('not in this system')
            ->assertSuccessful();
    }
}
