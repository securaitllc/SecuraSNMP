<?php

namespace Tests\Feature;

use App\Models\SshCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SshCredentialControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_a_credential_without_leaking_the_password(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->postJson('/api/ssh-credentials', [
            'name' => 'Massey NOC',
            'username' => 'netadmin',
            'password' => 'sup3r-secret',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.has_password', true);
        $response->assertJsonPath('data.username', 'netadmin');
        $this->assertArrayNotHasKey('password', $response->json('data'));
        $this->assertDatabaseHas('ssh_credentials', ['name' => 'Massey NOC', 'username' => 'netadmin']);
    }

    public function test_password_is_encrypted_at_rest(): void
    {
        $cred = SshCredential::factory()->create(['password' => 'plaintext-pw']);

        $raw = \DB::table('ssh_credentials')->where('id', $cred->id)->value('password');
        $this->assertNotSame('plaintext-pw', $raw);
        $this->assertSame('plaintext-pw', $cred->fresh()->password);
    }

    public function test_update_without_password_keeps_the_stored_secret(): void
    {
        $admin = User::factory()->admin()->create();
        $cred = SshCredential::factory()->create(['password' => 'original-pw']);

        $this->actingAs($admin)->putJson("/api/ssh-credentials/{$cred->id}", [
            'name' => $cred->name,
            'username' => 'renamed',
        ])->assertOk();

        $cred->refresh();
        $this->assertSame('renamed', $cred->username);
        $this->assertSame('original-pw', $cred->password);
    }

    public function test_viewer_cannot_list_or_create(): void
    {
        $viewer = User::factory()->create();

        $this->actingAs($viewer)->getJson('/api/ssh-credentials')->assertForbidden();
        $this->actingAs($viewer)->postJson('/api/ssh-credentials', ['name' => 'X'])->assertForbidden();
    }

    public function test_name_must_be_unique(): void
    {
        SshCredential::factory()->create(['name' => 'Dup']);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->postJson('/api/ssh-credentials', [
            'name' => 'Dup',
            'username' => 'x',
            'password' => 'y',
        ])->assertStatus(422);
    }
}
