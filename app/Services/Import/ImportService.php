<?php

namespace App\Services\Import;

use App\Jobs\ProcessDocumentImport;
use App\Models\Form;
use App\Models\ImportJob;
use App\Models\User;
use App\Services\FormSchema\FormSchemaValidator;
use App\Services\VersionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ImportService
{
    private const LARGE_FILE_THRESHOLD = 1024 * 1024; // 1MB - queue if larger

    public function __construct(
        private DocxParser $docxParser,
        private XlsxParser $xlsxParser,
        private ImportSchemaBuilder $schemaBuilder,
        private FormSchemaValidator $validator,
        private VersionService $versionService,
    ) {}

    /**
     * Start an import job
     */
    public function startImport(
        User $user,
        int $tenantId,
        UploadedFile $file,
        bool $useAiClassification = false
    ): ImportJob {
        $extension = strtolower($file->getClientOriginalExtension());
        $importType = match ($extension) {
            'docx' => ImportJob::TYPE_DOCX,
            'xlsx', 'xls' => ImportJob::TYPE_XLSX,
            default => throw new \InvalidArgumentException("Unsupported file type: {$extension}"),
        };

        // Store file
        $filePath = $file->store('imports', 'local');

        $job = ImportJob::create([
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'import_type' => $importType,
            'status' => ImportJob::STATUS_QUEUED,
            'original_filename' => $file->getClientOriginalName(),
            'file_path' => $filePath,
            'file_size' => $file->getSize(),
            'use_ai_classification' => $useAiClassification,
        ]);

        // Queue if large file, otherwise process synchronously
        if ($file->getSize() > self::LARGE_FILE_THRESHOLD) {
            ProcessDocumentImport::dispatch($job);
        } else {
            $this->processImport($job);
        }

        return $job->fresh();
    }

    /**
     * Process an import job (called by queue or synchronously)
     */
    public function processImport(ImportJob $job): void
    {
        $job->markRunning();

        try {
            $filePath = Storage::disk('local')->path($job->file_path);
            $file = new UploadedFile(
                $filePath,
                $job->original_filename,
                null,
                null,
                true
            );

            // Parse based on type
            $result = match ($job->import_type) {
                ImportJob::TYPE_DOCX => $this->docxParser->parse($file),
                ImportJob::TYPE_XLSX => $this->xlsxParser->parse($file),
                default => ImportResult::failure(['Unknown import type']),
            };

            if (!$result->success) {
                $job->markFailed(implode('; ', $result->errors));
                return;
            }

            // Convert elements to array format
            $elements = array_map(fn($e) => $e->toArray(), $result->elements);

            $job->markParsed($elements, $result->warnings);

        } catch (\Exception $e) {
            $job->markFailed('Import processing failed: ' . $e->getMessage());
        }
    }

    /**
     * Get import preview
     */
    public function getPreview(ImportJob $job): array
    {
        if (!$job->isParsed()) {
            return [
                'status' => $job->status,
                'error' => $job->error_message,
            ];
        }

        $elements = $job->getElementsForPreview();

        return [
            'status' => $job->status,
            'elements' => $elements,
            'warnings' => $job->warnings ?? [],
            'unparseable_count' => count(array_filter($elements, fn($e) => !$e['parseable'])),
            'field_count' => count(array_filter($elements, fn($e) => $e['parseable'] && $e['type'] !== ParsedElement::TYPE_HEADING)),
        ];
    }

    /**
     * Apply corrections to parsed elements
     */
    public function applyCorrections(ImportJob $job, array $corrections): ImportJob
    {
        if (!$job->isParsed()) {
            throw new \InvalidArgumentException('Import must be in parsed state to apply corrections');
        }

        $elements = $job->parsed_elements;

        foreach ($corrections as $index => $correction) {
            if (isset($elements[$index])) {
                $elements[$index] = array_merge($elements[$index], $correction);
            }
        }

        $job->updateCorrections($elements);

        return $job->fresh();
    }

    /**
     * Commit import and create form
     */
    public function commitImport(ImportJob $job, User $user, ?string $title = null): Form
    {
        if (!$job->canCommit()) {
            throw new \InvalidArgumentException('Import cannot be committed in current state');
        }

        $elements = $job->getElementsForPreview();

        // Build schema
        $schema = $this->schemaBuilder->build($elements, $title);

        // Validate schema
        $errors = $this->validator->validateAndGetErrors($schema);
        if (!empty($errors)) {
            $job->markFailed('Schema validation failed', $errors);
            throw new \RuntimeException('Generated schema is invalid: ' . json_encode($errors));
        }

        return DB::transaction(function () use ($job, $user, $schema, $title) {
            // Create form
            $form = Form::create([
                'tenant_id' => $job->tenant_id,
                'created_by' => $user->id,
                'title' => $title ?? $schema['metadata']['title'] ?? 'Imported Form',
                'description' => $schema['metadata']['description'] ?? '',
                'status' => Form::STATUS_DRAFT,
            ]);

            // Create initial version
            $this->versionService->createVersion(
                $form,
                $user,
                $schema,
                \App\Models\FormVersion::CHANGE_CREATED
            );

            // Update job
            $job->update([
                'form_id' => $form->id,
                'result_schema' => $schema,
            ]);
            $job->markSucceeded($schema);

            // Clean up file
            if ($job->file_path) {
                Storage::disk('local')->delete($job->file_path);
            }

            return $form->fresh(['currentVersion']);
        });
    }

    /**
     * Cancel and clean up an import job
     */
    public function cancelImport(ImportJob $job): void
    {
        if ($job->file_path) {
            Storage::disk('local')->delete($job->file_path);
        }

        $job->markFailed('Import cancelled by user');
    }

    /**
     * Get job status
     */
    public function getJobStatus(string $jobUuid): ?ImportJob
    {
        return ImportJob::where('job_uuid', $jobUuid)->first();
    }
}
