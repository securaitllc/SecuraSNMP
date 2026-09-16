<?php

namespace Tests\Feature;

use App\Mail\NoraRequestMail;
use App\Models\Circuit;
use App\Models\CircuitAlert;
use App\Models\Device;
use App\Models\DeviceAlarm;
use App\Models\NoraRequest;
use App\Models\Site;
use App\Models\User;
use App\Services\Lumen\NoraComposer;
use App\Services\Lumen\NoraGates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class NoraTest extends TestCase
{
    use RefreshDatabase;

    private function lumenCircuit(array $extra = []): Circuit
    {
        $site = Site::factory()->create(['name' => 'Lawrenceville GA', 'site_number' => '037', 'site_type' => 'branch']);

        return Circuit::factory()->for($site)->create(array_merge([
            'isp_name' => 'Lumen', 'circuit_id' => '445453113', 'lec_circuit_id' => null,
            'monitored_ip' => '4.4.252.37', 'gateway_ip' => '4.4.252.37', 'wan_interface' => 'wan1',
            'status' => 'down', 'monitoring_enabled' => true,
        ], $extra));
    }

    public function test_the_email_carries_everything_nora_asks_for(): void
    {
        $c = $this->lumenCircuit();
        CircuitAlert::factory()->create(['circuit_id' => $c->id, 'started_at' => now()->subMinutes(42), 'ended_at' => null]);

        $m = (new NoraComposer)->compose($c, 'open_ticket');

        $this->assertStringContainsString('445453113', $m['subject']);
        foreach (['Circuit ID: 445453113', 'Existing ticket number: none', 'Symptoms:', 'Started:', 'Still occurring: yes', 'Business impact: medium', '#037 Lawrenceville GA'] as $needle) {
            $this->assertStringContainsString($needle, $m['body'], $needle);
        }
    }

    public function test_a_hub_defaults_to_critical_and_a_note_is_included(): void
    {
        $c = $this->lumenCircuit();
        $c->site->update(['site_type' => 'hub']);

        $m = (new NoraComposer)->compose($c->fresh('site'), 'open_ticket', null, 'Confirmed on site: modem lights normal.');

        $this->assertSame('critical', $m['impact']);
        $this->assertStringContainsString('Notes: Confirmed on site', $m['body']);
    }

    public function test_only_lumen_circuits_are_handled(): void
    {
        $this->assertTrue(NoraComposer::handles($this->lumenCircuit()));
        $this->assertTrue(NoraComposer::handles($this->lumenCircuit(['isp_name' => 'CenturyLink'])));
        $this->assertFalse(NoraComposer::handles($this->lumenCircuit(['isp_name' => 'Spectrum'])));
    }

    public function test_the_breaker_warns_when_a_share_of_the_carrier_is_down(): void
    {
        // 15 September: 232 circuits down at once because ICMP was steered on our own
        // side. Every one would have been a ticket. The breaker is the guard.
        $c = $this->lumenCircuit();
        for ($i = 0; $i < 9; $i++) {
            $this->lumenCircuit(['circuit_id' => "L{$i}", 'monitored_ip' => "4.4.1.{$i}", 'status' => $i < 4 ? 'down' : 'up']);
        }

        $g = (new NoraGates)->evaluate($c);

        $this->assertTrue($g['breaker']);
        $this->assertSame(5, $g['carrier_down']);
        $this->assertSame(10, $g['carrier_total']);
        $this->assertStringContainsString('check our side', implode(' ', $g['warnings']));
    }

    public function test_an_appliance_alarm_on_the_wan_corroborates(): void
    {
        $c = $this->lumenCircuit();
        $edge = Device::factory()->create(['site_id' => $c->site_id, 'role' => 'edgeconnect']);

        $this->assertFalse((new NoraGates)->evaluate($c)['corroborated'], 'ICMP alone is not corroboration');

        DeviceAlarm::create(['device_id' => $edge->id, 'alarm_id' => 'ec:196625:gw:4.4.252.37', 'severity' => 'critical',
            'description' => 'Next-hop unreachable', 'first_seen_at' => now(), 'cleared_at' => null]);

        $this->assertTrue((new NoraGates)->evaluate($c)['corroborated']);
    }

    public function test_sending_is_refused_while_disabled(): void
    {
        config(['nora.enabled' => false]);
        $c = $this->lumenCircuit();
        $u = User::factory()->create(['role' => 'admin']);

        $this->actingAs($u)->postJson("/api/circuits/{$c->id}/nora/send", ['kind' => 'open_ticket'])->assertStatus(409);
    }

    public function test_send_mails_nora_and_records_the_message_id(): void
    {
        Mail::fake();
        config(['nora.enabled' => true, 'nora.address' => 'NORA@lumen.com', 'nora.from_address' => 'noc@example.test']);
        $c = $this->lumenCircuit();
        $u = User::factory()->create(['role' => 'analyst']);

        $res = $this->actingAs($u)->postJson("/api/circuits/{$c->id}/nora/send", ['kind' => 'open_ticket', 'impact' => 'high'])
            ->assertCreated();

        Mail::assertSent(NoraRequestMail::class, fn ($m) => $m->hasTo('NORA@lumen.com') && str_contains($m->bodyText, '445453113'));
        $row = NoraRequest::first();
        $this->assertNotNull($row);
        $this->assertStringStartsWith('<nodus-', $row->message_id);
        $this->assertSame('high', $row->impact);
        $this->assertSame($u->name, $row->sent_by);
        $this->assertSame($row->message_id, $res->json('message_id'));
    }

    public function test_an_edited_body_is_what_gets_sent_and_stored(): void
    {
        Mail::fake();
        config(['nora.enabled' => true]);
        $c = $this->lumenCircuit();
        $u = User::factory()->create(['role' => 'analyst']);

        $this->actingAs($u)->postJson("/api/circuits/{$c->id}/nora/send", ['kind' => 'open_ticket', 'body' => 'Custom text, circuit 445453113.'])->assertCreated();

        Mail::assertSent(NoraRequestMail::class, fn ($m) => $m->bodyText === 'Custom text, circuit 445453113.');
        $this->assertSame('Custom text, circuit 445453113.', NoraRequest::first()->body);
    }

    public function test_a_viewer_cannot_send(): void
    {
        config(['nora.enabled' => true]);
        $c = $this->lumenCircuit();
        $u = User::factory()->create(['role' => 'viewer']);

        $this->actingAs($u)->postJson("/api/circuits/{$c->id}/nora/send", ['kind' => 'open_ticket'])->assertForbidden();
    }

    public function test_a_non_lumen_circuit_is_refused(): void
    {
        config(['nora.enabled' => true]);
        $c = $this->lumenCircuit(['isp_name' => 'Spectrum']);
        $u = User::factory()->create(['role' => 'admin']);

        $this->actingAs($u)->getJson("/api/circuits/{$c->id}/nora/preview")->assertStatus(422);
    }

    public function test_recording_the_reply_ticket_updates_the_circuit_and_the_trail(): void
    {
        $c = $this->lumenCircuit();
        $u = User::factory()->create(['role' => 'analyst']);
        $req = NoraRequest::create(['circuit_id' => $c->id, 'kind' => 'open_ticket', 'message_id' => '<x@y>', 'subject' => 's', 'body' => 'b', 'sent_at' => now()]);

        $this->actingAs($u)->postJson("/api/circuits/{$c->id}/nora/{$req->id}/ticket", ['ticket_number' => '12345678'])->assertOk();

        $this->assertSame('12345678', $c->fresh()->isp_ticket);
        $this->assertSame('12345678', $req->fresh()->ticket_number);
        $this->assertDatabaseHas('circuit_isp_tickets', ['circuit_id' => $c->id, 'ticket_number' => '12345678', 'reason' => 'nora']);
    }

    public function test_a_site_number_already_in_the_name_is_not_repeated(): void
    {
        // Massey site names begin with the number on this fleet. Prefixing it again
        // put "Site: #037 #037 Lawrenceville GA" in an email to the carrier.
        $c = $this->lumenCircuit();
        $c->site->update(['name' => '#037 Lawrenceville GA', 'site_number' => '037']);

        $body = (new NoraComposer)->compose($c->fresh('site'), 'open_ticket')['body'];

        $this->assertStringContainsString('Site: #037 Lawrenceville GA', $body);
        $this->assertStringNotContainsString('#037 #037', $body);
    }

    public function test_long_running_trouble_reads_as_sustained(): void
    {
        // Carbon 3's diff is signed: now()->diffInMinutes($past) is NEGATIVE, so the
        // naive comparison made everything read as a flap, including a circuit that
        // had been degraded for two days.
        $c = $this->lumenCircuit(['status' => 'up', 'degraded_since' => now()->subDays(2)]);

        $this->assertTrue((new NoraGates)->evaluate($c)['sustained']);
    }

    public function test_brand_new_trouble_does_not(): void
    {
        $c = $this->lumenCircuit(['status' => 'up', 'degraded_since' => now()->subMinutes(2)]);

        $this->assertFalse((new NoraGates)->evaluate($c)['sustained']);
    }
}
