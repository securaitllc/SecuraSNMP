<?php

namespace Tests\Unit;

use App\Models\Device;
use App\Services\TrapRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrapRecorderTest extends TestCase
{
    use RefreshDatabase;

    private string $sample = "core-sw01\nUDP: [10.10.1.1]:41234->[172.17.0.2]:162\n.1.3.6.1.6.3.1.1.4.1.0 .1.3.6.1.6.3.1.1.5.3\n.1.3.6.1.2.1.2.2.1.1.2 2";

    public function test_it_matches_the_trap_to_a_device_by_source_ip(): void
    {
        $device = Device::factory()->create(['ip_address' => '10.10.1.1']);

        $trap = (new TrapRecorder())->record($this->sample);

        $this->assertSame($device->id, $trap->device_id);
        $this->assertSame('10.10.1.1', $trap->source_ip);
        $this->assertSame('.1.3.6.1.6.3.1.1.5.3', $trap->trap_oid);
        $this->assertStringContainsString('.1.3.6.1.2.1.2.2.1.1.2 2', $trap->message);
    }

    public function test_a_trap_from_an_unknown_source_is_still_recorded(): void
    {
        $trap = (new TrapRecorder())->record($this->sample);

        $this->assertNull($trap->device_id);
        $this->assertSame('10.10.1.1', $trap->source_ip);
        $this->assertDatabaseCount('snmp_traps', 1);
    }
}
