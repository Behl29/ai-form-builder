<?php

namespace Tests\Feature\Security;

use App\Models\Form;
use App\Models\FormVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_public_submission_rate_limit(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['current_tenant_id' => $tenant->id]);
        $tenant->users()->attach($user->id, ['role' => 'owner']);

        app(TenantService::class)->set($tenant);

        $form = Form::factory()->create([
            'tenant_id' => $tenant->id,
            'created_by' => $user->id,
            'status' => Form::STATUS_PUBLISHED,
        ]);

        $schema = [
            'schemaVersion' => '1.0',
            'metadata' => ['title' => 'Test'],
            'sections' => [
                [
                    'id' => 'section_1',
                    'title' => 'Section',
                    'fields' => [
                        ['id' => 'f1', 'key' => 'name', 'type' => 'text', 'label' => 'Name'],
                    ],
                ],
            ],
        ];

        $version = FormVersion::factory()->create([
            'form_id' => $form->id,
            'created_by' => $user->id,
            'schema' => $schema,
            'is_published' => true,
        ]);
        $form->update(['current_version_id' => $version->id]);

        // Make 10 requests (the limit)
        for ($i = 0; $i < 10; $i++) {
            $response = $this->postJson("/api/public/forms/{$form->slug}/submit", [
                'name' => "Test {$i}",
            ]);
            // First 10 should succeed or fail for other reasons, not rate limit
            $this->assertNotEquals(429, $response->status());
        }

        // 11th request should be rate limited
        $response = $this->postJson("/api/public/forms/{$form->slug}/submit", [
            'name' => 'Test 11',
        ]);

        $response->assertStatus(429);
        $response->assertJsonStructure(['message', 'retry_after']);
    }

    public function test_auth_rate_limit(): void
    {
        // Make 5 login attempts (the limit)
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', [
                'email' => 'test@example.com',
                'password' => 'wrong',
            ]);
        }

        // 6th attempt should be rate limited
        $response = $this->postJson('/api/login', [
            'email' => 'test@example.com',
            'password' => 'wrong',
        ]);

        $response->assertStatus(429);
    }

    public function test_rate_limit_headers_present(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['current_tenant_id' => $tenant->id]);
        $tenant->users()->attach($user->id, ['role' => 'owner']);

        app(TenantService::class)->set($tenant);

        $form = Form::factory()->create([
            'tenant_id' => $tenant->id,
            'created_by' => $user->id,
            'status' => Form::STATUS_PUBLISHED,
        ]);

        $version = FormVersion::factory()->create([
            'form_id' => $form->id,
            'created_by' => $user->id,
            'is_published' => true,
        ]);
        $form->update(['current_version_id' => $version->id]);

        $response = $this->getJson("/api/public/forms/{$form->slug}");

        $response->assertHeader('X-RateLimit-Limit');
        $response->assertHeader('X-RateLimit-Remaining');
    }

    public function test_ai_generation_rate_limit(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['current_tenant_id' => $tenant->id]);
        $tenant->users()->attach($user->id, ['role' => 'owner']);

        // Make 5 AI generation requests (the limit)
        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($user)->postJson('/api/ai/generate', [
                'prompt' => 'Create a contact form',
            ]);
        }

        // 6th request should be rate limited
        $response = $this->actingAs($user)->postJson('/api/ai/generate', [
            'prompt' => 'Create another form',
        ]);

        $response->assertStatus(429);
    }
}
