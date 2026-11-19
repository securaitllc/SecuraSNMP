<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessControlOracleTest extends TestCase
{
    use RefreshDatabase;

    /** ACL-02: a viewer is refused an admin object before it is looked up, so a missing id is not a 404. */
    public function test_acl02_viewer_gets_403_not_404_on_missing_admin_object(): void
    {
        $viewer = User::factory()->create();

        $this->actingAs($viewer)->putJson('/api/notification-channels/999999', ['name' => 'x'])->assertForbidden();
        $this->actingAs($viewer)->putJson('/api/ipam/prefixes/999999', [])->assertForbidden();
        $this->actingAs($viewer)->getJson('/api/osint/cases/999999')->assertForbidden();
    }

    /** ACL-02: the gate still lets an admin through to a normal 404 for a missing object. */
    public function test_acl02_admin_still_gets_404_on_missing_object(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->putJson('/api/notification-channels/999999', ['name' => 'x'])->assertNotFound();
    }

    /** ACL-02: a denied write is still recorded in the audit trail. */
    public function test_acl02_denied_write_is_still_audited(): void
    {
        $viewer = User::factory()->create();

        $this->actingAs($viewer)->putJson('/api/notification-channels/999999', ['name' => 'x'])->assertForbidden();

        $this->assertDatabaseHas('audit_logs', ['user_id' => $viewer->id, 'method' => 'PUT', 'status' => 403]);
    }
}
