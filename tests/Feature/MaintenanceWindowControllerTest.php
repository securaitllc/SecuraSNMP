<?php

namespace Tests\Feature;

use App\Models\MaintenanceWindow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaintenanceWindowControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_a_maintenance_window(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->postJson('/api/maintenance-windows', [
            'name' => 'Core upgrade',
            'starts_at' => now()->toISOString(),
            'ends_at' => now()->addHours(2)->toISOString(),
            'reason' => 'Firmware',
        ])->assertCreated();

        $this->assertDatabaseHas('maintenance_windows', ['name' => 'Core upgrade']);
    }

    public function test_end_must_be_after_start(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->postJson('/api/maintenance-windows', [
            'name' => 'bad',
            'starts_at' => now()->toISOString(),
            'ends_at' => now()->subHour()->toISOString(),
        ])->assertStatus(422);
    }

    public function test_viewer_cannot_create_a_window(): void
    {
        $viewer = User::factory()->create();

        $this->actingAs($viewer)->postJson('/api/maintenance-windows', ['name' => 'x'])->assertForbidden();
    }

    public function test_suppresses_matches_global_and_scoped_windows(): void
    {
        MaintenanceWindow::factory()->create(); // global active
        $this->assertTrue(MaintenanceWindow::suppresses(999, 999));

        MaintenanceWindow::query()->delete();
        $this->assertFalse(MaintenanceWindow::suppresses(1, 1));
    }
}
