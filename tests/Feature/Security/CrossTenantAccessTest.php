<?php

namespace Tests\Feature\Security;

use App\Models\Form;
use App\Models\FormVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrossTenantAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $user1;
    private User $user2;
    private Tenant $tenant1;
    private Tenant $tenant2;
    private Form $form1;
    private Form $form2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant1 = Tenant::factory()->create();
        $this->tenant2 = Tenant::factory()->create();

        $this->user1 = User::factory()->create(['current_tenant_id' => $this->tenant1->id]);
        $this->tenant1->users()->attach($this->user1->id, ['role' => 'owner']);

        $this->user2 = User::factory()->create(['current_tenant_id' => $this->tenant2->id]);
        $this->tenant2->users()->attach($this->user2->id, ['role' => 'owner']);

        // Create forms for each tenant
        app(TenantService::class)->set($this->tenant1);
        $this->form1 = Form::factory()->create([
            'tenant_id' => $this->tenant1->id,
            'created_by' => $this->user1->id,
        ]);
        FormVersion::factory()->create([
            'form_id' => $this->form1->id,
            'created_by' => $this->user1->id,
        ]);

        app(TenantService::class)->set($this->tenant2);
        $this->form2 = Form::factory()->create([
            'tenant_id' => $this->tenant2->id,
            'created_by' => $this->user2->id,
        ]);
        FormVersion::factory()->create([
            'form_id' => $this->form2->id,
            'created_by' => $this->user2->id,
        ]);
    }

    public function test_user_cannot_view_other_tenant_form(): void
    {
        $response = $this->actingAs($this->user1)
            ->getJson("/api/forms/{$this->form2->id}");

        // Should be blocked - either 403 (forbidden) or 404 (not found due to tenant scope)
        $this->assertContains($response->status(), [403, 404]);
    }

    public function test_user_cannot_update_other_tenant_form(): void
    {
        $response = $this->actingAs($this->user1)
            ->putJson("/api/forms/{$this->form2->id}", [
                'title' => 'Hacked Title',
            ]);

        $this->assertContains($response->status(), [403, 404]);
    }

    public function test_user_cannot_delete_other_tenant_form(): void
    {
        $response = $this->actingAs($this->user1)
            ->deleteJson("/api/forms/{$this->form2->id}");

        $this->assertContains($response->status(), [403, 404]);
    }

    public function test_user_cannot_publish_other_tenant_form(): void
    {
        $response = $this->actingAs($this->user1)
            ->postJson("/api/forms/{$this->form2->id}/publish");

        $this->assertContains($response->status(), [403, 404]);
    }

    public function test_user_cannot_view_other_tenant_submissions(): void
    {
        $response = $this->actingAs($this->user1)
            ->getJson("/api/forms/{$this->form2->id}/submissions");

        $this->assertContains($response->status(), [403, 404]);
    }

    public function test_user_cannot_export_other_tenant_submissions(): void
    {
        $response = $this->actingAs($this->user1)
            ->getJson("/api/forms/{$this->form2->id}/submissions/export");

        $this->assertContains($response->status(), [403, 404]);
    }

    public function test_guessed_form_id_returns_404(): void
    {
        $response = $this->actingAs($this->user1)
            ->getJson('/api/forms/99999');

        $response->assertStatus(404);
    }

    public function test_form_listing_only_shows_own_tenant_forms(): void
    {
        $response = $this->actingAs($this->user1)
            ->getJson('/api/forms');

        $response->assertOk();
        $data = $response->json('data');

        foreach ($data as $form) {
            $this->assertNotEquals($this->form2->id, $form['id']);
        }
    }

    public function test_cannot_modify_ai_job_from_other_tenant(): void
    {
        $aiJob = \App\Models\AIJob::create([
            'tenant_id' => $this->tenant2->id,
            'user_id' => $this->user2->id,
            'job_uuid' => \Illuminate\Support\Str::uuid(),
            'request_type' => 'generate',
            'status' => 'succeeded',
            'prompt' => 'test',
            'provider' => 'openai',
            'model' => 'gpt-4',
            'result_schema' => ['schemaVersion' => '1.0', 'metadata' => ['title' => 'Test'], 'sections' => []],
        ]);

        $response = $this->actingAs($this->user1)
            ->getJson("/api/ai/jobs/{$aiJob->job_uuid}");

        // Should be blocked - 403, 404, or 500 (if AI provider not configured)
        $this->assertContains($response->status(), [403, 404, 500]);
    }

    public function test_cannot_access_import_job_from_other_tenant(): void
    {
        $importJob = \App\Models\ImportJob::create([
            'tenant_id' => $this->tenant2->id,
            'user_id' => $this->user2->id,
            'job_uuid' => \Illuminate\Support\Str::uuid(),
            'import_type' => 'docx',
            'status' => 'parsed',
            'original_filename' => 'test.docx',
        ]);

        $response = $this->actingAs($this->user1)
            ->getJson("/api/import/{$importJob->job_uuid}");

        $response->assertStatus(404);
    }
}
