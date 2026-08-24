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

class FormPublishingTest extends TestCase
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

    private function createFormWithVersion(array $formAttrs = [], array $versionAttrs = []): Form
    {
        $form = Form::factory()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
        ], $formAttrs));

        $version = FormVersion::factory()->create(array_merge([
            'form_id' => $form->id,
            'created_by' => $this->user->id,
            'schema' => $this->validSchema(),
        ], $versionAttrs));

        $form->update(['current_version_id' => $version->id]);

        return $form->fresh();
    }

    // ==================== PUBLISH TESTS ====================

    public function test_can_publish_draft_form(): void
    {
        $form = $this->createFormWithVersion(['status' => Form::STATUS_DRAFT]);

        $response = $this->actingAs($this->user)->postJson("/api/forms/{$form->id}/publish");

        $response->assertOk();
        $response->assertJsonPath('data.status', 'published');

        $form->refresh();
        $this->assertTrue($form->currentVersion->is_published);
    }

    public function test_publishing_validates_schema(): void
    {
        $form = Form::factory()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
            'status' => Form::STATUS_DRAFT,
        ]);

        $invalidSchema = $this->validSchema();
        $invalidSchema['sections'][0]['fields'][0]['type'] = 'invalid';

        $version = FormVersion::factory()->create([
            'form_id' => $form->id,
            'created_by' => $this->user->id,
            'schema' => $invalidSchema,
        ]);

        $form->update(['current_version_id' => $version->id]);

        $response = $this->actingAs($this->user)->postJson("/api/forms/{$form->id}/publish");

        $response->assertStatus(422);
    }

    public function test_cannot_publish_archived_form(): void
    {
        $form = $this->createFormWithVersion(['status' => Form::STATUS_ARCHIVED]);

        $response = $this->actingAs($this->user)->postJson("/api/forms/{$form->id}/publish");

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Cannot publish an archived form. Restore it first.');
    }

    public function test_publishing_already_published_creates_new_version(): void
    {
        $form = $this->createFormWithVersion(
            ['status' => Form::STATUS_PUBLISHED],
            ['is_published' => true, 'published_at' => now()]
        );

        $initialVersionCount = $form->versions()->count();

        $response = $this->actingAs($this->user)->postJson("/api/forms/{$form->id}/publish");

        $response->assertOk();
        $this->assertEquals($initialVersionCount + 1, $form->versions()->count());
    }

    public function test_published_version_is_immutable(): void
    {
        $form = $this->createFormWithVersion(
            ['status' => Form::STATUS_PUBLISHED],
            ['is_published' => true, 'published_at' => now()]
        );

        $publishedVersion = $form->currentVersion;
        $this->assertTrue($publishedVersion->isImmutable());
    }

    // ==================== UNPUBLISH TESTS ====================

    public function test_can_unpublish_published_form(): void
    {
        $form = $this->createFormWithVersion(['status' => Form::STATUS_PUBLISHED]);

        $response = $this->actingAs($this->user)->postJson("/api/forms/{$form->id}/unpublish");

        $response->assertOk();
        $response->assertJsonPath('data.status', 'draft');
    }

    public function test_cannot_unpublish_draft_form(): void
    {
        $form = $this->createFormWithVersion(['status' => Form::STATUS_DRAFT]);

        $response = $this->actingAs($this->user)->postJson("/api/forms/{$form->id}/unpublish");

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Form is not published.');
    }

    public function test_cannot_unpublish_archived_form(): void
    {
        $form = $this->createFormWithVersion(['status' => Form::STATUS_ARCHIVED]);

        $response = $this->actingAs($this->user)->postJson("/api/forms/{$form->id}/unpublish");

        $response->assertStatus(422);
    }

    // ==================== ARCHIVE TESTS ====================

    public function test_can_archive_form(): void
    {
        $form = $this->createFormWithVersion(['status' => Form::STATUS_PUBLISHED]);

        $response = $this->actingAs($this->user)->postJson("/api/forms/{$form->id}/archive");

        $response->assertOk();
        $response->assertJsonPath('data.status', 'archived');
    }

    public function test_can_archive_draft_form(): void
    {
        $form = $this->createFormWithVersion(['status' => Form::STATUS_DRAFT]);

        $response = $this->actingAs($this->user)->postJson("/api/forms/{$form->id}/archive");

        $response->assertOk();
        $response->assertJsonPath('data.status', 'archived');
    }

    // ==================== RESTORE TESTS ====================

    public function test_can_restore_archived_form(): void
    {
        $form = $this->createFormWithVersion(['status' => Form::STATUS_ARCHIVED]);

        $response = $this->actingAs($this->user)->postJson("/api/forms/{$form->id}/restore");

        $response->assertOk();
        $response->assertJsonPath('data.status', 'draft');
    }

    public function test_cannot_restore_non_archived_form(): void
    {
        $form = $this->createFormWithVersion(['status' => Form::STATUS_DRAFT]);

        $response = $this->actingAs($this->user)->postJson("/api/forms/{$form->id}/restore");

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Form is not archived.');
    }

    public function test_restored_form_can_be_published(): void
    {
        $form = $this->createFormWithVersion(['status' => Form::STATUS_ARCHIVED]);

        $this->actingAs($this->user)->postJson("/api/forms/{$form->id}/restore");

        $response = $this->actingAs($this->user)->postJson("/api/forms/{$form->id}/publish");

        $response->assertOk();
        $response->assertJsonPath('data.status', 'published');
    }

    // ==================== DUPLICATE TESTS ====================

    public function test_can_duplicate_form(): void
    {
        $form = $this->createFormWithVersion(['title' => 'Original Form']);

        $response = $this->actingAs($this->user)->postJson("/api/forms/{$form->id}/duplicate", [
            'title' => 'Duplicated Form',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.title', 'Duplicated Form');
        $response->assertJsonPath('data.status', 'draft');

        $this->assertEquals(2, Form::count());
    }

    public function test_duplicate_uses_default_title_if_not_provided(): void
    {
        $form = $this->createFormWithVersion(['title' => 'Original Form']);

        $response = $this->actingAs($this->user)->postJson("/api/forms/{$form->id}/duplicate");

        $response->assertStatus(201);
        $response->assertJsonPath('data.title', 'Original Form (Copy)');
    }

    public function test_duplicate_creates_unique_slug(): void
    {
        $form = $this->createFormWithVersion();

        $response = $this->actingAs($this->user)->postJson("/api/forms/{$form->id}/duplicate");

        $response->assertStatus(201);

        $originalSlug = $form->slug;
        $duplicateSlug = $response->json('data.slug');

        $this->assertNotEquals($originalSlug, $duplicateSlug);
    }

    public function test_duplicate_copies_schema(): void
    {
        $form = $this->createFormWithVersion();

        $response = $this->actingAs($this->user)->postJson("/api/forms/{$form->id}/duplicate");

        $response->assertStatus(201);

        $duplicateId = $response->json('data.id');
        $duplicate = Form::find($duplicateId);

        $this->assertEquals(
            $form->currentVersion->schema['sections'],
            $duplicate->currentVersion->schema['sections']
        );
    }
}
