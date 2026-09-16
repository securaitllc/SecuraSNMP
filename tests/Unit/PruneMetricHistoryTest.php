<?php

namespace Tests\Unit;

use App\Models\DeviceInterface;
use App\Models\InterfaceMetricHistory;
use App\Models\Tunnel;
use App\Models\TunnelMetricHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PruneMetricHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_prune_deletes_interface_history_older_than_30_days(): void
    {
        $interface = DeviceInterface::factory()->create();
        $old = InterfaceMetricHistory::create([
            'device_interface_id' => $interface->id, 'recorded_at' => now()->subDays(31), 'status' => 'up',
            'in_octets_delta' => 0, 'out_octets_delta' => 0, 'in_discards_delta' => 0, 'out_discards_delta' => 0,
        ]);
        $recent = InterfaceMetricHistory::create([
            'device_interface_id' => $interface->id, 'recorded_at' => now()->subDays(1), 'status' => 'up',
            'in_octets_delta' => 0, 'out_octets_delta' => 0, 'in_discards_delta' => 0, 'out_discards_delta' => 0,
        ]);

        $this->artisan('metrics:prune')->assertExitCode(0);

        $this->assertDatabaseMissing('interface_metric_history', ['id' => $old->id]);
        $this->assertDatabaseHas('interface_metric_history', ['id' => $recent->id]);
    }

    public function test_prune_deletes_tunnel_history_older_than_30_days(): void
    {
        $tunnel = Tunnel::factory()->create();
        $old = TunnelMetricHistory::create([
            'tunnel_id' => $tunnel->id, 'recorded_at' => now()->subDays(31), 'status' => 'up',
            'in_discards_delta' => 0, 'out_discards_delta' => 0,
        ]);
        $recent = TunnelMetricHistory::create([
            'tunnel_id' => $tunnel->id, 'recorded_at' => now()->subDays(1), 'status' => 'up',
            'in_discards_delta' => 0, 'out_discards_delta' => 0,
        ]);

        $this->artisan('metrics:prune')->assertExitCode(0);

        $this->assertDatabaseMissing('tunnel_metric_history', ['id' => $old->id]);
        $this->assertDatabaseHas('tunnel_metric_history', ['id' => $recent->id]);
    }
}
