<?php

namespace App\Services;

use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\FormVersion;
use App\Models\SubmissionFile;
use App\Services\FormSchema\FormSchemaContract;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SubmissionService
{
    public function __construct(
        private SubmissionValidator $validator,
        private FileSecurityService $fileSecurity
    ) {}

    public function submit(Form $form, array $data, array $files, Request $request): FormSubmission
    {
        if (!$form->isPublished()) {
            throw new \RuntimeException('Form is not published.');
        }

        $version = $form->currentVersion;
        if (!$version || !$version->isPublished()) {
            throw new \RuntimeException('Form has no published version.');
        }

        // Check for duplicate submission (within 30 seconds, same IP, same data hash)
        $dataHash = md5(json_encode($data));
        $hashedIp = $this->hashIp($request->ip());
        $recentDuplicate = FormSubmission::where('form_id', $form->id)
            ->where('ip_address', $hashedIp)
            ->where('submitted_at', '>=', now()->subSeconds(30))
            ->get()
            ->first(function ($submission) use ($dataHash) {
                return md5(json_encode($submission->data)) === $dataHash;
            });

        if ($recentDuplicate) {
            throw new \RuntimeException('Duplicate submission detected. Please wait before submitting again.');
        }

        // Validate submission
        $errors = $this->validator->validate($version->schema, $data, $files);
        if (!empty($errors)) {
            throw new SubmissionValidationException($errors);
        }

        return DB::transaction(function () use ($form, $version, $data, $files, $request) {
            // Create submission
            $submission = FormSubmission::create([
                'form_id' => $form->id,
                'form_version_id' => $version->id,
                'data' => $this->sanitizeData($data, $version->schema),
                'status' => FormSubmission::STATUS_COMPLETED,
                'ip_address' => $this->hashIp($request->ip()),
                'user_agent' => Str::limit($request->userAgent(), 500),
                'submitted_at' => now(),
            ]);

            // Handle file uploads
            $this->processFiles($submission, $files, $version->schema);

            return $submission->load('files');
        });
    }

    public function list(Form $form, array $filters = []): LengthAwarePaginator
    {
        $query = FormSubmission::where('form_id', $form->id)
            ->with(['formVersion:id,version_number', 'files'])
            ->orderByDesc('submitted_at');

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            // Use LIKE search on JSON data - works with both MySQL and SQLite
            $query->where('data', 'LIKE', "%{$search}%");
        }

        if (!empty($filters['from_date'])) {
            $query->where('submitted_at', '>=', $filters['from_date']);
        }

        if (!empty($filters['to_date'])) {
            $query->where('submitted_at', '<=', $filters['to_date'] . ' 23:59:59');
        }

        return $query->paginate($filters['per_page'] ?? 20);
    }

    public function getSubmission(int $id): ?FormSubmission
    {
        return FormSubmission::with(['form', 'formVersion', 'files'])->find($id);
    }

    public function exportCsv(Form $form, ?int $versionId = null): string
    {
        $query = FormSubmission::where('form_id', $form->id)
            ->with(['formVersion', 'files'])
            ->orderByDesc('submitted_at');

        if ($versionId) {
            $query->where('form_version_id', $versionId);
        }

        $submissions = $query->get();

        if ($submissions->isEmpty()) {
            return '';
        }

        // Get schema from first submission's version for column headers
        $version = $versionId
            ? FormVersion::find($versionId)
            : $submissions->first()->formVersion;

        $fields = $this->getFieldsFromSchema($version->schema);
        $headers = ['Submission ID', 'Submitted At', ...array_column($fields, 'label')];

        $output = fopen('php://temp', 'r+');

        // Write headers
        fputcsv($output, array_map([$this, 'escapeCsvCell'], $headers));

        // Write data rows
        foreach ($submissions as $submission) {
            $row = [
                $submission->id,
                $submission->submitted_at->toIso8601String(),
            ];

            foreach ($fields as $field) {
                $value = $submission->data[$field['key']] ?? '';

                // Handle arrays (checkbox groups)
                if (is_array($value)) {
                    $value = implode(', ', $value);
                }

                // Handle file fields
                if ($field['type'] === 'file') {
                    $fileNames = $submission->files
                        ->where('field_key', $field['key'])
                        ->pluck('original_name')
                        ->implode(', ');
                    $value = $fileNames ?: '';
                }

                $row[] = $this->escapeCsvCell((string) $value);
            }

            fputcsv($output, $row);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    public function getFile(int $fileId): ?SubmissionFile
    {
        return SubmissionFile::find($fileId);
    }

    public function canAccessFile(SubmissionFile $file, Form $form): bool
    {
        $submission = $file->submission;
        return $submission && $submission->form_id === $form->id;
    }

    private function sanitizeData(array $data, array $schema): array
    {
        $sanitized = [];
        $validKeys = [];
        $fileKeys = [];

        foreach ($schema['sections'] ?? [] as $section) {
            foreach ($section['fields'] ?? [] as $field) {
                if (in_array($field['type'], FormSchemaContract::PRESENTATIONAL_FIELDS)) {
                    continue;
                }
                if ($field['type'] === 'file') {
                    $fileKeys[] = $field['key'];
                    continue;
                }
                $validKeys[] = $field['key'];
            }
        }

        foreach ($data as $key => $value) {
            // Skip file fields - they're handled separately
            if (in_array($key, $fileKeys)) {
                continue;
            }
            // Skip if value is an UploadedFile instance
            if ($value instanceof UploadedFile) {
                continue;
            }
            if (in_array($key, $validKeys)) {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    private function processFiles(FormSubmission $submission, array $files, array $schema): void
    {
        $fileFields = [];
        foreach ($schema['sections'] ?? [] as $section) {
            foreach ($section['fields'] ?? [] as $field) {
                if ($field['type'] === 'file') {
                    $fileFields[$field['key']] = $field;
                }
            }
        }

        foreach ($files as $key => $uploadedFiles) {
            if (!isset($fileFields[$key])) {
                continue;
            }

            $fileList = is_array($uploadedFiles) ? $uploadedFiles : [$uploadedFiles];
            $allowedTypes = $fileFields[$key]['accept'] ?? [];

            foreach ($fileList as $file) {
                if (!($file instanceof UploadedFile) || !$file->isValid()) {
                    continue;
                }

                // Security validation
                $errors = $this->fileSecurity->validateUpload($file, $allowedTypes);
                if (!empty($errors)) {
                    throw new \RuntimeException('File validation failed: ' . implode(', ', $errors));
                }

                $this->storeFile($submission, $key, $file);
            }
        }
    }

    private function storeFile(FormSubmission $submission, string $fieldKey, UploadedFile $file): SubmissionFile
    {
        // Generate non-guessable path
        $directory = 'submissions/' . $submission->form_id . '/' . Str::random(16);
        $storedName = Str::random(32) . '.' . $file->getClientOriginalExtension();
        $path = $directory . '/' . $storedName;

        // Store file privately
        Storage::disk('local')->putFileAs($directory, $file, $storedName);

        return SubmissionFile::create([
            'form_submission_id' => $submission->id,
            'field_key' => $fieldKey,
            'original_name' => $this->fileSecurity->sanitizeFilename($file->getClientOriginalName()),
            'stored_name' => $storedName,
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'disk' => 'local',
        ]);
    }

    private function hashIp(?string $ip): ?string
    {
        if (!$ip) {
            return null;
        }
        // Hash IP for privacy while still allowing duplicate detection
        return hash('sha256', $ip . config('app.key'));
    }

    private function getFieldsFromSchema(array $schema): array
    {
        $fields = [];
        foreach ($schema['sections'] ?? [] as $section) {
            foreach ($section['fields'] ?? [] as $field) {
                if (in_array($field['type'], FormSchemaContract::PRESENTATIONAL_FIELDS)) {
                    continue;
                }
                $fields[] = [
                    'key' => $field['key'],
                    'label' => $field['label'] ?? $field['key'],
                    'type' => $field['type'],
                ];
            }
        }
        return $fields;
    }

    private function escapeCsvCell(string $value): string
    {
        // Prevent CSV injection by prefixing dangerous characters
        $dangerousChars = ['=', '+', '-', '@', "\t", "\r", "\n"];
        $firstChar = mb_substr($value, 0, 1);

        if (in_array($firstChar, $dangerousChars)) {
            return "'" . $value;
        }

        return $value;
    }
}
