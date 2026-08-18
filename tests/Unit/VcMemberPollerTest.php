<?php

namespace Tests\Unit;

use App\Models\Device;
use App\Models\DeviceMember;
use App\Services\VcMemberPoller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VcMemberPollerTest extends TestCase
{
    use RefreshDatabase;

    /** A fake snmpwalk that answers the jnxVirtualChassisMemberTable columns from a map. */
    private function walker(array $membersByColumn): callable
    {
        // $membersByColumn = ['2' => [0 => 'SERIAL0', 1 => 'SERIAL1'], '3' => [...], ...]
        return function (Device $device, string $oid) use ($membersByColumn): string {
            $col = substr($oid, strrpos($oid, '.') + 1);
            $rows = $membersByColumn[$col] ?? [];
            $lines = [];
            foreach ($rows as $memberId => $value) {
                $type = is_int($value) || is_numeric($value) && $col !== '2' ? 'INTEGER' : 'STRING';
                $rendered = $type === 'INTEGER' ? (string) $value : '"'.$value.'"';
                $lines[] = ".1.3.6.1.4.1.2636.3.40.1.4.1.1.1.{$col}.{$memberId} = {$type}: {$rendered}";
            }

            return implode("\n", $lines);
        };
    }

    private function threeMemberVc(): array
    {
        return [
            '2' => [0 => 'AB1234567890', 1 => 'CD0987654321', 2 => 'EF1122334455'], // serial
            '3' => [0 => 1, 1 => 2, 2 => 3],                                          // role master(1)/backup(2)/linecard(3)
            '5' => [0 => '21.4R3-S2.6', 1 => '21.4R3-S2.6', 2 => '21.4R3-S2.6'],      // sw version
            '6' => [0 => 128, 1 => 128, 2 => 1],                                      // priority
            '8' => [0 => 'EX4300-48T', 1 => 'EX4300-48T', 2 => 'EX4300-48T'],         // model
        ];
    }

    public function test_it_captures_every_virtual_chassis_member(): void
    {
        $device = Device::factory()->create(['vendor' => 'juniper', 'role' => 'switch']);

        (new VcMemberPoller($this->walker($this->threeMemberVc())))->poll($device);

        $this->assertSame(3, $device->members()->count());
        $master = $device->members()->where('member_id', 0)->first();
        $this->assertSame('AB1234567890', $master->serial_number);
        $this->assertSame('master', $master->role);
        $this->assertSame('EX4300-48T', $master->model);
        $this->assertSame('21.4R3-S2.6', $master->sw_version);
        $this->assertSame('present', $master->status);
        $this->assertSame('backup', $device->members()->where('member_id', 1)->first()->role);
        $this->assertSame('linecard', $device->members()->where('member_id', 2)->first()->role);
    }

    public function test_it_backfills_device_serial_and_os_from_the_vc_master(): void
    {
        // EX4650 and similar don't populate ENTITY-MIB and override sysDescr, so the
        // identity poller finds no serial/OS — the VC master member is the source.
        $device = Device::factory()->create(['vendor' => 'juniper', 'role' => 'switch', 'serial_number' => null, 'os_version' => null]);

        (new VcMemberPoller($this->walker($this->threeMemberVc())))->poll($device);

        $device->refresh();
        $this->assertSame('AB1234567890', $device->serial_number);  // member 0 = master
        $this->assertSame('21.4R3-S2.6', $device->os_version);
    }

    public function test_a_member_that_drops_out_is_flagged_offline_not_deleted(): void
    {
        $device = Device::factory()->create(['vendor' => 'juniper', 'role' => 'switch']);
        (new VcMemberPoller($this->walker($this->threeMemberVc())))->poll($device);

        // Next poll: member 2 is gone from the table (its switch died).
        $reduced = $this->threeMemberVc();
        unset($reduced['2'][2], $reduced['3'][2], $reduced['5'][2], $reduced['6'][2], $reduced['8'][2]);
        (new VcMemberPoller($this->walker($reduced)))->poll($device);

        // Still three rows — the serial is the physical switch's, kept for RMA.
        $this->assertSame(3, $device->members()->count());
        $gone = $device->members()->where('member_id', 2)->first();
        $this->assertSame('missing', $gone->status);
        $this->assertNotNull($gone->absent_since);
        $this->assertSame('EF1122334455', $gone->serial_number);
        // The survivors stay present.
        $this->assertSame('present', $device->members()->where('member_id', 0)->first()->status);
    }

    public function test_a_returning_member_clears_the_offline_flag(): void
    {
        $device = Device::factory()->create(['vendor' => 'juniper', 'role' => 'switch']);
        DeviceMember::create(['device_id' => $device->id, 'member_id' => 2, 'serial_number' => 'EF1122334455', 'status' => 'missing', 'absent_since' => now()->subHour()]);

        (new VcMemberPoller($this->walker($this->threeMemberVc())))->poll($device);

        $back = $device->members()->where('member_id', 2)->first();
        $this->assertSame('present', $back->status);
        $this->assertNull($back->absent_since);
    }

    public function test_an_empty_walk_never_wipes_known_members(): void
    {
        // A standalone (non-VC) switch, or one that dropped the SNMP response, returns
        // nothing — existing members must NOT be flapped offline on an empty read.
        $device = Device::factory()->create(['vendor' => 'juniper', 'role' => 'switch']);
        (new VcMemberPoller($this->walker($this->threeMemberVc())))->poll($device);

        (new VcMemberPoller($this->walker([])))->poll($device);

        $this->assertSame(3, $device->members()->count());
        $this->assertSame(0, $device->members()->where('status', 'missing')->count());
    }

    public function test_it_skips_non_juniper_and_non_switch_devices(): void
    {
        $edge = Device::factory()->create(['vendor' => 'silverpeak', 'role' => 'edgeconnect']);
        $fw = Device::factory()->create(['vendor' => 'juniper', 'role' => 'firewall']);

        $throwing = function (): string {
            throw new \RuntimeException('walker must not be called for a non-VC device');
        };
        (new VcMemberPoller($throwing))->poll($edge);
        (new VcMemberPoller($throwing))->poll($fw);

        $this->assertSame(0, DeviceMember::count());
    }
}
