<?php

namespace Tests\Feature\Security;

use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\FormVersion;
use App\Models\SubmissionFile;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FileSecurityService;
use App\Services\TenantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FileSecurityTest extends TestCase
{
    use RefreshDatabase;

    private FileSecurityService $fileSecurity;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fileSecurity = new FileSecurityService();
        Storage::fake('local');
    }

    public function test_blocks_php_file_upload(): void
    {
        $file = UploadedFile::fake()->create('malicious.php', 100, 'application/x-php');
        $errors = $this->fileSecurity->validateUpload($file);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('not allowed', $errors[0]);
    }

    public function test_blocks_exe_file_upload(): void
    {
        $file = UploadedFile::fake()->create('malicious.exe', 100, 'application/x-msdownload');
        $errors = $this->fileSecurity->validateUpload($file);

        $this->assertNotEmpty($errors);
    }

    public function test_blocks_double_extension_files(): void
    {
        $file = UploadedFile::fake()->create('image.php.jpg', 100, 'image/jpeg');
        $errors = $this->fileSecurity->validateUpload($file);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('multiple extensions', $errors[0]);
    }

    public function test_allows_valid_image_upload(): void
    {
        $file = UploadedFile::fake()->image('photo.jpg', 100, 100);
        $errors = $this->fileSecurity->validateUpload($file);

        $this->assertEmpty($errors);
    }

    public function test_allows_valid_pdf_upload(): void
    {
        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');
        $errors = $this->fileSecurity->validateUpload($file);

        $this->assertEmpty($errors);
    }

    public function test_sanitizes_filename_with_path_traversal(): void
    {
        $sanitized = $this->fileSecurity->sanitizeFilename('../../../etc/passwd');
        $this->assertEquals('passwd', $sanitized);
    }

    public function test_sanitizes_filename_with_null_bytes(): void
    {
        $sanitized = $this->fileSecurity->sanitizeFilename("file\0.txt");
        $this->assertStringNotContainsString("\0", $sanitized);
    }

    public function test_sanitizes_long_filename(): void
    {
        $longName = str_repeat('a', 300) . '.txt';
        $sanitized = $this->fileSecurity->sanitizeFilename($longName);
        $this->assertLessThanOrEqual(200, strlen($sanitized));
    }

    public function test_unauthorized_file_download_blocked(): void
    {
        // Setup tenant and user
        $tenant1 = Tenant::factory()->create();
        $tenant2 = Tenant::factory()->create();

        $user1 = User::factory()->create(['current_tenant_id' => $tenant1->id]);
        $tenant1->users()->attach($user1->id, ['role' => 'owner']);

        $user2 = User::factory()->create(['current_tenant_id' => $tenant2->id]);
        $tenant2->users()->attach($user2->id, ['role' => 'owner']);

        // Create form and submission for tenant1
        app(TenantService::class)->set($tenant1);
        $form = Form::factory()->create([
            'tenant_id' => $tenant1->id,
            'created_by' => $user1->id,
            'status' => Form::STATUS_PUBLISHED,
        ]);
        $version = FormVersion::factory()->create([
            'form_id' => $form->id,
            'created_by' => $user1->id,
            'is_published' => true,
        ]);
        $form->update(['current_version_id' => $version->id]);

        $submission = FormSubmission::create([
            'form_id' => $form->id,
            'form_version_id' => $version->id,
            'data' => ['name' => 'Test'],
            'status' => 'completed',
            'submission_token' => \Illuminate\Support\Str::random(64),
            'submitted_at' => now(),
        ]);

        // Create a file
        Storage::disk('local')->put('submissions/test/file.pdf', 'test content');
        $file = SubmissionFile::create([
            'form_submission_id' => $submission->id,
            'field_key' => 'document',
            'original_name' => 'test.pdf',
            'stored_name' => 'file.pdf',
            'path' => 'submissions/test/file.pdf',
            'mime_type' => 'application/pdf',
            'size' => 12,
            'disk' => 'local',
        ]);

        // User2 tries to download file from tenant1's form
        $response = $this->actingAs($user2)
            ->getJson("/api/forms/{$form->id}/submissions/{$submission->id}/files/{$file->id}");

        // Should be blocked - either 403 or 404
        $this->assertContains($response->status(), [403, 404]);
    }
}
