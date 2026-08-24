<?php

namespace App\Jobs;

use App\Models\AIJob;
use App\Services\AI\AIResponse;
use App\Services\AI\AISchemaRepair;
use App\Services\AI\FormAIProvider;
use App\Services\FormSchema\FormSchemaValidator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessAIFormGeneration implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [10, 30, 60];
    public int $timeout = 180;
    public int $maxExceptions = 3;

    public function __construct(
        public AIJob $aiJob
    ) {
        $this->onQueue('ai');
    }

    public function handle(
        FormAIProvider $provider,
        FormSchemaValidator $validator,
        AISchemaRepair $repair
    ): void {
        // Idempotency check - skip if already processed
        $this->aiJob->refresh();
        if ($this->aiJob->isComplete()) {
            Log::info('AI job already completed, skipping', ['job_uuid' => $this->aiJob->job_uuid]);
            return;
        }

        // Prevent duplicate processing
        if ($this->aiJob->isRunning()) {
            Log::info('AI job already running, skipping', ['job_uuid' => $this->aiJob->job_uuid]);
            return;
        }

        $this->aiJob->markRunning();

        try {
            // Call AI provider
            $response = $this->callProvider($provider);

            // Record metrics
            $this->aiJob->recordMetrics(
                $response->inputTokens,
                $response->outputTokens,
                $response->latencyMs
            );

            if (!$response->success) {
                $this->handleProviderError($response);
                return;
            }

            // Validate schema
            $schema = $response->schema;
            $validationErrors = $validator->validateAndGetErrors($schema);

            if (!empty($validationErrors)) {
                // Attempt repair
                $repairResult = $repair->repair($schema);

                if ($repairResult['success']) {
                    Log::info('AI schema repaired successfully', [
                        'job_uuid' => $this->aiJob->job_uuid,
                        'repairs' => $repairResult['repairs'],
                    ]);

                    $this->aiJob->markSucceeded($repairResult['schema'], $repairResult['repairs']);
                    return;
                }

                // Repair failed
                $this->aiJob->markFailed(
                    AIResponse::ERROR_INVALID_SCHEMA,
                    'Schema validation failed after repair attempt',
                    $repairResult['errors']
                );
                return;
            }

            // Schema is valid
            $this->aiJob->markSucceeded($schema);

        } catch (\Exception $e) {
            Log::error('AI form generation failed', [
                'job_uuid' => $this->aiJob->job_uuid,
                'error' => $e->getMessage(),
            ]);

            $this->aiJob->markFailed(
                AIResponse::ERROR_PROVIDER_ERROR,
                $e->getMessage()
            );
        }
    }

    private function callProvider(FormAIProvider $provider): AIResponse
    {
        if ($this->aiJob->request_type === AIJob::TYPE_GENERATE) {
            return $provider->generateForm(
                $this->aiJob->prompt,
                $this->aiJob->options ?? []
            );
        }

        // Modify existing form
        $form = $this->aiJob->form;
        $currentSchema = $form?->currentVersion?->schema ?? [];

        return $provider->modifyForm(
            $currentSchema,
            $this->aiJob->prompt,
            $this->aiJob->options ?? []
        );
    }

    private function handleProviderError(AIResponse $response): void
    {
        // Check if we should retry
        $retryableErrors = [
            AIResponse::ERROR_TIMEOUT,
            AIResponse::ERROR_RATE_LIMIT,
            AIResponse::ERROR_PROVIDER_ERROR,
        ];

        if (in_array($response->errorType, $retryableErrors) && $this->aiJob->retry_count < $this->tries) {
            $this->aiJob->incrementRetry();
            $this->release($this->backoff * ($this->aiJob->retry_count + 1));
            return;
        }

        $this->aiJob->markFailed($response->errorType, $response->error);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('AI job failed permanently', [
            'job_uuid' => $this->aiJob->job_uuid,
            'error' => $exception->getMessage(),
        ]);

        $this->aiJob->markFailed(
            AIResponse::ERROR_PROVIDER_ERROR,
            'Job failed after all retries: ' . $exception->getMessage()
        );
    }
}
