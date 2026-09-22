<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceAlarm;
use App\Models\Site;
use App\Services\FortiGateAlarmPoller;
use App\Services\FortiGateLogAlarms;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DNS and UTM failures are the gap SNMP cannot close: a FortiGate exposes no OID
 * for "rating lookups are timing out", it writes a log line and keeps forwarding
 * traffic — unrated and unfiltered. From the outside that looks perfectly healthy.
 */
class FortiGateLogAlarmsTest extends TestCase
{
    use RefreshDatabase;

    private function firewall(): Device
    {
        return Device::factory()->create([
            'site_id' => Site::factory()->create()->id,
            'name' => 'FL0001-HQ-FW',
            'vendor' => 'fortigate',
            'role' => 'firewall',
            'status' => 'active',
        ]);
    }

    public static function failureProvider(): array
    {
        return [
            'dns unreachable' => [
                'date=2026-09-18 logid="0100022921" type="event" subtype="system" level="critical" logdesc="DNS server unreachable" name="10.11.9.1"',
                'fgl:dns-unreachable', 'critical',
            ],
            'fortiguard unreachable' => [
                'logdesc="FortiGuard server unreachable" service="webfilter" msg="Cannot connect to FortiGuard servers"',
                'fgl:fortiguard-unreachable', 'critical',
            ],
            'rating error' => [
                'type="utm" subtype="webfilter" msg="URL rating error, passing traffic" cat=255',
                'fgl:rating-error', 'warning',
            ],
            'dns filter failing' => [
                'type="utm" subtype="dns" msg="DNS filter unavailable, requests bypassed"',
                'fgl:dnsfilter-failure', 'warning',
            ],
            'signature update failed' => [
                'logdesc="Update failed" msg="AV signature update failed after 3 attempts"',
                'fgl:update-failed', 'warning',
            ],
            'licence expired' => [
                'msg="FortiGuard Web Filtering contract expired"',
                'fgl:license-expired', 'critical',
            ],
            'conserve mode' => [
                'logdesc="Memory conserve mode" msg="Entered conserve mode"',
                'fgl:conserve-mode', 'critical',
            ],
        ];
    }

    /** @dataProvider failureProvider */
    public function test_a_failure_line_raises_the_right_alarm(string $line, string $alarmId, string $severity): void
    {
        $fw = $this->firewall();

        FortiGateLogAlarms::evaluate($fw, $line);

        $alarm = DeviceAlarm::where('device_id', $fw->id)->where('alarm_id', $alarmId)->first();
        $this->assertNotNull($alarm, "{$alarmId} should have been raised");
        $this->assertSame($severity, $alarm->severity);
    }

    public function test_ordinary_traffic_logging_raises_nothing(): void
    {
        // Almost every line a firewall sends is this. A rule that matched them would
        // bury the NOC instantly.
        $fw = $this->firewall();

        foreach ([
            'type="traffic" subtype="forward" action="accept" srcip=10.11.9.50 dstip=8.8.8.8 service="DNS"',
            'type="utm" subtype="webfilter" action="passthrough" cat=52 catdesc="Information Technology"',
            'type="event" subtype="user" action="login" status="success" user="admin"',
        ] as $line) {
            FortiGateLogAlarms::evaluate($fw, $line);
        }

        $this->assertSame(0, DeviceAlarm::where('device_id', $fw->id)->count());
    }

    public function test_a_storm_of_one_condition_collapses_into_one_alarm(): void
    {
        // A broken resolver writes hundreds of lines a minute. One condition is one
        // alarm, not one alarm per line.
        $fw = $this->firewall();

        for ($i = 0; $i < 50; $i++) {
            FortiGateLogAlarms::evaluate($fw, 'logdesc="DNS server unreachable" name="10.11.9.1"');
        }

        $this->assertSame(1, DeviceAlarm::where('device_id', $fw->id)->count());
    }

    public function test_an_alarm_clears_once_the_condition_stops_recurring(): void
    {
        $fw = $this->firewall();

        FortiGateLogAlarms::evaluate($fw, 'logdesc="DNS server unreachable"');
        $alarm = DeviceAlarm::where('alarm_id', 'fgl:dns-unreachable')->first();

        // Still recurring — must not clear.
        $this->assertSame(0, FortiGateLogAlarms::clearQuiet());
        $this->assertNull($alarm->fresh()->cleared_at);

        // Gone quiet past the window.
        $alarm->forceFill(['updated_at' => now()->subMinutes(config('fortigate.log_alarm_quiet_minutes') + 5)])->saveQuietly();

        $this->assertSame(1, FortiGateLogAlarms::clearQuiet());
        $this->assertNotNull($alarm->fresh()->cleared_at);
    }

