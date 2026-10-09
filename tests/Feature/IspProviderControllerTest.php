<?php

namespace Tests\Feature;

use App\Models\Circuit;
use App\Models\IspProvider;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IspProviderControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_can_list_providers(): void
    {
        IspProvider::factory()->count(2)->create();
        $viewer = User::factory()->create();

        $this->actingAs($viewer)->getJson('/api/isp-providers')->assertOk()->assertJsonCount(2);
    }

    public function test_admin_can_create_a_provider_with_rep_contact(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->postJson('/api/isp-providers', [
            'name' => 'AT&T',
            'support_phone' => '1-800-555-0199',
            'account_rep_name' => 'Jane Doe',
            'account_rep_mobile' => '407-555-0100',
            'account_rep_phone' => '407-555-0101',
            'account_rep_email' => 'jane@att.example',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('isp_providers', ['name' => 'AT&T', 'account_rep_name' => 'Jane Doe', 'support_phone' => '1-800-555-0199']);
    }

    public function test_viewer_cannot_create_a_provider(): void
    {
        $viewer = User::factory()->create();

        $this->actingAs($viewer)->postJson('/api/isp-providers', ['name' => 'X'])->assertForbidden();
    }

    public function test_provider_name_must_be_unique(): void
    {
        IspProvider::factory()->create(['name' => 'Lumen']);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->postJson('/api/isp-providers', ['name' => 'Lumen'])
            ->assertStatus(422);
    }

    public function test_a_circuit_can_be_associated_with_a_provider_and_returns_it(): void
    {
        $provider = IspProvider::factory()->create(['name' => 'Spectrum', 'support_phone' => '1-855-555-0177']);
        $site = Site::factory()->create();
        Circuit::factory()->create(['site_id' => $site->id, 'isp_provider_id' => $provider->id]);
        $viewer = User::factory()->create();

        $response = $this->actingAs($viewer)->getJson('/api/circuits');

        $response->assertOk();
        $response->assertJsonPath('0.isp_provider.name', 'Spectrum');
        $response->assertJsonPath('0.isp_provider.support_phone', '1-855-555-0177');
    }
}
