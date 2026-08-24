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

    // ==================== CREATE TESTS ====================

    public function test_can_create_form(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/forms', [
            'title' => 'Contact Form',
            'description' => 'A simple contact form',
            'schema' => $this->validSchema(),
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.title', 'Contact Form');
        $response->assertJsonPath('data.status', 'draft');

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

    public function test_can_create_form_with_custom_slug(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/forms', [
            'title' => 'My Form',
            'slug' => 'my-custom-slug',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.slug', 'my-custom-slug');
    }

    public function test_slug_must_be_unique_within_tenant(): void
    {
        Form::factory()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
            'slug' => 'existing-slug',
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/forms', [
            'title' => 'New Form',
            'slug' => 'existing-slug',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['slug']);
    }

    public function test_slug_must_be_safe_format(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/forms', [
            'title' => 'Test',
            'slug' => 'Invalid Slug!',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['slug']);
    }

    public function test_title_is_required(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/forms', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['title']);
    }

    // ==================== LIST TESTS ====================

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

    public function test_can_search_forms(): void
    {
        Form::factory()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
            'title' => 'Contact Form',
        ]);

        Form::factory()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
            'title' => 'Survey Form',
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/forms?search=Contact');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.title', 'Contact Form');
    }

    public function test_can_paginate_forms(): void
    {
        Form::factory()->count(20)->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/forms?per_page=5');

        $response->assertOk();
        $response->assertJsonCount(5, 'data');
        $response->assertJsonPath('meta.per_page', 5);
        $response->assertJsonPath('meta.total', 20);
    }

    // ==================== SHOW TESTS ====================

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
        $response->assertJsonPath('data.id', $form->id);
        $response->assertJsonStructure(['data' => ['current_version']]);
    }

    // ==================== UPDATE TESTS ====================

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
        $response->assertJsonPath('data.title', 'Updated Title');
    }

    public function test_can_update_form_slug(): void
    {
        $form = Form::factory()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
            'slug' => 'old-slug',
        ]);

        $response = $this->actingAs($this->user)->putJson("/api/forms/{$form->id}", [
            'slug' => 'new-slug',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.slug', 'new-slug');
    }

    public function test_cannot_update_slug_to_existing_slug(): void
    {
        Form::factory()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
            'slug' => 'taken-slug',
        ]);

        $form = Form::factory()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
            'slug' => 'my-slug',
        ]);

        $response = $this->actingAs($this->user)->putJson("/api/forms/{$form->id}", [
            'slug' => 'taken-slug',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['slug']);
    }

    // ==================== DELETE TESTS ====================

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

    public function test_deleting_form_deletes_versions(): void
    {
        $form = Form::factory()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
        ]);

        $version = FormVersion::factory()->create([
            'form_id' => $form->id,
            'created_by' => $this->user->id,
        ]);

        $this->actingAs($this->user)->deleteJson("/api/forms/{$form->id}");

        $this->assertDatabaseMissing('form_versions', ['id' => $version->id]);
    }

    // ==================== TENANT ISOLATION TESTS ====================

    public function test_cannot_access_other_tenant_forms(): void
    {
        $otherTenant = Tenant::factory()->create();
        $otherUser = User::factory()->create(['current_tenant_id' => $otherTenant->id]);
        $otherTenant->users()->attach($otherUser->id, ['role' => 'owner']);

        $form = Form::factory()->create([
            'tenant_id' => $otherTenant->id,
            'created_by' => $otherUser->id,
        ]);

        $response = $this->actingAs($this->user)->getJson("/api/forms/{$form->id}");

        $response->assertStatus(404);
    }

    public function test_forms_are_scoped_to_tenant(): void
    {
        Form::factory()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
        ]);

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

    // ==================== VALIDATION TESTS ====================

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

    // ==================== AUTH TESTS ====================

    public function test_unauthenticated_cannot_access_forms(): void
    {
        $response = $this->getJson('/api/forms');
        $response->assertStatus(401);
    }
}
