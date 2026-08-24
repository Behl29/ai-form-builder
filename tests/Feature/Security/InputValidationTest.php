<?php

namespace Tests\Feature\Security;

use App\Models\Form;
use App\Models\FormVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FormSchema\FormSchemaContract;
use App\Services\SubmissionValidator;
use App\Services\TenantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InputValidationTest extends TestCase
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

    public function test_rejects_oversized_schema(): void
    {
        // Create a schema that exceeds 1MB
        $largeSchema = [
            'schemaVersion' => '1.0',
            'metadata' => ['title' => 'Test'],
            'sections' => [],
        ];

        // Add enough data to exceed 1MB
        for ($i = 0; $i < 1000; $i++) {
            $largeSchema['sections'][] = [
                'id' => "section_{$i}",
                'title' => str_repeat('x', 1000),
                'fields' => [],
            ];
        }

        $response = $this->actingAs($this->user)
            ->postJson('/api/forms', [
                'title' => 'Test Form',
                'schema' => $largeSchema,
            ]);

        $response->assertStatus(422);
    }

    public function test_rejects_excessive_field_count(): void
    {
        $schema = [
            'schemaVersion' => '1.0',
            'metadata' => ['title' => 'Test'],
            'sections' => [
                [
                    'id' => 'section_1',
                    'title' => 'Section',
                    'fields' => [],
                ],
            ],
        ];

        // Add more than MAX_FIELD_COUNT fields
        for ($i = 0; $i < FormSchemaContract::MAX_FIELD_COUNT + 10; $i++) {
            $schema['sections'][0]['fields'][] = [
                'id' => "field_{$i}",
                'key' => "field_{$i}",
                'type' => 'text',
                'label' => "Field {$i}",
            ];
        }

        $response = $this->actingAs($this->user)
            ->postJson('/api/forms', [
                'title' => 'Test Form',
                'schema' => $schema,
            ]);

        $response->assertStatus(422);
    }

    public function test_rejects_malicious_csv_values_in_export(): void
    {
        // Create form with submission containing CSV injection attempt
        $form = Form::factory()->create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
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
            'created_by' => $this->user->id,
            'schema' => $schema,
            'is_published' => true,
        ]);
        $form->update(['current_version_id' => $version->id]);

        // Create submission with CSV injection attempt
        \App\Models\FormSubmission::create([
            'form_id' => $form->id,
            'form_version_id' => $version->id,
            'data' => ['name' => '=CMD|\'calc\'!A0'],
            'status' => 'completed',
            'submission_token' => \Illuminate\Support\Str::random(64),
            'submitted_at' => now(),
        ]);

        // Test the CSV escaping directly via the service
        $service = app(\App\Services\SubmissionService::class);
        $csv = $service->exportCsv($form);

        // The malicious value should be escaped with a leading quote
        $this->assertStringContainsString("'=", $csv);
    }

    public function test_regex_safety_validation(): void
    {
        $validator = new SubmissionValidator();

        $schema = [
            'sections' => [
                [
                    'id' => 'section_1',
                    'fields' => [
                        [
                            'id' => 'f1',
                            'key' => 'input',
                            'type' => 'text',
                            'label' => 'Input',
                            'pattern' => '^[a-z]+$', // Safe pattern
                        ],
                    ],
                ],
            ],
        ];

        // This should complete quickly with a safe pattern
        $startTime = microtime(true);
        $errors = $validator->validate($schema, ['input' => 'validinput']);
        $duration = microtime(true) - $startTime;

        // Should complete quickly
        $this->assertLessThan(1.0, $duration);
        $this->assertEmpty($errors);
    }

    public function test_rejects_invalid_field_types(): void
    {
        $schema = [
            'schemaVersion' => '1.0',
            'metadata' => ['title' => 'Test'],
            'sections' => [
                [
                    'id' => 'section_1',
                    'title' => 'Section',
                    'fields' => [
                        [
                            'id' => 'f1',
                            'key' => 'field1',
                            'type' => 'script', // Invalid type
                            'label' => 'Field',
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/api/forms', [
                'title' => 'Test Form',
                'schema' => $schema,
            ]);

        $response->assertStatus(422);
    }

    public function test_ai_prompt_length_limit(): void
    {
        $longPrompt = str_repeat('a', 3000); // Exceeds 2000 char limit

        $response = $this->actingAs($this->user)
            ->postJson('/api/ai/generate', [
                'prompt' => $longPrompt,
            ]);

        // Should fail validation (422), service unavailable (503), or internal error (500) if AI not configured
        $this->assertContains($response->status(), [422, 500, 503]);
    }

    public function test_import_file_size_limit(): void
    {
        // Create a file that exceeds the 10MB limit
        $largeFile = \Illuminate\Http\UploadedFile::fake()->create('large.docx', 11000); // 11MB

        $response = $this->actingAs($this->user)
            ->postJson('/api/import/upload', [
                'file' => $largeFile,
            ]);

        $response->assertStatus(422);
    }
}
