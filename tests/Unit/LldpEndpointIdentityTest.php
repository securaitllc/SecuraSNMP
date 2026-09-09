<?php

namespace Tests\Unit;

use App\Models\Device;
use App\Models\LldpNeighbor;
use App\Services\LldpCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Endpoint identity extracted from what LLDP already advertises.
 *
 * The fixtures below are shaped from real walk output, including the detail that
 * matters most: chassis id means different things per subtype. A wireless AP
 * advertises six octets (a MAC); a handset advertises five — an address family byte
 * followed by an IPv4 address. Reading the second as a MAC loses the address, and
 * reading it by length alone is how that mistake happens.
 *
 * @fixture-source device=EX-series access switch, LLDP-MIB walk, captured 2026-07-27
 */
class LldpEndpointIdentityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A walker returning canned OID output. Keys are OID suffixes; the collector
     * asks for full OIDs so we match on the tail.
     *
     * @param  array<string, string>  $byOid
     */
    private function collector(array $byOid): LldpCollector
    {
        return new LldpCollector(function (Device $device, string $oid) use ($byOid): string {
            foreach ($byOid as $suffix => $output) {
                if (str_ends_with($oid, $suffix)) {
                    return $output;
                }
            }

            return '';
        });
    }

    /** Index shape is timeMark.localPortNum.remoteIndex. */
    private const IDX = '0.563.1';

    private function walkFixture(string $sysName, string $chassis, ?string $subtype): array
    {
        $rem = fn (string $leaf, string $val) => ".1.0.8802.1.1.2.1.4.1.1.{$leaf}.".self::IDX." = {$val}";

        return [
            '.1.0.8802.1.1.2.1.4.1.1.9' => $rem('9', "STRING: \"{$sysName}\""),
            '.1.0.8802.1.1.2.1.4.1.1.5' => $rem('5', "Hex-STRING: {$chassis}"),
            '.1.0.8802.1.1.2.1.4.1.1.4' => $subtype === null ? '' : $rem('4', "INTEGER: {$subtype}"),
            '.1.3.6.1.2.1.31.1.1.1.1' => '.1.3.6.1.2.1.31.1.1.1.1.563 = STRING: "ge-0/0/27"',
        ];
    }

    public function test_a_handset_yields_its_extension_model_and_address(): void
    {
        $device = Device::factory()->create();

        // Chassis id subtype 5: family byte 01 (IPv4) + 0A 00 02 36 = 10.0.2.54
        $this->collector($this->walkFixture('regDN 500206,MINET_6920', '01 0A 00 02 36', '5'))
            ->discover($device);

        $n = LldpNeighbor::where('device_id', $device->id)->firstOrFail();

        $this->assertSame('500206', $n->extension);
        $this->assertSame('Mitel 6920', $n->endpoint_model);
        $this->assertSame('10.0.2.54', $n->remote_mgmt_addr);
        // A handset advertises no MAC here — claiming one would be a fabrication.
        $this->assertNull($n->remote_mac);
    }

    public function test_an_access_point_yields_a_mac(): void
    {
        $device = Device::factory()->create();

        $this->collector($this->walkFixture('SITE-AP1', '02 00 5E 10 BE 13', '4'))
            ->discover($device);

        $n = LldpNeighbor::where('device_id', $device->id)->firstOrFail();

        $this->assertSame('02:00:5E:10:BE:13', $n->remote_mac);
        $this->assertNull($n->extension);
        $this->assertNull($n->endpoint_model);
    }

    public function test_subtype_is_inferred_from_shape_when_the_agent_omits_it(): void
    {
        $device = Device::factory()->create();

        // Same AP, no subtype OID returned at all.
        $this->collector($this->walkFixture('SITE-AP2', '02 00 5E 10 BE FE', null))
            ->discover($device);

        $this->assertSame(
            '02:00:5E:10:BE:FE',
            LldpNeighbor::where('device_id', $device->id)->value('remote_mac'),
        );
    }

    public function test_a_five_octet_id_is_never_mistaken_for_a_mac(): void
    {
        $device = Device::factory()->create();

        $this->collector($this->walkFixture('regDN 500100,MINET_6930', '01 0A 00 02 3B', null))
            ->discover($device);

        $n = LldpNeighbor::where('device_id', $device->id)->firstOrFail();

        // This is the regression that matters: length-based decoding would produce a
        // truncated MAC here and silently drop the address.
        $this->assertNull($n->remote_mac);
        $this->assertSame('10.0.2.59', $n->remote_mgmt_addr);
    }

    public function test_a_handset_without_a_model_still_yields_its_extension(): void
    {
        $device = Device::factory()->create();

        $this->collector($this->walkFixture('regDN 500200', '01 0A 00 02 3D', '5'))
            ->discover($device);

        $n = LldpNeighbor::where('device_id', $device->id)->firstOrFail();

        $this->assertSame('500200', $n->extension);
        $this->assertNull($n->endpoint_model);
    }

    public function test_a_mib_resolved_subtype_is_understood(): void
    {
        $device = Device::factory()->create();

        // With MIBs loaded the agent renders "macAddress(4)" rather than "4".
        $this->collector($this->walkFixture('SITE-AP3', '02 00 5E 10 BE 20', 'macAddress(4)'))
            ->discover($device);

        $this->assertSame(
            '02:00:5E:10:BE:20',
            LldpNeighbor::where('device_id', $device->id)->value('remote_mac'),
        );
    }

    public function test_an_ordinary_switch_neighbour_gets_no_endpoint_identity(): void
    {
        $device = Device::factory()->create();

        $this->collector($this->walkFixture('CORE-SW01', '02 00 5E 2F 5F 6C', '4'))
            ->discover($device);

        $n = LldpNeighbor::where('device_id', $device->id)->firstOrFail();

        $this->assertSame('02:00:5E:2F:5F:6C', $n->remote_mac);
        $this->assertNull($n->extension);
    }

    public function test_a_junk_chassis_id_is_ignored_rather_than_guessed(): void
    {
        $device = Device::factory()->create();

        $this->collector($this->walkFixture('WEIRD-BOX', 'not-hex-at-all', null))
            ->discover($device);

        $n = LldpNeighbor::where('device_id', $device->id)->firstOrFail();

        $this->assertNull($n->remote_mac);
    }
}
