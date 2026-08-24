<?php

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $user1;
    private User $user2;
    private Tenant $tenant1;
    private Tenant $tenant2;

    protected function setUp(): void
    {
        parent::setUp();

        // Create two separate tenants with their own users
        $this->tenant1 = Tenant::factory()->create(['name' => 'Tenant One']);
        $this->tenant2 = Tenant::factory()->create(['name' => 'Tenant Two']);

        $this->user1 = User::factory()->create([
            'email' => 'user1@tenant1.com',
            'current_tenant_id' => $this->tenant1->id,
        ]);
        $this->tenant1->users()->attach($this->user1->id, ['role' => 'owner']);

        $this->user2 = User::factory()->create([
            'email' => 'user2@tenant2.com',
            'current_tenant_id' => $this->tenant2->id,
        ]);
        $this->tenant2->users()->attach($this->user2->id, ['role' => 'owner']);
    }

    public function test_user_can_access_own_tenant(): void
    {
        $response = $this->actingAs($this->user1)
            ->getJson('/api/user');

        $response->assertOk();
        $response->assertJsonPath('current_tenant.id', $this->tenant1->id);
    }

    public function test_user_cannot_switch_to_another_users_tenant(): void
    {
        $response = $this->actingAs($this->user1)
            ->postJson("/api/tenants/{$this->tenant2->id}/switch");

        $response->assertStatus(403)
            ->assertJson(['message' => 'Access denied to this tenant']);
    }

    public function test_user_can_switch_to_own_tenant(): void
    {
        // Add user1 to tenant2 as well
        $this->tenant2->users()->attach($this->user1->id, ['role' => 'member']);

        $response = $this->actingAs($this->user1)
            ->postJson("/api/tenants/{$this->tenant2->id}/switch");

        $response->assertOk()
            ->assertJson(['message' => 'Tenant switched successfully']);

        $this->user1->refresh();
        $this->assertEquals($this->tenant2->id, $this->user1->current_tenant_id);
    }

    public function test_direct_tenant_id_manipulation_cannot_bypass_isolation(): void
    {
        // User1 tries to access tenant2 by directly using its ID
        $response = $this->actingAs($this->user1)
            ->postJson("/api/tenants/{$this->tenant2->id}/switch");

        $response->assertStatus(403);

        // Verify user's tenant hasn't changed
        $this->user1->refresh();
        $this->assertEquals($this->tenant1->id, $this->user1->current_tenant_id);
    }

    public function test_tenant_service_correctly_sets_context(): void
    {
        $tenantService = app(TenantService::class);

        $tenantService->setForUser($this->user1);

        $this->assertTrue($tenantService->check());
        $this->assertEquals($this->tenant1->id, $tenantService->current()->id);
    }

    public function test_tenant_service_prevents_unauthorized_switch(): void
    {
        $tenantService = app(TenantService::class);

        $result = $tenantService->switchTenant($this->user1, $this->tenant2);

        $this->assertFalse($result);
    }

    public function test_user_belongs_to_tenant_check(): void
    {
        $this->assertTrue($this->user1->belongsToTenant($this->tenant1));
        $this->assertFalse($this->user1->belongsToTenant($this->tenant2));
    }

    public function test_tenant_has_user_check(): void
    {
        $this->assertTrue($this->tenant1->hasUser($this->user1));
        $this->assertFalse($this->tenant1->hasUser($this->user2));
    }

    public function test_user_role_in_tenant(): void
    {
        $this->assertEquals('owner', $this->user1->roleInTenant($this->tenant1));
        $this->assertNull($this->user1->roleInTenant($this->tenant2));
    }

    public function test_user_is_owner_check(): void
    {
        $this->assertTrue($this->user1->isOwnerOf($this->tenant1));
        $this->assertFalse($this->user1->isOwnerOf($this->tenant2));

        // Add user1 as member to tenant2
        $this->tenant2->users()->attach($this->user1->id, ['role' => 'member']);
        $this->assertFalse($this->user1->isOwnerOf($this->tenant2));
    }
}
