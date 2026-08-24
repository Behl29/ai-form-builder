<?php

namespace Tests\Feature\Form;

use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\FormVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FormSchema\FormSchemaContract;
use App\Services\TenantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicFormTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Tenant $tenant;
    private Form $form;
    private FormVersion $version;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['current_tenant_id' => $this->tenant->id]);
        $this->tenant->users()->attach($this->user->id, ['role' => 'owner']);

        app(TenantService::class)->set($this->tenant);

        Storage::fake('local');
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
                            'required' => true,
                        ],
                        [
                            'id' => 'field_2',
                            'key' => 'email',
                            'type' => 'email',
                            'label' => 'Email',
                            'required' => true,
                        ],
                        [
                            'id' => 'field_3',
                            'key' => 'age',
                            'type' => 'number',
                            'label' => 'Age',
                            'min' => 18,
                            'max' => 100,
                        ],
                    ],
                ],
            ],
        ];
    }

    private function createPublishedForm(): void
    {
        $this->form = Form::factory()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
            'status' => Form::STATUS_PUBLISHED,
            'slug' => 'test-form',
        ]);

        $this->version = FormVersion::factory()->create([
            'form_id' => $this->form->id,
            'created_by' => $this->user->id,
            'schema' => $this->validSchema(),
            'is_published' => true,
            'published_at' => now(),
        ]);

        $this->form->update(['current_version_id' => $this->version->id]);
    }

    // ==================== PUBLIC FORM ACCESS TESTS ====================

    public function test_can_access_published_form(): void
    {
        $this->createPublishedForm();

        $response = $this->getJson('/api/public/forms/test-form');

        $response->assertOk();
        // The title comes from the form model, not the schema
        $response->assertJsonStructure(['data' => ['title', 'schema']]);
    }

    public function test_cannot_access_unpublished_form(): void
    {
        $form = Form::factory()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
            'status' => Form::STATUS_DRAFT,
            'slug' => 'draft-form',
        ]);

        $response = $this->getJson('/api/public/forms/draft-form');

        $response->assertStatus(404);
    }

    public function test_cannot_access_archived_form(): void
    {
        $form = Form::factory()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
            'status' => Form::STATUS_ARCHIVED,
            'slug' => 'archived-form',
        ]);

        $response = $this->getJson('/api/public/forms/archived-form');

        $response->assertStatus(404);
    }

    public function test_cannot_access_nonexistent_form(): void
    {
        $response = $this->getJson('/api/public/forms/nonexistent');

        $response->assertStatus(404);
    }

    // ==================== SUBMISSION TESTS ====================

    public function test_can_submit_valid_data(): void
    {
        $this->createPublishedForm();

        $response = $this->postJson('/api/public/forms/test-form/submit', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'age' => 25,
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure(['message', 'data' => ['submission_id', 'submitted_at']]);

        $this->assertDatabaseHas('form_submissions', [
            'form_id' => $this->form->id,
            'form_version_id' => $this->version->id,
        ]);
    }

    public function test_cannot_submit_to_unpublished_form(): void
    {
        $form = Form::factory()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
            'status' => Form::STATUS_DRAFT,
            'slug' => 'draft-form',
        ]);

        $response = $this->postJson('/api/public/forms/draft-form/submit', [
            'name' => 'John Doe',
        ]);

        $response->assertStatus(404);
    }

    public function test_required_field_validation(): void
    {
        $this->createPublishedForm();

        $response = $this->postJson('/api/public/forms/test-form/submit', [
            'age' => 25,
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['name', 'email']]);
    }

    public function test_email_validation(): void
    {
        $this->createPublishedForm();

        $response = $this->postJson('/api/public/forms/test-form/submit', [
            'name' => 'John Doe',
            'email' => 'invalid-email',
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['email']]);
    }

    public function test_number_min_max_validation(): void
    {
        $this->createPublishedForm();

        $response = $this->postJson('/api/public/forms/test-form/submit', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'age' => 15, // Below min of 18
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['age']]);
    }

    public function test_duplicate_submission_protection(): void
    {
        $this->createPublishedForm();

        $data = [
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ];

        // First submission
        $response1 = $this->postJson('/api/public/forms/test-form/submit', $data);
        $response1->assertStatus(201);

        // Immediate duplicate
        $response2 = $this->postJson('/api/public/forms/test-form/submit', $data);
        $response2->assertStatus(422);
        $response2->assertJsonPath('message', 'Duplicate submission detected. Please wait before submitting again.');
    }

    // ==================== FILE UPLOAD TESTS ====================

    public function test_can_upload_file(): void
    {
        $schema = $this->validSchema();
        $schema['sections'][0]['fields'][] = [
            'id' => 'field_4',
            'key' => 'resume',
            'type' => 'file',
            'label' => 'Resume',
            'accept' => ['.pdf', '.doc'],
            'maxSize' => 5 * 1024 * 1024, // 5MB
        ];

        $this->form = Form::factory()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
            'status' => Form::STATUS_PUBLISHED,
            'slug' => 'file-form',
        ]);

        $this->version = FormVersion::factory()->create([
            'form_id' => $this->form->id,
            'created_by' => $this->user->id,
            'schema' => $schema,
            'is_published' => true,
        ]);

        $this->form->update(['current_version_id' => $this->version->id]);

        $file = UploadedFile::fake()->create('resume.pdf', 100, 'application/pdf');

        $response = $this->postJson('/api/public/forms/file-form/submit', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'resume' => [$file],
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('submission_files', [
            'field_key' => 'resume',
            'original_name' => 'resume.pdf',
        ]);
    }

    public function test_file_extension_validation(): void
    {
        $schema = $this->validSchema();
        $schema['sections'][0]['fields'][] = [
            'id' => 'field_4',
            'key' => 'resume',
            'type' => 'file',
            'label' => 'Resume',
            'accept' => ['.pdf'],
            'required' => true,
        ];

        $this->form = Form::factory()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
            'status' => Form::STATUS_PUBLISHED,
            'slug' => 'file-form',
        ]);

        $this->version = FormVersion::factory()->create([
            'form_id' => $this->form->id,
            'created_by' => $this->user->id,
            'schema' => $schema,
            'is_published' => true,
        ]);

        $this->form->update(['current_version_id' => $this->version->id]);

        $file = UploadedFile::fake()->create('resume.exe', 100, 'application/x-msdownload');

        $response = $this->postJson('/api/public/forms/file-form/submit', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'resume' => [$file],
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['resume']]);
    }

    public function test_file_size_validation(): void
    {
        $schema = $this->validSchema();
        $schema['sections'][0]['fields'][] = [
            'id' => 'field_4',
            'key' => 'resume',
            'type' => 'file',
            'label' => 'Resume',
            'maxSize' => 1024, // 1KB
            'required' => true,
        ];

        $this->form = Form::factory()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
            'status' => Form::STATUS_PUBLISHED,
            'slug' => 'file-form',
        ]);

        $this->version = FormVersion::factory()->create([
            'form_id' => $this->form->id,
            'created_by' => $this->user->id,
            'schema' => $schema,
            'is_published' => true,
        ]);

        $this->form->update(['current_version_id' => $this->version->id]);

        $file = UploadedFile::fake()->create('resume.pdf', 100, 'application/pdf'); // 100KB

        $response = $this->postJson('/api/public/forms/file-form/submit', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'resume' => [$file],
        ]);

        $response->assertStatus(422);
    }

    // ==================== CONDITIONAL LOGIC TESTS ====================

    public function test_conditional_required_field(): void
    {
        $schema = $this->validSchema();
        $schema['sections'][0]['fields'][] = [
            'id' => 'field_4',
            'key' => 'has_company',
            'type' => 'checkbox',
            'label' => 'Do you have a company?',
        ];
        $schema['sections'][0]['fields'][] = [
            'id' => 'field_5',
            'key' => 'company_name',
            'type' => 'text',
            'label' => 'Company Name',
            'conditions' => [
                [
                    'field' => 'has_company',
                    'operator' => 'equals',
                    'value' => true,
                    'action' => 'require',
                ],
            ],
        ];

        $this->form = Form::factory()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
            'status' => Form::STATUS_PUBLISHED,
            'slug' => 'conditional-form',
        ]);

        $this->version = FormVersion::factory()->create([
            'form_id' => $this->form->id,
            'created_by' => $this->user->id,
            'schema' => $schema,
            'is_published' => true,
        ]);

        $this->form->update(['current_version_id' => $this->version->id]);

        // Without checkbox checked - should pass
        $response1 = $this->postJson('/api/public/forms/conditional-form/submit', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'has_company' => false,
        ]);
        $response1->assertStatus(201);

        // With checkbox checked but no company name - should fail
        $response2 = $this->postJson('/api/public/forms/conditional-form/submit', [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'has_company' => true,
        ]);
        $response2->assertStatus(422);
        $response2->assertJsonStructure(['errors' => ['company_name']]);
    }

    public function test_conditional_hidden_field_not_validated(): void
    {
        $schema = $this->validSchema();
        $schema['sections'][0]['fields'][] = [
            'id' => 'field_4',
            'key' => 'show_extra',
            'type' => 'checkbox',
            'label' => 'Show extra field',
        ];
        $schema['sections'][0]['fields'][] = [
            'id' => 'field_5',
            'key' => 'extra_field',
            'type' => 'text',
            'label' => 'Extra Field',
            'required' => true,
            'conditions' => [
                [
                    'field' => 'show_extra',
                    'operator' => 'equals',
                    'value' => true,
                    'action' => 'show',
                ],
            ],
        ];

        $this->form = Form::factory()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
            'status' => Form::STATUS_PUBLISHED,
            'slug' => 'hidden-form',
        ]);

        $this->version = FormVersion::factory()->create([
            'form_id' => $this->form->id,
            'created_by' => $this->user->id,
            'schema' => $schema,
            'is_published' => true,
        ]);

        $this->form->update(['current_version_id' => $this->version->id]);

        // Hidden field should not be validated
        $response = $this->postJson('/api/public/forms/hidden-form/submit', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'show_extra' => false,
        ]);

        $response->assertStatus(201);
    }
}
