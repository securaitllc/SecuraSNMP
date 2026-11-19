<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceAlarm;
use App\Models\Site;
use App\Services\HostnamePoller;
use App\Services\HostnameRecheck;
use App\Services\TunnelCorrelation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 18 September: an EdgeConnect at SC125 was renamed on the console from
 * GA0008-SC125_SDW to SC125-ECB01. Within ten minutes the HQ hubs were raising
 * "to_SC125-ECB01_*" tunnel alarms, but `devices.name` still held the old name —
 * so the token the alarm carried (sc125-ecb1) matched nothing, the symptoms never
 * rolled under SC125's incident, and they read as an HQ fault instead.
 */
class HostnameRenameCorrelationTest extends TestCase
{
    use RefreshDatabase;

    private function edge(Site $site, string $name, array $extra = []): Device
    {
        return Device::factory()->create(array_merge([
            'site_id' => $site->id,
            'name' => $name,
            'role' => 'edgeconnect',
            'vendor' => 'silverpeak',
            'status' => 'active',
        ], $extra));
    }

    /** The branch is genuinely down, which is what a tunnel symptom rolls up to. */
    private function branchIsDown(Device $edge): void
    {
        DeviceAlarm::create([
            'device_id' => $edge->id,
            'alarm_id' => 'device-unreachable',
            'description' => 'Device unreachable',
            'severity' => 'critical',
            'ticket_number' => '10000001',
            'first_seen_at' => now()->subMinutes(10),
        ]);
    }

    public function test_a_tunnel_symptom_still_resolves_after_the_box_is_renamed(): void
    {
        $hq = Site::factory()->create(['name' => '#893 HQ Orlando FL']);
        $branch = Site::factory()->create(['name' => '#125 Cumming GA']);

        $hub = $this->edge($hq, 'FL0001-HQ-ECH01');

        // The record still says the old name; the box now answers to the new one.
        $edge = $this->edge($branch, 'GA0008-SC125_SDW', ['snmp_hostname' => 'SC125-ECB01']);
        $this->branchIsDown($edge);

        $alarm = DeviceAlarm::create([
            'device_id' => $hub->id,
            'alarm_id' => 'ec:65537:to_SC125-ECB01_DIA2-BB',
            'description' => 'Tunnel state is Down — to_SC125-ECB01_DIA2-BB',
            'severity' => 'critical',
            'ticket_number' => '10000002',
            'first_seen_at' => now()->subMinutes(5),
        ]);

        $result = (new TunnelCorrelation)->analyze();

        $this->assertContains($alarm->id, $result['suppressed_alarm_ids'],
            'the hub symptom must roll under the branch that is actually down');
        $this->assertSame($branch->id, $result['incidents'][0]['site_id']);
    }

    public function test_the_old_name_keeps_resolving_after_an_adopt(): void
    {
        // Peers do not all switch at once: for a few minutes after adopting the new
        // name, alarms still arrive under the old one.
        $hq = Site::factory()->create(['name' => '#893 HQ Orlando FL']);
        $branch = Site::factory()->create(['name' => '#125 Cumming GA']);

        $hub = $this->edge($hq, 'FL0001-HQ-ECH01');
        $edge = $this->edge($branch, 'SC125-ECB01', [
            'snmp_hostname' => 'SC125-ECB01',
            'previous_name' => 'GA0008-SC125_SDW',
        ]);
        $this->branchIsDown($edge);

        $alarm = DeviceAlarm::create([
            'device_id' => $hub->id,
            'alarm_id' => 'ec:65537:to_GA0008-SC125_DIA1-DIA1',
            'description' => 'Tunnel state is Down',
            'severity' => 'critical',
            'ticket_number' => '10000003',
            'first_seen_at' => now()->subMinutes(5),
        ]);

        $result = (new TunnelCorrelation)->analyze();

        $this->assertContains($alarm->id, $result['suppressed_alarm_ids']);
    }

