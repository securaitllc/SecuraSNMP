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

    public function test_a_relocated_site_is_matched_on_its_number_not_its_address(): void
    {
        // #085 cut over at 2504 S Alafaya Trl in March 2024 and has since moved to
        // Oviedo. The system holds the CURRENT address, so the address differing from
        // the 2024 carrier list is the move itself — the service-centre number is the
        // stable key and the date still belongs to this site.
        $site = Site::factory()->create(['site_number' => '085']);
        $dia = Circuit::factory()->create(['site_id' => $site->id, 'circuit_type' => 'fiber', 'install_date' => null]);

        $this->artisan('circuits:import-dia-cutover', ['--apply' => true])->assertSuccessful();

        $this->assertSame('2024-03-26', $dia->fresh()->install_date?->toDateString());
    }

    public function test_the_disputed_knoxville_site_is_left_alone(): void
    {
        // SC 189's own row carries no date; the only date offered for it comes from a
        // renumber note pointing at another site's Florida address. Importing that
        // would put a cutover date on the wrong building.
        $site = Site::factory()->create(['site_number' => '189']);
        $dia = Circuit::factory()->create(['site_id' => $site->id, 'circuit_type' => 'fiber', 'install_date' => null]);

        $this->artisan('circuits:import-dia-cutover', ['--apply' => true])->assertSuccessful();

        $this->assertNull($dia->fresh()->install_date, 'SC 189 must stay blank until a human settles it');
    }

    public function test_the_contract_expiry_is_derived_three_years_from_the_cutover(): void
    {
        // DIA contracts run three years from the day the transport was cut over, so a
        // 2024 cutover means a 2027 renewal — which is what the contract-expiry
        // reporting keys off.
        $site = Site::factory()->create(['site_number' => '008']);   // cutover 2024-04-02
        $dia = Circuit::factory()->create([
            'site_id' => $site->id, 'circuit_type' => 'fiber',
            'install_date' => null, 'contract_end_date' => null, 'contract_term_months' => null,
        ]);

        $this->artisan('circuits:import-dia-cutover', ['--apply' => true])->assertSuccessful();

        $c = $dia->fresh();
        $this->assertSame('2024-04-02', $c->install_date?->toDateString());
        $this->assertSame('2027-04-02', $c->contract_end_date?->toDateString());
        $this->assertSame(36, $c->contract_term_months);
    }

    public function test_a_contract_end_date_already_on_file_outranks_the_derived_one(): void
    {
        // A date somebody recorded from an actual contract is real information; a
        // computed one is an inference. The recorded value wins.
        $site = Site::factory()->create(['site_number' => '008']);
        $dia = Circuit::factory()->create([
            'site_id' => $site->id, 'circuit_type' => 'fiber',
            'install_date' => null, 'contract_end_date' => '2026-12-31',
        ]);

        $this->artisan('circuits:import-dia-cutover', ['--apply' => true])->assertSuccessful();

        $c = $dia->fresh();
        $this->assertSame('2024-04-02', $c->install_date?->toDateString(), 'the cutover still lands');
        $this->assertSame('2026-12-31', $c->contract_end_date?->toDateString(), 'the recorded expiry is kept');
    }

    public function test_the_term_length_can_be_overridden(): void
    {
        $site = Site::factory()->create(['site_number' => '008']);
        $dia = Circuit::factory()->create([
            'site_id' => $site->id, 'circuit_type' => 'fiber',
            'install_date' => null, 'contract_end_date' => null,
        ]);

        $this->artisan('circuits:import-dia-cutover', ['--apply' => true, '--term-months' => 60])
            ->assertSuccessful();

        $this->assertSame('2029-04-02', $dia->fresh()->contract_end_date?->toDateString());
    }
}
