<?php

namespace Tests\Feature;

use App\Models\Circuit;
use App\Models\Device;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MISC-10: bulk imports are one all-or-nothing transaction, so an unbounded row array
 * means one long lock and one pinned worker. Each import refuses more than 2000 rows.
 */
class ImportCapTest extends TestCase
{
    use RefreshDatabase;

    private const OVER = 2001;

    /** MISC-10: a site import over the cap is a 422 and writes nothing. */
    public function test_misc10_site_import_rejects_more_than_2000_rows(): void
    {
        $rows = array_map(fn ($i) => ['number' => (string) $i, 'name' => "Site $i"], range(1, self::OVER));

        $this->actingAs(User::factory()->admin()->create())->postJson('/api/sites/import', ['sites' => $rows])
            ->assertUnprocessable()->assertJsonValidationErrors('sites');
        $this->assertSame(0, Site::count());
    }

    /** MISC-10: a device import over the cap is a 422 and writes nothing. */
    public function test_misc10_device_import_rejects_more_than_2000_rows(): void
    {
        $rows = array_map(fn ($i) => ['name' => "SW$i", 'ip' => '10.'.intdiv($i, 256).'.'.($i % 256).'.1'], range(1, self::OVER));

        $this->actingAs(User::factory()->admin()->create())->postJson('/api/devices/import', [
            'devices' => $rows, 'role' => 'switch', 'vendor' => 'juniper',
        ])->assertUnprocessable()->assertJsonValidationErrors('devices');
        $this->assertSame(0, Device::count());
    }

    /** MISC-10: a circuit import over the cap is a 422 and writes nothing. */
    public function test_misc10_circuit_import_rejects_more_than_2000_rows(): void
    {
        $rows = array_map(fn ($i) => ['site' => "SC$i", 'ip' => '24.'.intdiv($i, 256).'.'.($i % 256).'.1', 'interface' => 'wan0'], range(1, self::OVER));

        $this->actingAs(User::factory()->admin()->create())->postJson('/api/circuits/import', ['circuits' => $rows])
            ->assertUnprocessable()->assertJsonValidationErrors('circuits');
        $this->assertSame(0, Circuit::count());
    }
}
