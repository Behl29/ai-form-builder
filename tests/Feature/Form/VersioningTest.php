<?php

namespace Tests\Feature\Form;

use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\FormVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Services\VersionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VersioningTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Tenant $tenant;
    private Form $form;
    private VersionService $versionService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create();
        $this->user->tenants()->attach($this->tenant);

        $this->form = Form::factory()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
        ]);

        $this->versionService = new VersionService();
    }

    private function actingAsUser(): self
    {
        return $this->actingAs($this->user)
            ->withHeaders(['X-Tenant-ID' => $this->tenant->id]);
    }

    private function createSchema(array $fields = []): array
    {
        return [
            'sections' => [
                [
                    'id' => 'section_1',
                    'title' => 'Test Section',
                    'fields' => $fields ?: [
                        ['key' => 'name', 'type' => 'text', 'label' => 'Name', 'required' => true],
                        ['key' => 'email', 'type' => 'email', 'label' => 'Email'],
                    ],
                ],
            ],
        ];
    }

    // ==================== VERSION CREATION TESTS ====================

    public function test_creates_initial_version(): void
    {
        $schema = $this->createSchema();
        $version = $this->versionService->createVersion(
            $this->form,
            $this->user,
            $schema,
            FormVersion::CHANGE_CREATED
        );

        $this->assertEquals(1, $version->version_number);
        $this->assertEquals(FormVersion::CHANGE_CREATED, $version->change_type);
        $this->assertEquals($schema, $version->schema);
        $this->assertEquals($this->user->id, $version->created_by);
        $this->assertFalse($version->is_published);
    }

    public function test_increments_version_number(): void
    {
        $schema1 = $this->createSchema();
        $version1 = $this->versionService->createVersion($this->form, $this->user, $schema1);

        $schema2 = $this->createSchema([
            ['key' => 'name', 'type' => 'text', 'label' => 'Full Name'],
        ]);
        $version2 = $this->versionService->createVersion($this->form, $this->user, $schema2);

        $this->assertEquals(1, $version1->version_number);
        $this->assertEquals(2, $version2->version_number);
    }

    public function test_versions_are_immutable_after_publish(): void
    {
        $schema = $this->createSchema();
        $version = $this->versionService->createVersion($this->form, $this->user, $schema);
        $version->publish();

        $this->assertTrue($version->isImmutable());
        $this->assertTrue($version->is_published);
        $this->assertNotNull($version->published_at);
    }

    public function test_generates_change_summary(): void
    {
        $schema1 = $this->createSchema([
            ['key' => 'name', 'type' => 'text', 'label' => 'Name'],
            ['key' => 'email', 'type' => 'email', 'label' => 'Email'],
        ]);
        $this->versionService->createVersion($this->form, $this->user, $schema1);
        $this->form->refresh(); // Refresh to get updated current_version_id

        $schema2 = $this->createSchema([
            ['key' => 'name', 'type' => 'text', 'label' => 'Full Name'], // Modified
            ['key' => 'phone', 'type' => 'phone', 'label' => 'Phone'], // Added (email removed)
        ]);
        $version2 = $this->versionService->createVersion($this->form, $this->user, $schema2);

        $summary = $version2->change_summary;
        $this->assertIsArray($summary);
        $this->assertArrayHasKey('fields_added', $summary);
        $this->assertArrayHasKey('fields_removed', $summary);
        $this->assertArrayHasKey('fields_modified', $summary);
    }

    // ==================== ROLLBACK TESTS ====================

    public function test_rollback_creates_new_version(): void
    {
        $schema1 = $this->createSchema([['key' => 'name', 'type' => 'text', 'label' => 'Name']]);
        $version1 = $this->versionService->createVersion($this->form, $this->user, $schema1);

        $schema2 = $this->createSchema([['key' => 'email', 'type' => 'email', 'label' => 'Email']]);
        $this->versionService->createVersion($this->form, $this->user, $schema2);

        $rolledBack = $this->versionService->rollback($this->form, $version1, $this->user);

        $this->assertEquals(3, $rolledBack->version_number);
        $this->assertEquals(FormVersion::CHANGE_RESTORED, $rolledBack->change_type);
        $this->assertEquals($version1->id, $rolledBack->restored_from_version_id);
        $this->assertEquals($schema1, $rolledBack->schema);
    }

    public function test_rollback_does_not_mutate_old_version(): void
    {
        $schema1 = $this->createSchema([['key' => 'name', 'type' => 'text', 'label' => 'Name']]);
        $version1 = $this->versionService->createVersion($this->form, $this->user, $schema1);
        $originalSchema = $version1->schema;

        $schema2 = $this->createSchema([['key' => 'email', 'type' => 'email', 'label' => 'Email']]);
        $this->versionService->createVersion($this->form, $this->user, $schema2);

        $this->versionService->rollback($this->form, $version1, $this->user);

        $version1->refresh();
        $this->assertEquals($originalSchema, $version1->schema);
        $this->assertEquals(1, $version1->version_number);
    }

    public function test_rollback_api_endpoint(): void
    {
        $schema1 = $this->createSchema();
        $version1 = FormVersion::factory()->create([
            'form_id' => $this->form->id,
            'created_by' => $this->user->id,
            'version_number' => 1,
            'schema' => $schema1,
        ]);

        $this->form->update(['current_version_id' => $version1->id]);

        $schema2 = $this->createSchema([['key' => 'phone', 'type' => 'phone', 'label' => 'Phone']]);
        $version2 = FormVersion::factory()->create([
            'form_id' => $this->form->id,
            'created_by' => $this->user->id,
            'version_number' => 2,
            'schema' => $schema2,
        ]);

        $this->form->update(['current_version_id' => $version2->id]);

        $response = $this->actingAsUser()
            ->postJson("/api/forms/{$this->form->id}/versions/{$version1->id}/rollback");

        $response->assertOk();
        $this->assertStringContainsString('Rolled back', $response->json('message'));
        $this->assertEquals(3, $response->json('data.version_number'));
    }

    // ==================== VERSION COMPARISON TESTS ====================

    public function test_compares_added_fields(): void
    {
        $schema1 = $this->createSchema([['key' => 'name', 'type' => 'text', 'label' => 'Name']]);
        $version1 = $this->versionService->createVersion($this->form, $this->user, $schema1);

        $schema2 = $this->createSchema([
            ['key' => 'name', 'type' => 'text', 'label' => 'Name'],
            ['key' => 'email', 'type' => 'email', 'label' => 'Email'],
        ]);
        $version2 = $this->versionService->createVersion($this->form, $this->user, $schema2);

        $comparison = $this->versionService->compareVersions($version1, $version2);

        $this->assertArrayHasKey('email', $comparison['fields']['added']);
        $this->assertEmpty($comparison['fields']['removed']);
    }

    public function test_compares_removed_fields(): void
    {
        $schema1 = $this->createSchema([
            ['key' => 'name', 'type' => 'text', 'label' => 'Name'],
            ['key' => 'email', 'type' => 'email', 'label' => 'Email'],
        ]);
        $version1 = $this->versionService->createVersion($this->form, $this->user, $schema1);

        $schema2 = $this->createSchema([['key' => 'name', 'type' => 'text', 'label' => 'Name']]);
        $version2 = $this->versionService->createVersion($this->form, $this->user, $schema2);

        $comparison = $this->versionService->compareVersions($version1, $version2);

        $this->assertArrayHasKey('email', $comparison['fields']['removed']);
        $this->assertEmpty($comparison['fields']['added']);
    }

    public function test_compares_modified_labels(): void
    {
        $schema1 = $this->createSchema([['key' => 'name', 'type' => 'text', 'label' => 'Name']]);
        $version1 = $this->versionService->createVersion($this->form, $this->user, $schema1);

        $schema2 = $this->createSchema([['key' => 'name', 'type' => 'text', 'label' => 'Full Name']]);
        $version2 = $this->versionService->createVersion($this->form, $this->user, $schema2);

        $comparison = $this->versionService->compareVersions($version1, $version2);

        $this->assertArrayHasKey('name', $comparison['fields']['modified']);
        $this->assertArrayHasKey('label', $comparison['fields']['modified']['name']['changes']);
    }

    public function test_compares_modified_validation(): void
    {
        $schema1 = $this->createSchema([['key' => 'name', 'type' => 'text', 'label' => 'Name', 'required' => false]]);
        $version1 = $this->versionService->createVersion($this->form, $this->user, $schema1);

        $schema2 = $this->createSchema([['key' => 'name', 'type' => 'text', 'label' => 'Name', 'required' => true]]);
        $version2 = $this->versionService->createVersion($this->form, $this->user, $schema2);

        $comparison = $this->versionService->compareVersions($version1, $version2);

        $this->assertArrayHasKey('name', $comparison['fields']['modified']);
        $this->assertArrayHasKey('required', $comparison['fields']['modified']['name']['changes']);
    }

    public function test_compares_modified_options(): void
    {
        $schema1 = $this->createSchema([
            [
                'key' => 'color',
                'type' => 'select',
                'label' => 'Color',
                'options' => [['value' => 'red', 'label' => 'Red']],
            ],
        ]);
        $version1 = $this->versionService->createVersion($this->form, $this->user, $schema1);

        $schema2 = $this->createSchema([
            [
                'key' => 'color',
                'type' => 'select',
                'label' => 'Color',
                'options' => [['value' => 'red', 'label' => 'Red'], ['value' => 'blue', 'label' => 'Blue']],
            ],
        ]);
        $version2 = $this->versionService->createVersion($this->form, $this->user, $schema2);

        $comparison = $this->versionService->compareVersions($version1, $version2);

        $this->assertArrayHasKey('color', $comparison['fields']['modified']);
        $this->assertArrayHasKey('options', $comparison['fields']['modified']['color']['changes']);
    }

    public function test_compares_sections(): void
    {
        $schema1 = [
            'sections' => [
                ['id' => 'section_1', 'title' => 'Section 1', 'fields' => []],
            ],
        ];
        $version1 = $this->versionService->createVersion($this->form, $this->user, $schema1);

        $schema2 = [
            'sections' => [
                ['id' => 'section_1', 'title' => 'Section 1', 'fields' => []],
                ['id' => 'section_2', 'title' => 'Section 2', 'fields' => []],
            ],
        ];
        $version2 = $this->versionService->createVersion($this->form, $this->user, $schema2);

        $comparison = $this->versionService->compareVersions($version1, $version2);

        $this->assertArrayHasKey('section_2', $comparison['sections']['added']);
    }

    public function test_compare_api_endpoint(): void
    {
        $version1 = FormVersion::factory()->create([
            'form_id' => $this->form->id,
            'created_by' => $this->user->id,
            'version_number' => 1,
            'schema' => $this->createSchema([['key' => 'name', 'type' => 'text', 'label' => 'Name']]),
        ]);

        $version2 = FormVersion::factory()->create([
            'form_id' => $this->form->id,
            'created_by' => $this->user->id,
            'version_number' => 2,
            'schema' => $this->createSchema([['key' => 'email', 'type' => 'email', 'label' => 'Email']]),
        ]);

        $response = $this->actingAsUser()
            ->postJson("/api/forms/{$this->form->id}/versions/compare", [
                'old_version_id' => $version1->id,
                'new_version_id' => $version2->id,
            ]);

        $response->assertOk();
        $this->assertArrayHasKey('fields', $response->json('data'));
    }

    // ==================== VERSION HISTORY TESTS ====================

    public function test_gets_version_history(): void
    {
        // Create versions manually with different version numbers
        FormVersion::factory()->create([
            'form_id' => $this->form->id,
            'created_by' => $this->user->id,
            'version_number' => 1,
        ]);
        FormVersion::factory()->create([
            'form_id' => $this->form->id,
            'created_by' => $this->user->id,
            'version_number' => 2,
        ]);
        FormVersion::factory()->create([
            'form_id' => $this->form->id,
            'created_by' => $this->user->id,
            'version_number' => 3,
        ]);

        $response = $this->actingAsUser()
            ->getJson("/api/forms/{$this->form->id}/versions");

        $response->assertOk();
        $this->assertCount(3, $response->json('data'));
    }

    public function test_version_history_includes_metadata(): void
    {
        $version = FormVersion::factory()->create([
            'form_id' => $this->form->id,
            'created_by' => $this->user->id,
            'change_type' => FormVersion::CHANGE_UPDATED,
            'change_summary' => ['fields_added' => 1],
        ]);

        $response = $this->actingAsUser()
            ->getJson("/api/forms/{$this->form->id}/versions");

        $response->assertOk();
        $versionData = $response->json('data.0');
        $this->assertArrayHasKey('change_type', $versionData);
        $this->assertArrayHasKey('change_summary', $versionData);
        $this->assertArrayHasKey('created_by', $versionData);
    }

    // ==================== SUBMISSION VERSION INTEGRITY TESTS ====================

    public function test_submission_retains_form_version(): void
    {
        $version1 = FormVersion::factory()->create([
            'form_id' => $this->form->id,
            'created_by' => $this->user->id,
            'version_number' => 1,
            'schema' => $this->createSchema(),
            'is_published' => true,
        ]);

        $this->form->update([
            'current_version_id' => $version1->id,
            'status' => Form::STATUS_PUBLISHED,
        ]);

        $submission = FormSubmission::create([
            'form_id' => $this->form->id,
            'form_version_id' => $version1->id,
            'data' => ['name' => 'Test'],
            'status' => FormSubmission::STATUS_COMPLETED,
        ]);

        // Create new version
        $version2 = FormVersion::factory()->create([
            'form_id' => $this->form->id,
            'created_by' => $this->user->id,
            'version_number' => 2,
            'schema' => $this->createSchema([['key' => 'email', 'type' => 'email', 'label' => 'Email']]),
        ]);

        $this->form->update(['current_version_id' => $version2->id]);

        // Submission should still reference version 1
        $submission->refresh();
        $this->assertEquals($version1->id, $submission->form_version_id);
        $this->assertEquals($version1->schema, $submission->formVersion->schema);
    }

    public function test_historical_submission_shows_original_schema(): void
    {
        $originalSchema = $this->createSchema([
            ['key' => 'old_field', 'type' => 'text', 'label' => 'Old Field'],
        ]);

        $version1 = FormVersion::factory()->create([
            'form_id' => $this->form->id,
            'created_by' => $this->user->id,
            'version_number' => 1,
            'schema' => $originalSchema,
            'is_published' => true,
        ]);

        $submission = FormSubmission::create([
            'form_id' => $this->form->id,
            'form_version_id' => $version1->id,
            'data' => ['old_field' => 'Test Value'],
            'status' => FormSubmission::STATUS_COMPLETED,
        ]);

        // Verify submission's version has original schema
        $this->assertEquals($originalSchema, $submission->formVersion->schema);
        $this->assertEquals('old_field', $submission->formVersion->schema['sections'][0]['fields'][0]['key']);
    }
}
