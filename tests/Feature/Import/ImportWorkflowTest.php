<?php

namespace Tests\Feature\Import;

use App\Models\Form;
use App\Models\ImportJob;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Import\ImportSchemaBuilder;
use App\Services\Import\ParsedElement;
use App\Services\Import\XlsxParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ImportWorkflowTest extends TestCase
{
    use RefreshDatabase, CreatesSampleFiles;

    private User $user;
    private Tenant $tenant;
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['current_tenant_id' => $this->tenant->id]);
        $this->user->tenants()->attach($this->tenant);

        $this->tempDir = sys_get_temp_dir() . '/import_tests_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            array_map('unlink', glob($this->tempDir . '/*'));
            rmdir($this->tempDir);
        }
        parent::tearDown();
    }

    public function test_schema_builder_creates_valid_schema(): void
    {
        $elements = [
            (new ParsedElement(
                type: ParsedElement::TYPE_QUESTION,
                sourceText: 'Name:',
                detectedFieldType: 'text',
                label: 'Name',
                key: 'name',
            ))->toArray(),
            (new ParsedElement(
                type: ParsedElement::TYPE_QUESTION,
                sourceText: 'Email:',
                detectedFieldType: 'email',
                label: 'Email',
                key: 'email',
            ))->toArray(),
        ];

        $builder = new ImportSchemaBuilder();
        $schema = $builder->build($elements, 'Test Form');

        $this->assertEquals('1.0', $schema['schemaVersion']);
        $this->assertEquals('Test Form', $schema['metadata']['title']);
        $this->assertNotEmpty($schema['sections']);
        $this->assertNotEmpty($schema['sections'][0]['fields']);
    }

    public function test_schema_builder_groups_by_headings(): void
    {
        $elements = [
            (new ParsedElement(
                type: ParsedElement::TYPE_HEADING,
                sourceText: 'Personal Info',
                detectedFieldType: 'heading',
                label: 'Personal Info',
            ))->toArray(),
            (new ParsedElement(
                type: ParsedElement::TYPE_QUESTION,
                sourceText: 'Name:',
                detectedFieldType: 'text',
                label: 'Name',
                key: 'name',
            ))->toArray(),
            (new ParsedElement(
                type: ParsedElement::TYPE_HEADING,
                sourceText: 'Contact',
                detectedFieldType: 'heading',
                label: 'Contact',
            ))->toArray(),
            (new ParsedElement(
                type: ParsedElement::TYPE_QUESTION,
                sourceText: 'Email:',
                detectedFieldType: 'email',
                label: 'Email',
                key: 'email',
            ))->toArray(),
        ];

        $builder = new ImportSchemaBuilder();
        $schema = $builder->build($elements, 'Test Form');

        $this->assertCount(2, $schema['sections']);
        $this->assertEquals('Personal Info', $schema['sections'][0]['title']);
        $this->assertEquals('Contact', $schema['sections'][1]['title']);
    }

    public function test_schema_builder_preserves_options(): void
    {
        $elements = [
            (new ParsedElement(
                type: ParsedElement::TYPE_CHOICE_LIST,
                sourceText: 'Options',
                detectedFieldType: 'select',
                label: 'Country',
                key: 'country',
                options: [
                    ['value' => 'us', 'label' => 'United States'],
                    ['value' => 'uk', 'label' => 'United Kingdom'],
                ],
            ))->toArray(),
        ];

        $builder = new ImportSchemaBuilder();
        $schema = $builder->build($elements, 'Test Form');

        $field = $schema['sections'][0]['fields'][0];
        $this->assertEquals('select', $field['type']);
        $this->assertCount(2, $field['options']);
    }

    public function test_failed_import_does_not_create_partial_form(): void
    {
        $initialFormCount = Form::count();

        $filePath = $this->tempDir . '/malformed.xlsx';
        $this->createMalformedXlsx($filePath);

        $file = new UploadedFile($filePath, 'malformed.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $parser = new XlsxParser();
        $result = $parser->parse($file);

        $this->assertFalse($result->success);
        $this->assertEquals($initialFormCount, Form::count());
    }

    public function test_import_job_model_status_transitions(): void
    {
        $job = ImportJob::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'import_type' => 'xlsx',
            'status' => ImportJob::STATUS_QUEUED,
            'original_filename' => 'test.xlsx',
        ]);

        $this->assertTrue($job->isQueued());
        $this->assertFalse($job->canCommit());

        $job->markRunning();
        $this->assertTrue($job->isRunning());

        $job->markParsed([['type' => 'question', 'label' => 'Test']]);
        $this->assertTrue($job->isParsed());
        $this->assertTrue($job->canCommit());

        $job->markSucceeded(['schemaVersion' => '1.0']);
        $this->assertTrue($job->isSucceeded());
        $this->assertTrue($job->isComplete());
    }

    public function test_import_job_corrections(): void
    {
        $job = ImportJob::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'import_type' => 'xlsx',
            'status' => ImportJob::STATUS_PARSED,
            'original_filename' => 'test.xlsx',
            'parsed_elements' => [
                ['type' => 'question', 'label' => 'Name', 'key' => 'name'],
                ['type' => 'question', 'label' => 'Email', 'key' => 'email'],
            ],
        ]);

        $job->updateCorrections([
            ['type' => 'question', 'label' => 'Full Name', 'key' => 'full_name'],
            ['type' => 'question', 'label' => 'Email Address', 'key' => 'email_address'],
        ]);

        $job->refresh();
        $elements = $job->getElementsForPreview();

        $this->assertEquals('Full Name', $elements[0]['label']);
        $this->assertEquals('full_name', $elements[0]['key']);
    }

    public function test_schema_builder_generates_unique_keys(): void
    {
        $elements = [
            (new ParsedElement(
                type: ParsedElement::TYPE_QUESTION,
                sourceText: 'Name:',
                detectedFieldType: 'text',
                label: 'Name',
                key: 'name',
            ))->toArray(),
            (new ParsedElement(
                type: ParsedElement::TYPE_QUESTION,
                sourceText: 'Name:',
                detectedFieldType: 'text',
                label: 'Name',
                key: 'name',
            ))->toArray(),
        ];

        $builder = new ImportSchemaBuilder();
        $schema = $builder->build($elements, 'Test Form');

        $keys = array_column($schema['sections'][0]['fields'], 'key');
        $this->assertCount(2, array_unique($keys));
    }

    public function test_schema_builder_adds_default_options_for_select(): void
    {
        $elements = [
            (new ParsedElement(
                type: ParsedElement::TYPE_QUESTION,
                sourceText: 'Choice:',
                detectedFieldType: 'select',
                label: 'Choice',
                key: 'choice',
                options: [],
            ))->toArray(),
        ];

        $builder = new ImportSchemaBuilder();
        $schema = $builder->build($elements, 'Test Form');

        $field = $schema['sections'][0]['fields'][0];
        $this->assertNotEmpty($field['options']);
    }
}
