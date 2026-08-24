<?php

namespace Tests\Feature;

use App\Models\OsintLookup;
use App\Models\User;
use App\Services\Osint\OsintDomainService;
use App\Services\Osint\OsintValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class OsintTest extends TestCase
{
    use RefreshDatabase;

    private function super(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    public function test_validator_blocks_shell_injection_and_normalizes_hosts(): void
    {
        foreach (['evil.com; rm -rf /', '$(whoami).com', 'a b.com', 'http://', '../../etc'] as $bad) {
            try {
                OsintValidator::domain($bad);
                $this->fail("expected rejection of: {$bad}");
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame('masseyservices.nowsso.com', OsintValidator::domain('https://masseyservices.nowsso.com/login?x=1'));
        $this->assertSame('nowsso.com', OsintValidator::baseDomain('masseyservices.nowsso.com'));
        $this->assertSame('+14075550142', OsintValidator::phone('+1 (407) 555-0142'));
    }

    public function test_osint_is_super_admin_only(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->getJson('/api/osint/integrations')->assertForbidden();
        $this->actingAs($this->super())->getJson('/api/osint/integrations')->assertOk()->assertJsonPath('data.0.provider', 'ipdata');
    }

    public function test_api_key_is_stored_encrypted_and_returned_masked(): void
    {
        $this->actingAs($this->super())
            ->postJson('/api/osint/integrations/ipdata', ['api_key' => 'SECRET-IPDATA-3f9a'])
            ->assertOk()->assertJsonPath('configured', true)->assertJsonPath('masked', '••••••••3f9a');

        // At rest it must not be the plaintext.
        $raw = DB::table('osint_integrations')->where('provider', 'ipdata')->value('api_key');
        $this->assertNotSame('SECRET-IPDATA-3f9a', $raw);
        $this->assertNotEmpty($raw);
    }

    public function test_integration_test_button_reports_key_validity(): void
    {
        // Sequence: first request (valid key) 200, second (bad key) 403.
        Http::fakeSequence('api.ipdata.co/*')->push(['ip' => '8.8.8.8'], 200)->push('bad key', 403);
        $this->actingAs($this->super())
            ->postJson('/api/osint/integrations/ipdata/test', ['api_key' => 'k'])
            ->assertOk()->assertJsonPath('ok', true);
        $this->actingAs($this->super())
            ->postJson('/api/osint/integrations/ipdata/test', ['api_key' => 'bad'])
            ->assertOk()->assertJsonPath('ok', false);
    }

    public function test_domain_lookup_enriches_scores_and_audit_logs(): void
    {
        Http::fake(['crt.sh/*' => Http::response([['name_value' => "login.nowsso.com\nmasseyservices.nowsso.com"]], 200)]);
        // Fake the shell runner so no real whois/dig/subfinder is spawned.
        $this->app->instance(OsintDomainService::class, new OsintDomainService(function (array $cmd) {
            return match ($cmd[0]) {
                'whois' => "Registrar: NameSilo, LLC\nCreation Date: ".now()->subDays(6)->format('Y-m-d')."\nName Server: ns1.dnsowl.com\n",
                'dig' => ($cmd[3] ?? '') === 'A' ? '45.133.1.77' : '',
                default => '',
            };
        }));

        $res = $this->actingAs($this->super())
            ->postJson('/api/osint/lookup/domain', ['target' => 'masseyservices.nowsso.com'])
            ->assertOk();

        $res->assertJsonPath('result.base', 'nowsso.com');
        $res->assertJsonPath('result.risk.verdict', 'malicious'); // 6-day-old + no DMARC
        $this->assertNotEmpty($res->json('iocs'));
        $this->assertDatabaseHas('osint_lookups', ['kind' => 'domain', 'target' => 'masseyservices.nowsso.com']);
        $this->assertSame(1, OsintLookup::count());
    }

    public function test_domain_lookup_rejects_a_bad_target(): void
    {
        $this->actingAs($this->super())
            ->postJson('/api/osint/lookup/domain', ['target' => 'evil.com; id'])
            ->assertStatus(422);
    }

    public function test_case_is_created_from_iocs_and_exports(): void
    {
        $super = $this->super();
        $case = $this->actingAs($super)->postJson('/api/osint/cases', [
            'title' => 'Massey impersonation',
            'severity' => 'high',
            'mitre' => ['T1566'],
            'iocs' => [
                ['type' => 'domain', 'value' => 'nowsso.com', 'confidence' => 'high', 'source' => 'whois'],
                ['type' => 'ip', 'value' => '45.133.1.77', 'confidence' => 'high', 'source' => 'dns'],
            ],
        ])->assertCreated();

        $id = $case->json('id');
        $this->assertStringStartsWith('CASE-', $case->json('case_number'));
        $this->assertDatabaseCount('osint_iocs', 2);

        // CSV export
        $this->actingAs($super)->get("/api/osint/cases/{$id}/export")
            ->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        // STIX export
        $this->actingAs($super)->getJson("/api/osint/cases/{$id}/export?format=stix")
            ->assertOk()->assertJsonPath('type', 'bundle');
    }
}
