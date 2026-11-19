<?php

namespace Tests\Feature;

use App\Models\Anomaly;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\InterfaceMetricHistory;
use App\Models\Site;
use App\Services\AnomalyDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Control-plane interfaces are not part of the forwarding path.
 *
 * On Juniper, bme0 is the internal bridge to the Routing Engine and lo0 is the
 * loopback the control plane uses to talk to itself; the RE also creates units of
 * its own (bme0.0, lo0.16385). Discards and errors there say nothing about the
 * network an operator can act on, so scanning them only fills the anomaly list with
 * findings nobody can fix.
 */
class AnomalyInternalInterfaceTest extends TestCase
{
    use RefreshDatabase;

    private function interfaceWithNoisyHistory(string $ifName): DeviceInterface
    {
        $device = Device::factory()->create(['site_id' => Site::factory()->create()->id]);
        $if = DeviceInterface::factory()->create([
            'device_id' => $device->id, 'if_name' => $ifName,
            'status' => 'up', 'speed_bps' => 1_000_000_000,
        ]);

        // A flat baseline, then a hard sustained spike — a shape that reliably trips
        // the detector on a real forwarding port.
        $t = now()->subDays(3);
        for ($i = 0; $i < 60; $i++) {
            InterfaceMetricHistory::create([
                'device_interface_id' => $if->id,
                'recorded_at' => $t->copy()->addMinutes($i * 5), 'status' => 'up',
                'in_octets_delta' => 1000, 'out_octets_delta' => 1000,
                'in_discards_delta' => 0, 'out_discards_delta' => 0,
                'in_errors_delta' => 0, 'out_errors_delta' => 0,
            ]);
        }
        for ($i = 60; $i < 70; $i++) {
            InterfaceMetricHistory::create([
                'device_interface_id' => $if->id,
                'recorded_at' => $t->copy()->addMinutes($i * 5), 'status' => 'up',
                'in_octets_delta' => 1000, 'out_octets_delta' => 1000,
                'in_discards_delta' => 5000, 'out_discards_delta' => 5000,
                'in_errors_delta' => 0, 'out_errors_delta' => 0,
            ]);
        }

        return $if;
    }

    public static function internalNames(): array
    {
        return [['bme0'], ['bme0.0'], ['lo0'], ['lo0.16385']];
    }

    /**
     * @dataProvider internalNames
     */
    public function test_an_internal_interface_raises_no_anomaly(string $ifName): void
    {
        $if = $this->interfaceWithNoisyHistory($ifName);

        (new AnomalyDetector)->scanInterface($if->id);

        $this->assertSame(0, Anomaly::where('entity_type', 'interface')->where('entity_id', $if->id)->count(), "{$ifName} forwards nothing — its counters are not an anomaly");
    }

    public function test_a_forwarding_port_with_the_same_history_still_does(): void
    {
        // The control: identical data on a real port must still be caught, or the
        // filter has simply switched detection off.
        $if = $this->interfaceWithNoisyHistory('ge-0/0/12');

        (new AnomalyDetector)->scanInterface($if->id);

        $this->assertGreaterThan(0, Anomaly::where('entity_type', 'interface')->where('entity_id', $if->id)->count());
    }

    public function test_an_anomaly_already_open_on_an_internal_port_is_retired(): void
    {
        // These have been collected for a while, so the existing findings have to
        // clear themselves rather than need a migration.
        $if = $this->interfaceWithNoisyHistory('bme0');
        Anomaly::create([
            'entity_type' => 'interface', 'entity_id' => $if->id, 'metric' => 'discards',
            'direction' => 'high', 'z_score' => 9.1, 'observed' => 5000, 'baseline' => 0,
            'detected_at' => now()->subHour(), 'last_seen_at' => now()->subMinutes(5),
        ]);

        (new AnomalyDetector)->scanInterface($if->id);

        $this->assertSame(0, Anomaly::open()->where('entity_id', $if->id)->count(), 'the stale finding clears on the next pass');
    }
}
