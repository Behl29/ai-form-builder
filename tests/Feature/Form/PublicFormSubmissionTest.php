<?php

namespace Tests\Feature\Form;

use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\FormVersion;
use App\Models\SubmissionFile;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FormSchema\FormSchemaContract;
use App\Services\TenantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicFormSubmissionTest extends TestCase
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

    private function createSchema(array $fields = []): array
    {
        return [
            'schemaVersion' => FormSchemaContract::SCHEMA_VERSION,
            'metadata' => ['title' => 'Test Form'],
            'settings' => ['submitButtonText' => 'Submit'],
            'sections' => [
                [
                    'id' => 'section_1',
                    'title' => 'Section 1',
                    'fields' => $fields ?: [
                        ['id' => 'f1', 'key' => 'name', 'type' => 'text', 'label' => 'Name', 'required' => true],
                        ['id' => 'f2', 'key' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true],
                    ],
                ],
            ],
        ];
    }

    private function createPublishedForm(array $schema = null): void
    {
        $this->form = Form::factory()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
            'status' => Form::STATUS_PUBLISHED,
            'slug' => 'test-form-' . uniqid(),
        ]);

        $this->version = FormVersion::factory()->create([
            'form_id' => $this->form->id,
            'created_by' => $this->user->id,
            'schema' => $schema ?? $this->createSchema(),
            'is_published' => true,
            'published_at' => now(),
        ]);

        $this->form->update(['current_version_id' => $this->version->id]);
    }

    // ==================== PUBLIC FORM ACCESS ====================

    public function test_public_unpublished_form_rejection(): void
    {
        $form = Form::factory()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
            'status' => Form::STATUS_DRAFT,
            'slug' => 'draft-form',
        ]);

        $response = $this->getJson('/api/public/forms/draft-form');
        $response->assertStatus(404);
        $response->assertJson(['message' => 'Form not found.']);
    }

    public function test_public_published_form_rendering(): void
    {
        $this->createPublishedForm();

        $response = $this->getJson("/api/public/forms/{$this->form->slug}");

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'id',
                'title',
                'description',
                'slug',
                'success_message',
                'schema' => [
                    'schemaVersion',
                    'metadata',
                    'sections',
                ],
            ],
        ]);
    }

    // ==================== SERVER VALIDATION ====================

    public function test_server_validation_required_fields(): void
    {
        $this->createPublishedForm();

        $response = $this->postJson("/api/public/forms/{$this->form->slug}/submit", []);

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['name', 'email']]);
    }

    public function test_server_validation_email_format(): void
    {
        $this->createPublishedForm();

        $response = $this->postJson("/api/public/forms/{$this->form->slug}/submit", [
            'name' => 'John',
            'email' => 'not-an-email',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.email.0', 'Email must be a valid email address.');
    }

    public function test_server_validation_url_format(): void
    {
        $schema = $this->createSchema([
            ['id' => 'f1', 'key' => 'website', 'type' => 'url', 'label' => 'Website', 'required' => true],
        ]);
        $this->createPublishedForm($schema);

        $response = $this->postJson("/api/public/forms/{$this->form->slug}/submit", [
            'website' => 'not-a-url',
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['website']]);
    }

    public function test_server_validation_numeric_constraints(): void
    {
        $schema = $this->createSchema([
            ['id' => 'f1', 'key' => 'age', 'type' => 'number', 'label' => 'Age', 'min' => 18, 'max' => 100, 'required' => true],
        ]);
        $this->createPublishedForm($schema);

        // Below min
        $response = $this->postJson("/api/public/forms/{$this->form->slug}/submit", ['age' => 10]);
        $response->assertStatus(422);
        $response->assertJsonPath('errors.age.0', 'Age must be at least 18.');

        // Above max
        $response = $this->postJson("/api/public/forms/{$this->form->slug}/submit", ['age' => 150]);
        $response->assertStatus(422);
        $response->assertJsonPath('errors.age.0', 'Age must not exceed 100.');
    }

    public function test_server_validation_string_length(): void
    {
        $schema = $this->createSchema([
            ['id' => 'f1', 'key' => 'bio', 'type' => 'text', 'label' => 'Bio', 'minLength' => 10, 'maxLength' => 50, 'required' => true],
        ]);
        $this->createPublishedForm($schema);

        // Too short
        $response = $this->postJson("/api/public/forms/{$this->form->slug}/submit", ['bio' => 'Hi']);
        $response->assertStatus(422);
        $response->assertJsonPath('errors.bio.0', 'Bio must be at least 10 characters.');

        // Too long
        $response = $this->postJson("/api/public/forms/{$this->form->slug}/submit", ['bio' => str_repeat('a', 60)]);
        $response->assertStatus(422);
        $response->assertJsonPath('errors.bio.0', 'Bio must not exceed 50 characters.');
    }

    public function test_server_validation_regex_pattern(): void
    {
        $schema = $this->createSchema([
            ['id' => 'f1', 'key' => 'code', 'type' => 'text', 'label' => 'Code', 'pattern' => '^[A-Z]{3}[0-9]{3}$', 'required' => true],
        ]);
        $this->createPublishedForm($schema);

        $response = $this->postJson("/api/public/forms/{$this->form->slug}/submit", ['code' => 'invalid']);
        $response->assertStatus(422);
        $response->assertJsonPath('errors.code.0', 'Code format is invalid.');

        // Valid pattern
        $response = $this->postJson("/api/public/forms/{$this->form->slug}/submit", ['code' => 'ABC123']);
        $response->assertStatus(201);
    }

    public function test_server_validation_select_options(): void
    {
        $schema = $this->createSchema([
            [
                'id' => 'f1',
                'key' => 'color',
                'type' => 'select',
                'label' => 'Color',
                'options' => [
                    ['value' => 'red', 'label' => 'Red'],
                    ['value' => 'blue', 'label' => 'Blue'],
                ],
                'required' => true,
            ],
        ]);
        $this->createPublishedForm($schema);

        // Invalid option
        $response = $this->postJson("/api/public/forms/{$this->form->slug}/submit", ['color' => 'green']);
        $response->assertStatus(422);
        $response->assertJsonPath('errors.color.0', 'Color contains an invalid selection.');

        // Valid option
        $response = $this->postJson("/api/public/forms/{$this->form->slug}/submit", ['color' => 'red']);
        $response->assertStatus(201);
    }

    // ==================== VALID SUBMISSION ====================

    public function test_valid_submission_creates_record(): void
    {
        $this->createPublishedForm();

        $response = $this->postJson("/api/public/forms/{$this->form->slug}/submit", [
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure(['message', 'data' => ['submission_id', 'submitted_at']]);

        $this->assertDatabaseHas('form_submissions', [
            'form_id' => $this->form->id,
            'form_version_id' => $this->version->id,
            'status' => FormSubmission::STATUS_COMPLETED,
        ]);
    }

    // ==================== CONDITIONAL BEHAVIOR ====================

    public function test_conditional_show_field_validation(): void
    {
        $schema = $this->createSchema([
            ['id' => 'f1', 'key' => 'has_job', 'type' => 'checkbox', 'label' => 'Employed?'],
            [
                'id' => 'f2',
                'key' => 'company',
                'type' => 'text',
                'label' => 'Company',
                'required' => true,
                'conditions' => [
                    ['field' => 'has_job', 'operator' => 'equals', 'value' => true, 'action' => 'show'],
                ],
            ],
        ]);
        $this->createPublishedForm($schema);

        // Hidden field not validated
        $response = $this->postJson("/api/public/forms/{$this->form->slug}/submit", ['has_job' => false]);
        $response->assertStatus(201);

        // Shown field is validated
        $response = $this->postJson("/api/public/forms/{$this->form->slug}/submit", ['has_job' => true]);
        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['company']]);
    }

    public function test_conditional_require_field_validation(): void
    {
        $schema = $this->createSchema([
            ['id' => 'f1', 'key' => 'subscribe', 'type' => 'checkbox', 'label' => 'Subscribe?'],
            [
                'id' => 'f2',
                'key' => 'email',
                'type' => 'email',
                'label' => 'Email',
                'conditions' => [
                    ['field' => 'subscribe', 'operator' => 'equals', 'value' => true, 'action' => 'require'],
                ],
            ],
        ]);
        $this->createPublishedForm($schema);

        // Not required when unchecked
        $response = $this->postJson("/api/public/forms/{$this->form->slug}/submit", ['subscribe' => false]);
        $response->assertStatus(201);

        // Required when checked
        $response = $this->postJson("/api/public/forms/{$this->form->slug}/submit", ['subscribe' => true]);
        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['email']]);
    }

    // ==================== FILE VALIDATION ====================

    public function test_file_extension_validation(): void
    {
        $schema = $this->createSchema([
            [
                'id' => 'f1',
                'key' => 'document',
                'type' => 'file',
                'label' => 'Document',
                'accept' => ['.pdf', '.doc'],
                'required' => true,
            ],
        ]);
        $this->createPublishedForm($schema);

        $file = UploadedFile::fake()->create('test.exe', 100, 'application/x-msdownload');

        $response = $this->postJson("/api/public/forms/{$this->form->slug}/submit", [
            'document' => [$file],
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['document']]);
    }

    public function test_file_size_validation(): void
    {
        $schema = $this->createSchema([
            [
                'id' => 'f1',
                'key' => 'document',
                'type' => 'file',
                'label' => 'Document',
                'maxSize' => 1024, // 1KB
                'required' => true,
            ],
        ]);
        $this->createPublishedForm($schema);

        $file = UploadedFile::fake()->create('test.pdf', 100, 'application/pdf'); // 100KB

        $response = $this->postJson("/api/public/forms/{$this->form->slug}/submit", [
            'document' => [$file],
        ]);

        $response->assertStatus(422);
    }

    public function test_file_upload_creates_record(): void
    {
        $schema = $this->createSchema([
            ['id' => 'f1', 'key' => 'name', 'type' => 'text', 'label' => 'Name', 'required' => true],
            ['id' => 'f2', 'key' => 'resume', 'type' => 'file', 'label' => 'Resume'],
        ]);
        $this->createPublishedForm($schema);

        $file = UploadedFile::fake()->create('resume.pdf', 50, 'application/pdf');

        $response = $this->postJson("/api/public/forms/{$this->form->slug}/submit", [
            'name' => 'Jane',
            'resume' => $file,
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('submission_files', [
            'field_key' => 'resume',
            'original_name' => 'resume.pdf',
        ]);
    }

    // ==================== DUPLICATE SUBMISSION PROTECTION ====================

    public function test_duplicate_submission_protection(): void
    {
        $this->createPublishedForm();

        $data = ['name' => 'John', 'email' => 'john@example.com'];

        // First submission
        $response1 = $this->postJson("/api/public/forms/{$this->form->slug}/submit", $data, [
            'REMOTE_ADDR' => '192.168.1.1',
        ]);
        $response1->assertStatus(201);

        // Immediate duplicate from same IP
        $response2 = $this->postJson("/api/public/forms/{$this->form->slug}/submit", $data, [
            'REMOTE_ADDR' => '192.168.1.1',
        ]);
        $response2->assertStatus(422);
        $response2->assertJsonPath('message', 'Duplicate submission detected. Please wait before submitting again.');
    }

    // ==================== SUBMISSION MANAGEMENT ====================

    public function test_search_submissions(): void
    {
        $this->createPublishedForm();

        FormSubmission::create([
            'form_id' => $this->form->id,
            'form_version_id' => $this->version->id,
            'data' => ['name' => 'John Doe', 'email' => 'john@example.com'],
            'status' => FormSubmission::STATUS_COMPLETED,
            'submitted_at' => now(),
        ]);

        FormSubmission::create([
            'form_id' => $this->form->id,
            'form_version_id' => $this->version->id,
            'data' => ['name' => 'Jane Smith', 'email' => 'jane@example.com'],
            'status' => FormSubmission::STATUS_COMPLETED,
            'submitted_at' => now(),
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/forms/{$this->form->id}/submissions?search=John");

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    }

    public function test_pagination_submissions(): void
    {
        $this->createPublishedForm();

        for ($i = 0; $i < 25; $i++) {
            FormSubmission::create([
                'form_id' => $this->form->id,
                'form_version_id' => $this->version->id,
                'data' => ['name' => "User $i", 'email' => "user$i@example.com"],
                'status' => FormSubmission::STATUS_COMPLETED,
                'submitted_at' => now(),
            ]);
        }

        $response = $this->actingAs($this->user)
            ->getJson("/api/forms/{$this->form->id}/submissions?per_page=10");

        $response->assertOk();
        $response->assertJsonCount(10, 'data');
        $response->assertJsonPath('meta.total', 25);
        $response->assertJsonPath('meta.last_page', 3);
    }

    // ==================== CSV INJECTION PREVENTION ====================

    public function test_csv_injection_prevention(): void
    {
        // Use the existing SubmissionManagementTest for CSV injection
        // This test verifies the escapeCsvCell function works
        $service = app(\App\Services\SubmissionService::class);
        
        // Use reflection to test the private method
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('escapeCsvCell');
        $method->setAccessible(true);

        // Test dangerous characters are escaped
        $this->assertEquals("'=SUM(A1)", $method->invoke($service, '=SUM(A1)'));
        $this->assertEquals("'+cmd|calc", $method->invoke($service, '+cmd|calc'));
        $this->assertEquals("'-1+1", $method->invoke($service, '-1+1'));
        $this->assertEquals("'@SUM(A1)", $method->invoke($service, '@SUM(A1)'));
        
        // Test safe values are not escaped
        $this->assertEquals('Hello World', $method->invoke($service, 'Hello World'));
        $this->assertEquals('123', $method->invoke($service, '123'));
    }

    // ==================== DOWNLOAD AUTHORIZATION ====================

    public function test_download_authorization_requires_auth(): void
    {
        $this->createPublishedForm();

        $submission = FormSubmission::create([
            'form_id' => $this->form->id,
            'form_version_id' => $this->version->id,
            'data' => ['name' => 'John'],
            'status' => FormSubmission::STATUS_COMPLETED,
            'submitted_at' => now(),
        ]);

        Storage::disk('local')->put('submissions/test.pdf', 'content');

        $file = SubmissionFile::create([
            'form_submission_id' => $submission->id,
            'field_key' => 'resume',
            'original_name' => 'resume.pdf',
            'stored_name' => 'test.pdf',
            'path' => 'submissions/test.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1024,
        ]);

        // Unauthenticated
        $response = $this->getJson("/api/forms/{$this->form->id}/submissions/{$submission->id}/files/{$file->id}");
        $response->assertStatus(401);

        // Authenticated
        $response = $this->actingAs($this->user)
            ->get("/api/forms/{$this->form->id}/submissions/{$submission->id}/files/{$file->id}");
        $response->assertOk();
    }

    public function test_download_authorization_prevents_cross_tenant(): void
    {
        $this->createPublishedForm();

        $submission = FormSubmission::create([
            'form_id' => $this->form->id,
            'form_version_id' => $this->version->id,
            'data' => ['name' => 'John'],
            'status' => FormSubmission::STATUS_COMPLETED,
            'submitted_at' => now(),
        ]);

        Storage::disk('local')->put('submissions/test.pdf', 'content');

        $file = SubmissionFile::create([
            'form_submission_id' => $submission->id,
            'field_key' => 'resume',
            'original_name' => 'resume.pdf',
            'stored_name' => 'test.pdf',
            'path' => 'submissions/test.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1024,
        ]);

        // Create another tenant/user
        $otherTenant = Tenant::factory()->create();
        $otherUser = User::factory()->create(['current_tenant_id' => $otherTenant->id]);
        $otherTenant->users()->attach($otherUser->id, ['role' => 'owner']);

        $response = $this->actingAs($otherUser)
            ->get("/api/forms/{$this->form->id}/submissions/{$submission->id}/files/{$file->id}");

        // Should be forbidden (403) or not found (404) - either is acceptable for cross-tenant access
        $this->assertTrue(in_array($response->status(), [403, 404]));
    }
}
