<?php

namespace App\Services\AI;

use App\Jobs\ProcessAIFormGeneration;
use App\Models\AIJob;
use App\Models\Form;
use App\Models\User;
use App\Services\FormSchema\FormSchemaValidator;
use App\Services\VersionService;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates AI form generation and editing workflows
 */
class AIFormService
{
    public function __construct(
        private FormAIProvider $provider,
        private FormSchemaValidator $validator,
        private AISchemaRepair $repair,
        private VersionService $versionService,
    ) {}

    /**
     * Queue a form generation job (or run sync if sync queue)
     */
    public function queueGeneration(
        User $user,
        int $tenantId,
        string $prompt,
        array $options = []
    ): AIJob {
        $job = AIJob::create([
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'request_type' => AIJob::TYPE_GENERATE,
            'status' => AIJob::STATUS_QUEUED,
            'provider' => $this->provider->getProviderName(),
            'model' => $this->provider->getModelName(),
            'prompt' => $this->sanitizePrompt($prompt),
            'options' => $options,
        ]);

        // For sync queue, run directly instead of dispatching
        if (config('queue.default') === 'sync') {
            $this->processGenerationSync($job);
        } else {
            ProcessAIFormGeneration::dispatch($job);
        }

        return $job;
    }

    /**
     * Process generation synchronously
     */
    private function processGenerationSync(AIJob $job): void
    {
        $job->markRunning();

        try {
            $response = $this->provider->generateForm($job->prompt, $job->options ?? []);

            $job->recordMetrics(
                $response->inputTokens ?? 0,
                $response->outputTokens ?? 0,
                $response->latencyMs ?? 0
            );

            if (!$response->success) {
                $job->markFailed($response->errorType, $response->error);
                return;
            }

            // Validate schema
            $errors = $this->validator->validateAndGetErrors($response->schema);

            if (!empty($errors)) {
                $repairResult = $this->repair->repair($response->schema);

                if ($repairResult['success']) {
                    $job->markSucceeded($repairResult['schema'], $repairResult['repairs']);
                } else {
                    $job->markFailed(AIResponse::ERROR_INVALID_SCHEMA, 'Schema validation failed', $repairResult['errors']);
                }
                return;
            }

            $job->markSucceeded($response->schema);
        } catch (\Exception $e) {
            \Log::error('AI sync generation failed', ['error' => $e->getMessage()]);
            $job->markFailed(AIResponse::ERROR_PROVIDER_ERROR, $e->getMessage());
        }
    }

    /**
     * Queue a form modification job
     */
    public function queueModification(
        User $user,
        Form $form,
        string $instruction,
        array $options = []
    ): AIJob {
        $job = AIJob::create([
            'tenant_id' => $form->tenant_id,
            'user_id' => $user->id,
            'form_id' => $form->id,
            'request_type' => AIJob::TYPE_MODIFY,
            'status' => AIJob::STATUS_QUEUED,
            'provider' => $this->provider->getProviderName(),
            'model' => $this->provider->getModelName(),
            'prompt' => $this->sanitizePrompt($instruction),
            'options' => $options,
        ]);

        ProcessAIFormGeneration::dispatch($job);

        return $job;
    }

    /**
     * Get job status
     */
    public function getJobStatus(string $jobUuid): ?AIJob
    {
        return AIJob::where('job_uuid', $jobUuid)->first();
    }

    /**
     * Generate diff between current schema and AI-generated schema
     */
    public function generateDiff(Form $form, array $newSchema): array
    {
        $currentSchema = $form->currentVersion?->schema ?? [];

        return $this->versionService->compareVersions(
            new \App\Models\FormVersion(['schema' => $currentSchema, 'version_number' => 0]),
            new \App\Models\FormVersion(['schema' => $newSchema, 'version_number' => 1])
        );
    }

    /**
     * Accept AI-generated schema and create new version
     */
    public function acceptGeneratedSchema(
        Form $form,
        User $user,
        array $schema,
        AIJob $aiJob
    ): Form {
        return DB::transaction(function () use ($form, $user, $schema, $aiJob) {
            // Create new version
            $this->versionService->createVersion(
                $form,
                $user,
                $schema,
                \App\Models\FormVersion::CHANGE_UPDATED
            );

            // Update AI job with form reference if it was a generation
            if ($aiJob->request_type === AIJob::TYPE_GENERATE && !$aiJob->form_id) {
                $aiJob->update(['form_id' => $form->id]);
            }

            return $form->fresh(['currentVersion']);
        });
    }

    /**
     * Create new form from AI-generated schema
     */
    public function createFormFromSchema(
        User $user,
        int $tenantId,
        array $schema,
        AIJob $aiJob
    ): Form {
        return DB::transaction(function () use ($user, $tenantId, $schema, $aiJob) {
            $title = $schema['metadata']['title'] ?? 'AI Generated Form';

            $form = Form::create([
                'tenant_id' => $tenantId,
                'created_by' => $user->id,
                'title' => $title,
                'description' => $schema['metadata']['description'] ?? '',
                'status' => Form::STATUS_DRAFT,
            ]);

            $this->versionService->createVersion(
                $form,
                $user,
                $schema,
                \App\Models\FormVersion::CHANGE_CREATED
            );

            // Link AI job to form
            $aiJob->update(['form_id' => $form->id]);

            return $form->fresh(['currentVersion']);
        });
    }

    /**
     * Synchronous generation (for testing or simple cases)
     */
    public function generateSync(string $prompt, array $options = []): array
    {
        $response = $this->provider->generateForm($prompt, $options);

        if (!$response->success) {
            return [
                'success' => false,
                'error' => $response->error,
                'error_type' => $response->errorType,
            ];
        }

        // Validate
        $errors = $this->validator->validate($response->schema);

        if (!empty($errors)) {
            // Attempt repair
            $repairResult = $this->repair->repair($response->schema);

            if (!$repairResult['success']) {
                return [
                    'success' => false,
                    'error' => 'Schema validation failed',
                    'error_type' => AIResponse::ERROR_INVALID_SCHEMA,
                    'validation_errors' => $repairResult['errors'],
                ];
            }

            return [
                'success' => true,
                'schema' => $repairResult['schema'],
                'repairs' => $repairResult['repairs'],
                'tokens' => $response->getTotalTokens(),
                'latency_ms' => $response->latencyMs,
            ];
        }

        return [
            'success' => true,
            'schema' => $response->schema,
            'repairs' => [],
            'tokens' => $response->getTotalTokens(),
            'latency_ms' => $response->latencyMs,
        ];
    }

    /**
     * Sanitize user prompt (remove potential PII patterns)
     */
    private function sanitizePrompt(string $prompt): string
    {
        // Remove email addresses
        $prompt = preg_replace('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', '[EMAIL]', $prompt);

        // Remove phone numbers
        $prompt = preg_replace('/\b\d{3}[-.]?\d{3}[-.]?\d{4}\b/', '[PHONE]', $prompt);

        // Remove potential SSN
        $prompt = preg_replace('/\b\d{3}-\d{2}-\d{4}\b/', '[SSN]', $prompt);

        return $prompt;
    }

    /**
     * Check if provider is available
     */
    public function isProviderAvailable(): bool
    {
        return $this->provider->isAvailable();
    }

    /**
     * Get provider info
     */
    public function getProviderInfo(): array
    {
        return [
            'provider' => $this->provider->getProviderName(),
            'model' => $this->provider->getModelName(),
            'available' => $this->provider->isAvailable(),
        ];
    }
}
