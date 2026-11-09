<?php

namespace Tests\Feature;

use App\Events\TwoFactorFailed;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    private function serverVars(): array
    {
        return ['HTTP_USER_AGENT' => 'audit-test-agent/1.0', 'REMOTE_ADDR' => '10.20.30.40'];
    }

    /** Asserts no column of any audit row contains the given secret. */
    private function assertSecretNotLogged(string $secret): void
    {
        foreach (AuditLog::all() as $row) {
            $this->assertStringNotContainsString($secret, json_encode($row->getAttributes()));
        }
    }

    /** LOG-01: a successful login writes an auth.login row with actor, IP and user agent. */
    public function test_log01_successful_login_is_audited(): void
    {
        $user = User::factory()->create(['email' => 'ok@test.local']);

        $this->withServerVariables($this->serverVars())
            ->postJson('/api/login', ['email' => 'ok@test.local', 'password' => 'password'])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth.login',
            'user_id' => $user->id,
            'email' => 'ok@test.local',
            'ip_address' => '10.20.30.40',
            'user_agent' => 'audit-test-agent/1.0',
            'path' => 'api/login',
        ]);
        $this->assertSecretNotLogged('"password"');
    }

    /** LOG-01: logout writes an auth.logout row for the signed-in user. */
    public function test_log01_logout_is_audited(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/logout')->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.logout', 'user_id' => $user->id]);
    }

    /** LOG-01: a Failed auth event records the attempted email, never the password. */
    public function test_log01_failed_login_event_records_email_without_password(): void
    {
        event(new Failed('web', null, ['email' => 'nobody@test.local', 'password' => 'Sup3r-S3cret-Guess']));

        $row = AuditLog::where('action', 'auth.failed')->sole();
        $this->assertSame('nobody@test.local', $row->email);
        $this->assertNull($row->user_id);
        $this->assertSecretNotLogged('Sup3r-S3cret-Guess');
    }

    /** LOG-01: a failed login for a real account is attributed to that account. */
    public function test_log01_failed_login_event_attributes_known_user(): void
    {
        $user = User::factory()->create(['email' => 'known@test.local']);

        event(new Failed('web', $user, ['email' => 'known@test.local', 'password' => 'wrong-one']));

        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.failed', 'user_id' => $user->id, 'email' => 'known@test.local']);
        $this->assertSecretNotLogged('wrong-one');
    }

    /** LOG-01: hitting the login throttle writes an auth.lockout row with the targeted email. */
    public function test_log01_login_lockout_is_audited(): void
    {
        User::factory()->create(['email' => 'target@test.local']);

        $locked = false;
        for ($i = 0; $i < 8 && ! $locked; $i++) {
            $locked = $this->withServerVariables($this->serverVars())
                ->postJson('/api/login', ['email' => 'target@test.local', 'password' => 'Wr0ng-Pass-'.$i])
                ->status() === 429;
        }

        $this->assertTrue($locked, 'login throttle never tripped');
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.lockout', 'email' => 'target@test.local', 'status' => 429]);
        $this->assertSecretNotLogged('Wr0ng-Pass-');
    }

    /** LOG-01: a bad second factor writes auth.mfa_failed, never the code. */
    public function test_log01_mfa_failure_is_audited_without_code(): void
    {
        $user = User::factory()->create();

        event(new TwoFactorFailed($user));

        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.mfa_failed', 'user_id' => $user->id, 'email' => $user->email, 'status' => 422]);
    }

    /** LOG-02: bulk exports are audited even though they are GETs. */
    public function test_log02_report_export_is_audited(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/api/reports/device-inventory/export');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'api.read',
            'user_id' => $admin->id,
            'method' => 'GET',
            'path' => 'api/reports/device-inventory/export',
        ]);
    }

    /** LOG-02: the user list and the audit log itself are sensitive reads. */
    public function test_log02_user_and_audit_log_reads_are_audited(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->getJson('/api/users')->assertOk();
        $this->actingAs($admin)->getJson('/api/audit-logs')->assertOk();

        $this->assertDatabaseHas('audit_logs', ['method' => 'GET', 'path' => 'api/users']);
        $this->assertDatabaseHas('audit_logs', ['method' => 'GET', 'path' => 'api/audit-logs']);
    }

    /** LOG-02: ordinary GETs (and look-alikes such as flows/exporters) stay unaudited. */
    public function test_log02_ordinary_gets_are_not_audited(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->getJson('/api/maintenance-windows')->assertOk();
        $this->actingAs($admin)->getJson('/api/flows/exporters');

        $this->assertDatabaseCount('audit_logs', 0);
    }

    /** LOG-03: a write records the bound target and a redacted field list. */
    public function test_log03_write_records_target_and_redacted_changes(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create(['role' => 'viewer']);

        $this->actingAs($admin)->putJson("/api/users/{$target->id}", [
            'name' => $target->name,
            'email' => $target->email,
            'role' => 'analyst',
            'is_active' => true,
            'password' => 'N3w-Long-Passw0rd!',
            'password_confirmation' => 'N3w-Long-Passw0rd!',
        ])->assertOk();

        $row = AuditLog::where('method', 'PUT')->sole();
        $this->assertSame(User::class, $row->target_type);
        $this->assertSame((string) $target->id, (string) $row->target_id);
        $this->assertSame('analyst', $row->changes['role']);
        $this->assertSame('[redacted]', $row->changes['password']);
        $this->assertSame('[redacted]', $row->changes['password_confirmation']);
        $this->assertSecretNotLogged('N3w-Long-Passw0rd!');
    }

    /** LOG-03: secret-looking keys are redacted at any depth. */
    public function test_log03_nested_secrets_are_redacted(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->postJson('/api/snmp-credentials', [
            'name' => 'audit-profile',
            'snmp_version' => 'v2c',
            'snmp_community' => 'c0mmunity-canary',
            'extra' => ['api_key' => 'nested-key-canary', 'note' => 'kept'],
        ]);

        $row = AuditLog::where('path', 'api/snmp-credentials')->sole();
        $this->assertSame('[redacted]', $row->changes['snmp_community']);
        $this->assertSame('[redacted]', $row->changes['extra']['api_key']);
        $this->assertSame('kept', $row->changes['extra']['note']);
        $this->assertSecretNotLogged('c0mmunity-canary');
        $this->assertSecretNotLogged('nested-key-canary');
    }

    /** LOG-03: the second-factor code posted to enrolment is never stored. */
    public function test_log03_totp_code_is_redacted(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/2fa/confirm', ['code' => '493817']);

        $this->assertSecretNotLogged('493817');
    }
}