    public function test_an_unknown_peer_token_queues_the_renamed_box_for_a_reread(): void
    {
        $hq = Site::factory()->create(['name' => '#893 HQ Orlando FL']);
        $branch = Site::factory()->create(['name' => '#125 Cumming GA']);

        $hub = $this->edge($hq, 'FL0001-HQ-ECH01', ['hostname_checked_at' => now()->subMinutes(30)]);
        $edge = $this->edge($branch, 'GA0008-SC125_SDW', ['hostname_checked_at' => now()->subMinutes(30)]);

        // The hub names a remote end we have never heard of, and stops naming SC125.
        DeviceAlarm::create([
            'device_id' => $hub->id,
            'alarm_id' => 'ec:65537:to_SC125-ECB01_DIA2-BB',
            'description' => 'Tunnel state is Down',
            'severity' => 'critical',
            'ticket_number' => '10000004',
            'first_seen_at' => now(),
        ]);

        $queued = (new HostnameRecheck)->run();

        $this->assertContains($edge->id, $queued, 'the box the peers stopped naming is the one that moved');
        $this->assertNull($edge->fresh()->hostname_checked_at, 'so HostnamePoller walks it on the next cycle');
        $this->assertNotNull($hub->fresh()->hostname_checked_at, 'the hub is still named by its own alarm, leave it');
    }

    public function test_nothing_is_rechecked_when_every_peer_token_is_known(): void
    {
        $hq = Site::factory()->create();
        $branch = Site::factory()->create();

        $hub = $this->edge($hq, 'FL0001-HQ-ECH01', ['hostname_checked_at' => now()->subMinutes(30)]);
        $edge = $this->edge($branch, 'GA0008-SC125_SDW', ['hostname_checked_at' => now()->subMinutes(30)]);

        DeviceAlarm::create([
            'device_id' => $hub->id,
            'alarm_id' => 'ec:65537:to_GA0008-SC125_DIA1-DIA1',
            'description' => 'Tunnel state is Down',
            'severity' => 'critical',
            'ticket_number' => '10000005',
            'first_seen_at' => now(),
        ]);

        $this->assertSame([], (new HostnameRecheck)->run());
        $this->assertNotNull($edge->fresh()->hostname_checked_at);
    }

    public function test_a_quiet_fleet_triggers_no_rechecks(): void
    {
        // No tunnel alarms at all must never be read as "every appliance was renamed".
        $site = Site::factory()->create();
        $edge = $this->edge($site, 'GA0008-SC125_SDW', ['hostname_checked_at' => now()->subMinutes(5)]);

        $this->assertSame([], (new HostnameRecheck)->run());
        $this->assertNotNull($edge->fresh()->hostname_checked_at);
    }

    public function test_the_recheck_is_capped_so_a_fleet_rename_cannot_stampede(): void
    {
        $hq = Site::factory()->create();
        $hub = $this->edge($hq, 'FL0001-HQ-ECH01', ['hostname_checked_at' => now()->subMinutes(30)]);

        for ($i = 1; $i <= HostnameRecheck::MAX_PER_SWEEP + 6; $i++) {
            $this->edge(Site::factory()->create(), "GA0008-SC{$i}_SDW", ['hostname_checked_at' => now()->subMinutes(30)]);
        }

        DeviceAlarm::create([
            'device_id' => $hub->id,
            // Shares the GA0008 run with every branch below, so all of them are
            // plausible — which is exactly the case the cap exists for.
            'alarm_id' => 'ec:65537:to_GA0008-NEWBOX_DIA1-DIA1',
            'description' => 'Tunnel state is Down',
            'severity' => 'critical',
            'ticket_number' => '10000006',
            'first_seen_at' => now(),
        ]);

        $this->assertCount(HostnameRecheck::MAX_PER_SWEEP, (new HostnameRecheck)->run());
    }

    public function test_a_box_already_due_a_walk_is_not_queued_again(): void
    {
        $hq = Site::factory()->create();
        $branch = Site::factory()->create();

        $hub = $this->edge($hq, 'FL0001-HQ-ECH01', ['hostname_checked_at' => now()->subMinutes(30)]);
        $edge = $this->edge($branch, 'GA0008-SC125_SDW', [
            'hostname_checked_at' => now()->subHours(HostnamePoller::REFRESH_HOURS + 1),
        ]);

        DeviceAlarm::create([
            'device_id' => $hub->id,
            'alarm_id' => 'ec:65537:to_SC125-ECB01_DIA2-BB',
            'description' => 'Tunnel state is Down',
            'severity' => 'critical',
            'ticket_number' => '10000007',
            'first_seen_at' => now(),
        ]);

        $this->assertSame([], (new HostnameRecheck)->run(), 'HostnamePoller will walk it on its own next pass');
    }
}
