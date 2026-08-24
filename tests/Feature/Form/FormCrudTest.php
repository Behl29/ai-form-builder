<?php

namespace Tests\Feature\Form;

use App\Models\Form;
use App\Models\FormVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FormSchema\FormSchemaContract;
use App\Services\TenantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FormCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['current_tenant_id' => $this->tenant->id]);
        $this->tenant->users()->attach($this->user->id, ['role' => 'owner']);

        app(TenantService::class)->set($this->tenant);
    }

    private function validSchema(): array
    {
        return [
            'schemaVersion' => FormSchemaContract::SCHEMA_VERSION,
            'metadata' => ['title' => 'Test Form'],
            'settings' => ['submitButtonText' => 'Submit'],
            'sections' => [
                [
                    'id' => 'section_1',
                    'title' => 'Section 1',
                    'fields' => [
                        [
                            'id' => 'field_1',
                            'key' => 'name',
                            'type' => 'text',
                            'label' => 'Name',
                        ],
                    ],
                ],
            ],
        ];
    }

    public function test_can_create_form(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/forms', [
            'title' => 'Contact Form',
            'description' => 'A simple contact form',
            'schema' => $this->validSchema(),
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('title', 'Contact Form');
        $response->assertJsonPath('status', 'draft');

        $this->assertDatabaseHas('forms', [
            'title' => 'Contact Form',
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
        ]);
    }

    public function test_can_create_form_with_default_schema(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/forms', [
            'title' => 'Simple Form',
        ]);

        $response->assertStatus(201);

        $form = Form::first();
        $this->assertNotNull($form->currentVersion);
        $this->assertEquals('1.0', $form->currentVersion->schema['schemaVersion']);
    }

    public function test_can_list_forms(): void
    {
        Form::factory()->count(3)->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/forms');

        $response->assertOk();
        $response->assertJsonCount(3, 'data');
    }

    public function test_can_filter_forms_by_status(): void
    {
        Form::factory()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
            'status' => Form::STATUS_DRAFT,
        ]);

        Form::factory()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
            'status' => Form::STATUS_PUBLISHED,
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/forms?status=draft');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    }

    public function test_can_show_form(): void
    {
        $form = Form::factory()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
        ]);

        $version = FormVersion::factory()->create([
            'form_id' => $form->id,
            'created_by' => $this->user->id,
            'schema' => $this->validSchema(),
        ]);

        $form->update(['current_version_id' => $version->id]);

        $response = $this->actingAs($this->user)->getJson("/api/forms/{$form->id}");

        $response->assertOk();
        $response->assertJsonPath('id', $form->id);
        $response->assertJsonStructure(['current_version']);
    }

    public function test_can_update_form_metadata(): void
    {
        $form = Form::factory()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
            'title' => 'Original Title',
        ]);

        $response = $this->actingAs($this->user)->putJson("/api/forms/{$form->id}", [
            'title' => 'Updated Title',
            'description' => 'New description',
        ]);

        $response->assertOk();
        $response->assertJsonPath('title', 'Updated Title');
    }

    public function test_can_delete_form(): void
    {
        $form = Form::factory()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)->deleteJson("/api/forms/{$form->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('forms', ['id' => $form->id]);
    }

    public function test_can_publish_form(): void
    {
        $form = Form::factory()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
            'status' => Form::STATUS_DRAFT,
        ]);

        $version = FormVersion::factory()->create([
            'form_id' => $form->id,
            'created_by' => $this->user->id,
            'schema' => $this->validSchema(),
        ]);

        $form->update(['current_version_id' => $version->id]);

        $response = $this->actingAs($this->user)->postJson("/api/forms/{$form->id}/publish");

        $response->assertOk();
        $response->assertJsonPath('form.status', 'published');

        $version->refresh();
        $this->assertTrue($version->is_published);
    }

    public function test_can_archive_form(): void
    {
        $form = Form::factory()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
            'status' => Form::STATUS_PUBLISHED,
        ]);

        $response = $this->actingAs($this->user)->postJson("/api/forms/{$form->id}/archive");

        $response->assertOk();
        $response->assertJsonPath('form.status', 'archived');
    }

    public function test_can_restore_archived_form(): void
    {
        $form = Form::factory()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
            'status' => Form::STATUS_ARCHIVED,
        ]);

        $response = $this->actingAs($this->user)->postJson("/api/forms/{$form->id}/restore");

        $response->assertOk();
        $response->assertJsonPath('form.status', 'draft');
    }

    public function test_can_duplicate_form(): void
    {
        $form = Form::factory()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
            'title' => 'Original Form',
        ]);

        $version = FormVersion::factory()->create([
            'form_id' => $form->id,
            'created_by' => $this->user->id,
            'schema' => $this->validSchema(),
        ]);

        $form->update(['current_version_id' => $version->id]);

        $response = $this->actingAs($this->user)->postJson("/api/forms/{$form->id}/duplicate", [
            'title' => 'Duplicated Form',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('form.title', 'Duplicated Form');

        $this->assertEquals(2, Form::count());
    }

    public function test_cannot_access_other_tenant_forms(): void
    {
        $otherTenant = Tenant::factory()->create();
        $otherUser = User::factory()->create(['current_tenant_id' => $otherTenant->id]);
        $otherTenant->users()->attach($otherUser->id, ['role' => 'owner']);

        $form = Form::factory()->create([
            'tenant_id' => $otherTenant->id,
            'created_by' => $otherUser->id,
        ]);

        // Form is not found due to tenant scope (404) - this is correct behavior
        // The global scope filters out forms from other tenants
        $response = $this->actingAs($this->user)->getJson("/api/forms/{$form->id}");

        $response->assertStatus(404);
    }

    public function test_forms_are_scoped_to_tenant(): void
    {
        // Create form for current tenant
        Form::factory()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
        ]);

        // Create form for other tenant
        $otherTenant = Tenant::factory()->create();
        $otherUser = User::factory()->create(['current_tenant_id' => $otherTenant->id]);
        Form::factory()->create([
            'tenant_id' => $otherTenant->id,
            'created_by' => $otherUser->id,
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/forms');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    }

    public function test_invalid_schema_returns_validation_errors(): void
    {
        $invalidSchema = $this->validSchema();
        $invalidSchema['sections'][0]['fields'][0]['type'] = 'invalid_type';

        $response = $this->actingAs($this->user)->postJson('/api/forms', [
            'title' => 'Test Form',
            'schema' => $invalidSchema,
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['message', 'errors']);
    }

    public function test_unauthenticated_cannot_access_forms(): void
    {
        $response = $this->getJson('/api/forms');
        $response->assertStatus(401);
    }
}
