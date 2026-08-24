<?php

namespace Tests\Feature\Import;

use App\Models\ImportJob;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Import\XlsxParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class XlsxImportTest extends TestCase
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

    public function test_xlsx_parser_parses_header_format(): void
    {
        $filePath = $this->tempDir . '/header.xlsx';
        $this->createSampleXlsx($filePath, 'header');

        $file = new UploadedFile($filePath, 'header.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $parser = new XlsxParser();
        $result = $parser->parse($file);

        $this->assertTrue($result->success);
        $this->assertCount(5, $result->elements); // Name, Email, Phone, Age, Website
    }

    public function test_xlsx_parser_parses_mapping_format(): void
    {
        $filePath = $this->tempDir . '/mapping.xlsx';
        $this->createSampleXlsx($filePath, 'mapping');

        $file = new UploadedFile($filePath, 'mapping.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $parser = new XlsxParser();
        $result = $parser->parse($file);

        $this->assertTrue($result->success);
        $this->assertGreaterThanOrEqual(5, count($result->elements));

        // Check that field types are preserved
        $emailField = collect($result->elements)->firstWhere(fn($e) => $e->key === 'email_address');
        $this->assertNotNull($emailField);
        $this->assertEquals('email', $emailField->detectedFieldType);
    }

    public function test_xlsx_parser_infers_types_from_samples(): void
    {
        $filePath = $this->tempDir . '/header.xlsx';
        $this->createSampleXlsx($filePath, 'header');

        $file = new UploadedFile($filePath, 'header.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $parser = new XlsxParser();
        $result = $parser->parse($file);

        // Email should be inferred from header
        $emailField = collect($result->elements)->firstWhere(fn($e) => $e->label === 'Email');
        $this->assertEquals('email', $emailField->detectedFieldType);

        // Age should be number
        $ageField = collect($result->elements)->firstWhere(fn($e) => $e->label === 'Age');
        $this->assertEquals('number', $ageField->detectedFieldType);
    }

    public function test_xlsx_parser_handles_ambiguous_values(): void
    {
        $filePath = $this->tempDir . '/ambiguous.xlsx';
        $this->createSampleXlsx($filePath, 'ambiguous');

        $file = new UploadedFile($filePath, 'ambiguous.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $parser = new XlsxParser();
        $result = $parser->parse($file);

        $this->assertTrue($result->success);
        // Ambiguous fields should default to text
        foreach ($result->elements as $element) {
            $this->assertContains($element->detectedFieldType, ['text', 'email', 'phone', 'number', 'date', 'url']);
        }
    }

    public function test_xlsx_parser_rejects_invalid_file(): void
    {
        $filePath = $this->tempDir . '/invalid.xlsx';
        file_put_contents($filePath, 'not a valid xlsx');

        $file = new UploadedFile($filePath, 'invalid.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $parser = new XlsxParser();
        $result = $parser->parse($file);

        $this->assertFalse($result->success);
        $this->assertNotEmpty($result->errors);
    }

    public function test_xlsx_parser_parses_options(): void
    {
        $filePath = $this->tempDir . '/mapping.xlsx';
        $this->createSampleXlsx($filePath, 'mapping');

        $file = new UploadedFile($filePath, 'mapping.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $parser = new XlsxParser();
        $result = $parser->parse($file);

        // Find the country select field
        $countryField = collect($result->elements)->firstWhere(fn($e) => $e->key === 'country');
        $this->assertNotNull($countryField);
        $this->assertEquals('select', $countryField->detectedFieldType);
        $this->assertNotEmpty($countryField->options);
    }

    public function test_xlsx_parser_parses_validation_rules(): void
    {
        $filePath = $this->tempDir . '/mapping.xlsx';
        $this->createSampleXlsx($filePath, 'mapping');

        $file = new UploadedFile($filePath, 'mapping.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $parser = new XlsxParser();
        $result = $parser->parse($file);

        // Find the age field with min/max validation
        $ageField = collect($result->elements)->firstWhere(fn($e) => $e->key === 'age');
        $this->assertNotNull($ageField);
        $this->assertEquals(18, $ageField->validations['min'] ?? null);
        $this->assertEquals(120, $ageField->validations['max'] ?? null);
    }

    public function test_xlsx_parser_infers_title_from_filename(): void
    {
        $filePath = $this->tempDir . '/my-contact-form.xlsx';
        $this->createSampleXlsx($filePath, 'header');

        $file = new UploadedFile($filePath, 'my-contact-form.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $parser = new XlsxParser();
        $result = $parser->parse($file);

        $this->assertTrue($result->success);
        $this->assertEquals('My Contact Form', $result->suggestedTitle);
    }
}
