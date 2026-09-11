<?php

namespace Tests\Feature;

use App\Models\OsintCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Adding indicators to a case that already exists.
 *
 * Creating a case swept up whatever the workspace had collected; afterwards the only
 * way in was typing indicators one at a time, so a second round of searching produced
 * a SECOND case instead of enriching the first.
 */
class OsintCaseEnrichTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    private function aCase(User $owner): OsintCase
    {
        return OsintCase::create([
            'case_number' => OsintCase::nextCaseNumber(),
            'title' => 'Phishing investigation', 'severity' => 'high',
            'status' => 'investigating', 'owner_id' => $owner->id, 'mitre' => [],
        ]);
    }

    public function test_a_batch_of_harvested_indicators_attaches_to_an_existing_case(): void
    {
        $user = $this->superAdmin();
        $case = $this->aCase($user);

        $this->actingAs($user)->postJson("/api/osint/cases/{$case->id}/iocs", [
            'iocs' => [
                ['type' => 'domain', 'value' => 'evil.example', 'confidence' => 'high', 'source' => 'whois'],
                ['type' => 'ip', 'value' => '45.133.1.77', 'source' => 'dns'],
            ],
        ])->assertCreated()->assertJsonPath('added', 2);

        $this->assertSame(2, $case->fresh()->iocs()->count());
    }

    public function test_re_running_the_same_lookup_adds_only_what_is_new(): void
    {
        // Investigators pivot and re-search constantly; a second pass must not fill the
        // case with duplicates, and must say plainly how many were already there.
        $user = $this->superAdmin();
        $case = $this->aCase($user);
        $batch = ['iocs' => [
            ['type' => 'domain', 'value' => 'evil.example'],
            ['type' => 'ip', 'value' => '45.133.1.77'],
        ]];

        $this->actingAs($user)->postJson("/api/osint/cases/{$case->id}/iocs", $batch)->assertCreated();

        $batch['iocs'][] = ['type' => 'email', 'value' => 'a@evil.example'];

        $this->actingAs($user)->postJson("/api/osint/cases/{$case->id}/iocs", $batch)
            ->assertCreated()
            ->assertJsonPath('added', 1)
            ->assertJsonPath('duplicates', 2);

        $this->assertSame(3, $case->fresh()->iocs()->count());
    }

    public function test_adding_one_indicator_by_hand_still_works(): void
    {
        $user = $this->superAdmin();
        $case = $this->aCase($user);

        $this->actingAs($user)->postJson("/api/osint/cases/{$case->id}/iocs", [
            'type' => 'hash', 'value' => 'd41d8cd98f00b204e9800998ecf8427e',
        ])->assertCreated();

        $this->assertSame(1, $case->fresh()->iocs()->count());
    }

    public function test_a_malformed_batch_is_rejected_whole(): void
    {
        // A bad row must not leave the case half-populated.
        $user = $this->superAdmin();
        $case = $this->aCase($user);

        $this->actingAs($user)->postJson("/api/osint/cases/{$case->id}/iocs", [
            'iocs' => [
                ['type' => 'domain', 'value' => 'good.example'],
                ['type' => 'not-a-type', 'value' => 'bad'],
            ],
        ])->assertStatus(422);

        $this->assertSame(0, $case->fresh()->iocs()->count());
    }

    public function test_osint_stays_restricted_to_super_admins(): void
    {
        $owner = $this->superAdmin();
        $case = $this->aCase($owner);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->postJson("/api/osint/cases/{$case->id}/iocs", [
            'iocs' => [['type' => 'domain', 'value' => 'evil.example']],
        ])->assertForbidden();
    }
}