    public function test_a_manual_clear_is_respected_while_the_condition_still_logs(): void
    {
        $fw = $this->firewall();

        FortiGateLogAlarms::evaluate($fw, 'logdesc="DNS server unreachable"');
        $alarm = DeviceAlarm::where('alarm_id', 'fgl:dns-unreachable')->first();
        $alarm->update(['cleared_at' => now(), 'cleared_manually' => true]);

        FortiGateLogAlarms::evaluate($fw, 'logdesc="DNS server unreachable"');

        $this->assertNotNull($alarm->fresh()->cleared_at, 'the NOC cleared it; do not resurrect');
    }

    public function test_a_recurrence_after_a_quiet_clear_opens_a_fresh_ticket(): void
    {
        $fw = $this->firewall();

        FortiGateLogAlarms::evaluate($fw, 'logdesc="DNS server unreachable"');
        $alarm = DeviceAlarm::where('alarm_id', 'fgl:dns-unreachable')->first();
        $first = $alarm->ticket_number;

        $alarm->forceFill(['updated_at' => now()->subMinutes(config('fortigate.log_alarm_quiet_minutes') + 5)])->saveQuietly();
        FortiGateLogAlarms::clearQuiet();

        FortiGateLogAlarms::evaluate($fw, 'logdesc="DNS server unreachable"');

        $alarm = $alarm->fresh();
        $this->assertNull($alarm->cleared_at);
        $this->assertNotSame($first, $alarm->ticket_number);
    }

    public function test_the_snmp_poller_does_not_clear_log_derived_alarms(): void
    {
        // The two sources share a device and nearly share a prefix. A log alarm is
        // never present in an SNMP table, so a poller that swept everything starting
        // "fg" would have cleared every DNS alarm within 90 seconds of raising it.
        $fw = $this->firewall();

        FortiGateLogAlarms::evaluate($fw, 'logdesc="DNS server unreachable"');

        $poller = new FortiGateAlarmPoller(fn (Device $d, string $oid): string => $oid === '.1.3.6.1.2.1.1.3.0'
            ? 'sysUpTimeInstance = Timeticks: (1) 0:00:00.01'
            : '');
        $poller->poll($fw);

        $this->assertNull(
            DeviceAlarm::where('alarm_id', 'fgl:dns-unreachable')->first()->cleared_at,
            'the SNMP poller must only clear what it raises'
        );
    }

    public function test_a_fortianalyzer_failure_is_not_read_as_a_signature_failure(): void
    {
        // The real line from AZR-FW01. Matching the RAW message let a pattern take
        // "update" from reason= and "Failed" from msg= — two unrelated fields 30
        // characters apart — and raise "FortiGuard signature update failed" on a
        // firewall whose signatures were fine. It could never clear either, because
        // FortiAnalyzer retries every few minutes and kept resetting the quiet timer.
        $fw = $this->firewall();
        $line = 'logid="0100022903" type="event" subtype="system" level="critical" '
            .'logdesc="FortiAnalyzer connection failed" action="connect" status="failure" '
            .'reason="oftp login data updated for reconnecting" msg="Failed to connect"';

        FortiGateLogAlarms::evaluate($fw, $line);

        $this->assertNull(
            DeviceAlarm::where('alarm_id', 'fgl:update-failed')->first(),
            'a FortiAnalyzer fault must never be reported as a signature fault'
        );
        $this->assertNotNull(DeviceAlarm::where('alarm_id', 'fgl:fortianalyzer-down')->first());
    }

    public function test_fields_are_parsed_and_matched_one_at_a_time(): void
    {
        $f = FortiGateLogAlarms::parseFields('logdesc="DNS server unreachable" srcip=10.1.1.1 msg="x y"');

        $this->assertSame('DNS server unreachable', $f['logdesc']);
        $this->assertSame('10.1.1.1', $f['srcip'], 'unquoted values parse too');
        $this->assertSame('x y', $f['msg']);
    }

    public function test_a_line_with_no_logdesc_raises_nothing(): void
    {
        // Traffic logs carry no logdesc at all. They are the overwhelming majority of
        // what a firewall sends, and none of them is an event.
        $fw = $this->firewall();

        FortiGateLogAlarms::evaluate($fw, 'type="traffic" subtype="forward" action="accept" service="DNS"');

        $this->assertSame(0, DeviceAlarm::where('device_id', $fw->id)->count());
    }

    public function test_the_observed_sdn_connector_failure_alarms(): void
    {
        $fw = $this->firewall();

        FortiGateLogAlarms::evaluate($fw, 'type="event" subtype="connector" level="error" logdesc="SDN Connector API failed"');

        $alarm = DeviceAlarm::where('alarm_id', 'fgl:sdn-connector-failed')->first();
        $this->assertNotNull($alarm);
        $this->assertStringContainsString('dynamic address objects', $alarm->description);
    }
}
