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

class FormVersionTest extends TestCase
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

    public function test_creating_form_creates_initial_version(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/forms', [
            'title' => 'Test Form',
            'schema' => $this->validSchema(),
        ]);

        $response->assertStatus(201);

        $form = Form::first();
        $this->assertNotNull($form->current_version_id);
        $this->assertEquals(1, $form->versions()->count());
        $this->assertEquals(1, $form->currentVersion->version_number);
    }

    public function test_updating_schema_creates_new_version(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/forms', [
            'title' => 'Test Form',
            'schema' => $this->validSchema(),
        ]);

        $form = Form::first();
        $originalVersionId = $form->current_version_id;

        $newSchema = $this->validSchema();
        $newSchema['sections'][0]['fields'][] = [
            'id' => 'field_2',
            'key' => 'email',
            'type' => 'email',
            'label' => 'Email',
        ];

        $response = $this->actingAs($this->user)->putJson("/api/forms/{$form->id}/schema", [
            'schema' => $newSchema,
        ]);

        $response->assertOk();

        $form->refresh();
        $this->assertNotEquals($originalVersionId, $form->current_version_id);
        $this->assertEquals(2, $form->versions()->count());
        $this->assertEquals(2, $form->currentVersion->version_number);
    }

    public function test_published_version_is_immutable(): void
    {
        $form = Form::factory()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
        ]);

        $version = FormVersion::factory()->create([
            'form_id' => $form->id,
            'created_by' => $this->user->id,
            'version_number' => 1,
            'schema' => $this->validSchema(),
        ]);

        $form->update(['current_version_id' => $version->id]);

        // Publish the form
        $this->actingAs($this->user)->postJson("/api/forms/{$form->id}/publish");

        $version->refresh();
        $this->assertTrue($version->isPublished());
        $this->assertTrue($version->isImmutable());
    }

    public function test_publishing_creates_new_version_if_current_is_published(): void
    {
        $form = Form::factory()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
        ]);

        $version = FormVersion::factory()->published()->create([
            'form_id' => $form->id,
            'created_by' => $this->user->id,
            'version_number' => 1,
            'schema' => $this->validSchema(),
        ]);

        $form->update([
            'current_version_id' => $version->id,
            'status' => Form::STATUS_PUBLISHED,
        ]);

        // Publish again
        $response = $this->actingAs($this->user)->postJson("/api/forms/{$form->id}/publish");

        $response->assertOk();
        $form->refresh();

        $this->assertEquals(2, $form->versions()->count());
    }

    public function test_can_restore_previous_version(): void
    {
        $form = Form::factory()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
        ]);

        $version1 = FormVersion::factory()->create([
            'form_id' => $form->id,
            'created_by' => $this->user->id,
            'version_number' => 1,
            'schema' => $this->validSchema(),
        ]);

        $schema2 = $this->validSchema();
        $schema2['metadata']['title'] = 'Updated Form';

        $version2 = FormVersion::factory()->create([
            'form_id' => $form->id,
            'created_by' => $this->user->id,
            'version_number' => 2,
            'schema' => $schema2,
        ]);

        $form->update(['current_version_id' => $version2->id]);

        // Restore version 1 (using rollback endpoint)
        $response = $this->actingAs($this->user)
            ->postJson("/api/forms/{$form->id}/versions/{$version1->id}/rollback");

        $response->assertOk();

        $form->refresh();
        $this->assertEquals(3, $form->versions()->count());
        $this->assertEquals(3, $form->currentVersion->version_number);
        $this->assertEquals('Test Form', $form->currentVersion->schema['metadata']['title']);
    }

    public function test_version_history_is_preserved(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/forms', [
            'title' => 'Test Form',
            'schema' => $this->validSchema(),
        ]);

        $form = Form::first();

        // Make several updates
        for ($i = 2; $i <= 5; $i++) {
            $schema = $this->validSchema();
            $schema['metadata']['title'] = "Version {$i}";

            $this->actingAs($this->user)->putJson("/api/forms/{$form->id}/schema", [
                'schema' => $schema,
            ]);
        }

        $response = $this->actingAs($this->user)->getJson("/api/forms/{$form->id}/versions");

        $response->assertOk();
        $versions = $response->json('data');

        $this->assertCount(5, $versions);

        // Verify version numbers are sequential
        $versionNumbers = collect($versions)->pluck('version_number')->sort()->values()->toArray();
        $this->assertEquals([1, 2, 3, 4, 5], $versionNumbers);
    }

    public function test_invalid_schema_does_not_create_version(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/forms', [
            'title' => 'Test Form',
            'schema' => $this->validSchema(),
        ]);

        $form = Form::first();
        $originalVersionCount = $form->versions()->count();
        $originalVersionId = $form->current_version_id;

        // Try to update with invalid schema
        $invalidSchema = $this->validSchema();
        $invalidSchema['sections'][0]['fields'][0]['type'] = 'invalid_type';

        $response = $this->actingAs($this->user)->putJson("/api/forms/{$form->id}/schema", [
            'schema' => $invalidSchema,
        ]);

        $response->assertStatus(422);

        $form->refresh();
        $this->assertEquals($originalVersionCount, $form->versions()->count());
        $this->assertEquals($originalVersionId, $form->current_version_id);
    }

    public function test_can_view_specific_version(): void
    {
        $form = Form::factory()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
        ]);

        $version = FormVersion::factory()->create([
            'form_id' => $form->id,
            'created_by' => $this->user->id,
            'version_number' => 1,
            'schema' => $this->validSchema(),
        ]);

        $form->update(['current_version_id' => $version->id]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/forms/{$form->id}/versions/{$version->id}");

        $response->assertOk();
        $response->assertJsonPath('data.version_number', 1);
        $response->assertJsonPath('data.schema.schemaVersion', '1.0');
    }
}
