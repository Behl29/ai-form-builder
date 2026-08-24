<?php

namespace Tests\Feature\AI;

use App\Models\AIJob;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AI\AIResponse;
use App\Services\AI\AISchemaRepair;
use App\Services\AI\FormAIProvider;
use App\Services\AI\Providers\MockAIProvider;
use App\Services\FormSchema\FormSchemaContract;
use App\Services\FormSchema\FormSchemaValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AIFormGenerationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Tenant $tenant;
    private MockAIProvider $mockProvider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['current_tenant_id' => $this->tenant->id]);
        $this->user->tenants()->attach($this->tenant);

        // Use mock provider for tests
        $this->mockProvider = new MockAIProvider();
        $this->app->instance(FormAIProvider::class, $this->mockProvider);
    }

    private function actingAsUser(): self
    {
        return $this->actingAs($this->user)
            ->withHeaders(['X-Tenant-ID' => $this->tenant->id]);
    }

    // ==================== GENERATION TESTS ====================

    public function test_queues_form_generation(): void
    {
        $response = $this->actingAsUser()
            ->postJson('/api/ai/generate', [
                'prompt' => 'Create an internship application form with personal information and education',
            ]);

        $response->assertStatus(202);
        $response->assertJsonStructure(['message', 'job_uuid', 'status']);

        // Job runs synchronously in tests, so it may already be succeeded
        $this->assertDatabaseHas('ai_jobs', [
            'job_uuid' => $response->json('job_uuid'),
            'request_type' => 'generate',
        ]);
    }

    public function test_validates_prompt_length(): void
    {
        $response = $this->actingAsUser()
            ->postJson('/api/ai/generate', [
                'prompt' => 'short',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['prompt']);
    }

    public function test_valid_generation_creates_schema(): void
    {
        $response = $this->mockProvider->generateForm(
            'Create a contact form with name, email, and message'
        );

        $this->assertTrue($response->success);
        $this->assertNotNull($response->schema);
        $this->assertEquals(FormSchemaContract::SCHEMA_VERSION, $response->schema['schemaVersion']);
    }

    public function test_generation_with_personal_info_section(): void
    {
        $response = $this->mockProvider->generateForm(
            'Create a form with personal information'
        );

        $this->assertTrue($response->success);
        $sections = $response->schema['sections'] ?? [];
        $this->assertNotEmpty($sections);

        $fields = collect($sections)->flatMap(fn($s) => $s['fields'] ?? []);
        $fieldKeys = $fields->pluck('key')->toArray();

        $this->assertTrue(
            in_array('full_name', $fieldKeys) || in_array('name', $fieldKeys),
            'Should have name field'
        );
    }

    public function test_generation_with_file_upload(): void
    {
        $response = $this->mockProvider->generateForm(
            'Create a form with resume upload'
        );

        $this->assertTrue($response->success);

        $fields = collect($response->schema['sections'] ?? [])
            ->flatMap(fn($s) => $s['fields'] ?? []);

        $fileField = $fields->firstWhere('type', 'file');
        $this->assertNotNull($fileField, 'Should have file upload field');
        $this->assertArrayHasKey('accept', $fileField);
        $this->assertArrayHasKey('maxSize', $fileField);
    }

    // ==================== MALFORMED JSON TESTS ====================

    public function test_handles_invalid_json_response(): void
    {
        $this->mockProvider->setForcedResponse(
            AIResponse::failure(
                'Failed to parse JSON',
                AIResponse::ERROR_INVALID_JSON,
                'This is not valid JSON {{{'
            )
        );

        $response = $this->mockProvider->generateForm('test');
        $this->assertFalse($response->success);
        $this->assertEquals(AIResponse::ERROR_INVALID_JSON, $response->errorType);
    }

    // ==================== UNSUPPORTED FIELD TYPE TESTS ====================

    public function test_repairs_unsupported_field_type(): void
    {
        $response = $this->mockProvider->generateForm('unsupported field test');

        $repair = new AISchemaRepair(new FormSchemaValidator());
        $result = $repair->repair($response->schema);

        $this->assertTrue($result['success']);

        foreach ($result['schema']['sections'] as $section) {
            foreach ($section['fields'] as $field) {
                $this->assertContains(
                    $field['type'],
                    FormSchemaContract::FIELD_TYPES,
                    "Field type {$field['type']} should be valid"
                );
            }
        }
    }

    public function test_maps_unknown_field_types(): void
    {
        $schema = [
            'schemaVersion' => '1.0',
            'metadata' => ['title' => 'Test'],
            'settings' => ['submitButtonText' => 'Submit'],
            'sections' => [
                [
                    'id' => 'section_1',
                    'title' => 'Test',
                    'fields' => [
                        ['id' => 'f1', 'key' => 'field1', 'type' => 'magic_field', 'label' => 'Magic'],
                        ['id' => 'f2', 'key' => 'field2', 'type' => 'dropdown', 'label' => 'Dropdown'],
                        ['id' => 'f3', 'key' => 'field3', 'type' => 'boolean', 'label' => 'Boolean'],
                    ],
                ],
            ],
        ];

        $repair = new AISchemaRepair(new FormSchemaValidator());
        $result = $repair->repair($schema);

        $this->assertTrue($result['success']);

        $fields = $result['schema']['sections'][0]['fields'];
        $this->assertEquals('text', $fields[0]['type']);
        $this->assertEquals('select', $fields[1]['type']);
        $this->assertEquals('checkbox', $fields[2]['type']);
    }

    // ==================== REPAIR SUCCESS/FAILURE TESTS ====================

    public function test_repair_adds_missing_metadata(): void
    {
        $schema = [
            'sections' => [
                ['id' => 's1', 'title' => 'Test', 'fields' => []],
            ],
        ];

        $repair = new AISchemaRepair(new FormSchemaValidator());
        $result = $repair->repair($schema);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('metadata', $result['schema']);
        $this->assertArrayHasKey('title', $result['schema']['metadata']);
    }

    public function test_repair_fixes_duplicate_field_keys(): void
    {
        $schema = [
            'schemaVersion' => '1.0',
            'metadata' => ['title' => 'Test'],
            'settings' => ['submitButtonText' => 'Submit'],
            'sections' => [
                [
                    'id' => 'section_1',
                    'title' => 'Test',
                    'fields' => [
                        ['id' => 'f1', 'key' => 'name', 'type' => 'text', 'label' => 'Name 1'],
                        ['id' => 'f2', 'key' => 'name', 'type' => 'text', 'label' => 'Name 2'],
                    ],
                ],
            ],
        ];

        $repair = new AISchemaRepair(new FormSchemaValidator());
        $result = $repair->repair($schema);

        // Repair should fix duplicate keys
        $this->assertTrue($result['success'], 'Repair should succeed: ' . json_encode($result['errors']));

        $keys = array_column($result['schema']['sections'][0]['fields'], 'key');
        $this->assertCount(2, array_unique($keys), 'Keys should be unique');
    }

    public function test_repair_adds_options_to_select_field(): void
    {
        $schema = [
            'schemaVersion' => '1.0',
            'metadata' => ['title' => 'Test'],
            'settings' => ['submitButtonText' => 'Submit'],
            'sections' => [
                [
                    'id' => 'section_1',
                    'title' => 'Test',
                    'fields' => [
                        ['id' => 'f1', 'key' => 'choice', 'type' => 'select', 'label' => 'Choice'],
                    ],
                ],
            ],
        ];

        $repair = new AISchemaRepair(new FormSchemaValidator());
        $result = $repair->repair($schema);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('options', $result['schema']['sections'][0]['fields'][0]);
        $this->assertNotEmpty($result['schema']['sections'][0]['fields'][0]['options']);
    }

    public function test_repair_cannot_fix_completely_invalid_schema(): void
    {
        $repair = new AISchemaRepair(new FormSchemaValidator());

        // canRepair should return false for completely invalid schemas
        $this->assertFalse($repair->canRepair([]));
        $this->assertFalse($repair->canRepair(['invalid' => 'data']));

        // But should return true for schemas with some structure
        $this->assertTrue($repair->canRepair(['sections' => []]));
    }

    // ==================== TIMEOUT/RATE LIMIT TESTS ====================

    public function test_handles_timeout(): void
    {
        $response = $this->mockProvider->generateForm('timeout test');

        $this->assertFalse($response->success);
        $this->assertEquals(AIResponse::ERROR_TIMEOUT, $response->errorType);
    }

    public function test_handles_rate_limit(): void
    {
        $response = $this->mockProvider->generateForm('rate limit test');

        $this->assertFalse($response->success);
        $this->assertEquals(AIResponse::ERROR_RATE_LIMIT, $response->errorType);
    }

    public function test_handles_auth_error(): void
    {
        $response = $this->mockProvider->generateForm('auth error test');

        $this->assertFalse($response->success);
        $this->assertEquals(AIResponse::ERROR_AUTH_FAILURE, $response->errorType);
    }

    public function test_handles_provider_error(): void
    {
        $response = $this->mockProvider->generateForm('provider error test');

        $this->assertFalse($response->success);
        $this->assertEquals(AIResponse::ERROR_PROVIDER_ERROR, $response->errorType);
    }

    // ==================== AI EDITING TESTS ====================

    public function test_modifies_existing_form(): void
    {
        $currentSchema = [
            'schemaVersion' => '1.0',
            'metadata' => ['title' => 'Test Form'],
            'settings' => ['submitButtonText' => 'Submit'],
            'sections' => [
                [
                    'id' => 'section_personal',
                    'title' => 'Personal Information',
                    'fields' => [
                        ['id' => 'f1', 'key' => 'full_name', 'type' => 'text', 'label' => 'Full Name', 'required' => false],
                    ],
                ],
            ],
        ];

        $response = $this->mockProvider->modifyForm(
            $currentSchema,
            'Make full_name required'
        );

        $this->assertTrue($response->success);
    }

    public function test_adds_section_via_modification(): void
    {
        $currentSchema = [
            'schemaVersion' => '1.0',
            'metadata' => ['title' => 'Test Form'],
            'settings' => ['submitButtonText' => 'Submit'],
            'sections' => [
                ['id' => 'section_1', 'title' => 'Section 1', 'fields' => []],
            ],
        ];

        $response = $this->mockProvider->modifyForm(
            $currentSchema,
            'Add an emergency contact section'
        );

        $this->assertTrue($response->success);
        $this->assertGreaterThan(1, count($response->schema['sections']));
    }

    public function test_translates_labels(): void
    {
        $currentSchema = [
            'schemaVersion' => '1.0',
            'metadata' => ['title' => 'Test Form'],
            'settings' => ['submitButtonText' => 'Submit'],
            'sections' => [
                [
                    'id' => 'section_personal',
                    'title' => 'Personal Information',
                    'fields' => [
                        ['id' => 'f1', 'key' => 'full_name', 'type' => 'text', 'label' => 'Full Name'],
                    ],
                ],
            ],
        ];

        $response = $this->mockProvider->modifyForm(
            $currentSchema,
            'Translate labels to Hindi'
        );

        $this->assertTrue($response->success);
        $label = $response->schema['sections'][0]['fields'][0]['label'];
        $this->assertNotEquals('Full Name', $label);
    }

    public function test_adds_file_upload_with_size_limit(): void
    {
        $currentSchema = [
            'schemaVersion' => '1.0',
            'metadata' => ['title' => 'Test Form'],
            'settings' => ['submitButtonText' => 'Submit'],
            'sections' => [
                ['id' => 'section_1', 'title' => 'Documents', 'fields' => []],
            ],
        ];

        $response = $this->mockProvider->modifyForm(
            $currentSchema,
            'Add a PDF resume upload up to 5 MB'
        );

        $this->assertTrue($response->success);

        $fields = collect($response->schema['sections'])
            ->flatMap(fn($s) => $s['fields'] ?? []);

        $fileField = $fields->firstWhere('type', 'file');
        $this->assertNotNull($fileField);
        $this->assertEquals(5 * 1024 * 1024, $fileField['maxSize']);
    }

    // ==================== VERSION CREATION TESTS ====================

    public function test_accepting_schema_creates_new_version(): void
    {
        $form = Form::factory()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
        ]);

        $version = FormVersion::factory()->create([
            'form_id' => $form->id,
            'created_by' => $this->user->id,
            'version_number' => 1,
        ]);

        $form->update(['current_version_id' => $version->id]);

        $job = AIJob::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'form_id' => $form->id,
            'job_uuid' => \Illuminate\Support\Str::uuid(),
            'request_type' => 'modify',
            'status' => AIJob::STATUS_SUCCEEDED,
            'provider' => 'mock',
            'model' => 'mock-model',
            'prompt' => 'Test modification',
            'result_schema' => [
                'schemaVersion' => '1.0',
                'metadata' => ['title' => 'Modified Form'],
                'settings' => ['submitButtonText' => 'Submit'],
                'sections' => [['id' => 's1', 'title' => 'Test', 'fields' => []]],
            ],
        ]);

        $response = $this->actingAsUser()
            ->postJson("/api/ai/forms/{$form->id}/accept", [
                'job_uuid' => $job->job_uuid,
            ]);

        $response->assertOk();

        $form->refresh();
        $this->assertEquals(2, $form->versions()->count());
    }

    // ==================== JOB STATUS TESTS ====================

    public function test_can_get_job_status(): void
    {
        $job = AIJob::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'job_uuid' => \Illuminate\Support\Str::uuid(),
            'request_type' => 'generate',
            'status' => AIJob::STATUS_QUEUED,
            'provider' => 'mock',
            'model' => 'mock-model',
            'prompt' => 'Test prompt',
        ]);

        $response = $this->actingAsUser()
            ->getJson("/api/ai/jobs/{$job->job_uuid}");

        $response->assertOk();
        $response->assertJsonPath('status', 'queued');
        $response->assertJsonPath('request_type', 'generate');
    }

    public function test_succeeded_job_includes_schema(): void
    {
        $schema = [
            'schemaVersion' => '1.0',
            'metadata' => ['title' => 'Test'],
            'settings' => ['submitButtonText' => 'Submit'],
            'sections' => [],
        ];

        $job = AIJob::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'job_uuid' => \Illuminate\Support\Str::uuid(),
            'request_type' => 'generate',
            'status' => AIJob::STATUS_SUCCEEDED,
            'provider' => 'mock',
            'model' => 'mock-model',
            'prompt' => 'Test prompt',
            'result_schema' => $schema,
            'completed_at' => now(),
        ]);

        $response = $this->actingAsUser()
            ->getJson("/api/ai/jobs/{$job->job_uuid}");

        $response->assertOk();
        $response->assertJsonPath('status', 'succeeded');
        $response->assertJsonPath('result_schema.schemaVersion', '1.0');
    }

    public function test_failed_job_includes_error(): void
    {
        $job = AIJob::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'job_uuid' => \Illuminate\Support\Str::uuid(),
            'request_type' => 'generate',
            'status' => AIJob::STATUS_FAILED,
            'provider' => 'mock',
            'model' => 'mock-model',
            'prompt' => 'Test prompt',
            'error_type' => AIResponse::ERROR_TIMEOUT,
            'error_message' => 'Request timed out',
            'completed_at' => now(),
        ]);

        $response = $this->actingAsUser()
            ->getJson("/api/ai/jobs/{$job->job_uuid}");

        $response->assertOk();
        $response->assertJsonPath('status', 'failed');
        $response->assertJsonPath('error_type', 'timeout');
    }

    // ==================== OBSERVABILITY TESTS ====================

    public function test_records_token_usage(): void
    {
        $response = $this->mockProvider->generateForm('Create a simple form');

        $this->assertGreaterThan(0, $response->inputTokens);
        $this->assertGreaterThan(0, $response->outputTokens);
        $this->assertGreaterThan(0, $response->latencyMs);
    }

    public function test_job_records_metrics(): void
    {
        $job = AIJob::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'job_uuid' => \Illuminate\Support\Str::uuid(),
            'request_type' => 'generate',
            'status' => AIJob::STATUS_RUNNING,
            'provider' => 'mock',
            'model' => 'mock-model',
            'prompt' => 'Test prompt',
        ]);

        $job->recordMetrics(100, 200, 500.5);

        $job->refresh();
        $this->assertEquals(100, $job->input_tokens);
        $this->assertEquals(200, $job->output_tokens);
        $this->assertEquals(500, $job->latency_ms);
        $this->assertEquals(300, $job->getTotalTokens());
    }

    // ==================== SECURITY TESTS ====================

    public function test_sanitizes_error_messages(): void
    {
        $job = new AIJob();
        $reflection = new \ReflectionClass($job);
        $method = $reflection->getMethod('sanitizeErrorMessage');
        $method->setAccessible(true);

        $message = 'Error with Bearer sk-abc123xyz token';
        $sanitized = $method->invoke($job, $message);

        $this->assertStringNotContainsString('sk-abc123xyz', $sanitized);
        $this->assertStringContainsString('[REDACTED]', $sanitized);
    }

    public function test_cannot_access_other_tenant_jobs(): void
    {
        $otherTenant = Tenant::factory()->create();
        $otherUser = User::factory()->create(['current_tenant_id' => $otherTenant->id]);

        $job = AIJob::create([
            'tenant_id' => $otherTenant->id,
            'user_id' => $otherUser->id,
            'job_uuid' => \Illuminate\Support\Str::uuid(),
            'request_type' => 'generate',
            'status' => AIJob::STATUS_QUEUED,
            'provider' => 'mock',
            'model' => 'mock-model',
            'prompt' => 'Test prompt',
        ]);

        $response = $this->actingAsUser()
            ->getJson("/api/ai/jobs/{$job->job_uuid}");

        $response->assertNotFound();
    }

    // ==================== DIFF PREVIEW TESTS ====================

    public function test_can_preview_diff(): void
    {
        $form = Form::factory()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
        ]);

        $version = FormVersion::factory()->create([
            'form_id' => $form->id,
            'created_by' => $this->user->id,
            'version_number' => 1,
            'schema' => [
                'schemaVersion' => '1.0',
                'metadata' => ['title' => 'Original'],
                'settings' => ['submitButtonText' => 'Submit'],
                'sections' => [
                    ['id' => 's1', 'title' => 'Section 1', 'fields' => [
                        ['id' => 'f1', 'key' => 'name', 'type' => 'text', 'label' => 'Name'],
                    ]],
                ],
            ],
        ]);

        $form->update(['current_version_id' => $version->id]);

        $job = AIJob::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'form_id' => $form->id,
            'job_uuid' => \Illuminate\Support\Str::uuid(),
            'request_type' => 'modify',
            'status' => AIJob::STATUS_SUCCEEDED,
            'provider' => 'mock',
            'model' => 'mock-model',
            'prompt' => 'Add email field',
            'result_schema' => [
                'schemaVersion' => '1.0',
                'metadata' => ['title' => 'Original'],
                'settings' => ['submitButtonText' => 'Submit'],
                'sections' => [
                    ['id' => 's1', 'title' => 'Section 1', 'fields' => [
                        ['id' => 'f1', 'key' => 'name', 'type' => 'text', 'label' => 'Name'],
                        ['id' => 'f2', 'key' => 'email', 'type' => 'email', 'label' => 'Email'],
                    ]],
                ],
            ],
        ]);

        $response = $this->actingAsUser()
            ->postJson("/api/ai/forms/{$form->id}/preview-diff", [
                'job_uuid' => $job->job_uuid,
            ]);

        $response->assertOk();
        $response->assertJsonStructure(['diff', 'new_schema']);
        $this->assertArrayHasKey('email', $response->json('diff.fields.added'));
    }
}
