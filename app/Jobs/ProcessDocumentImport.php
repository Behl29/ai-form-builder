<?php

namespace App\Jobs;

use App\Models\ImportJob;
use App\Services\Import\ImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessDocumentImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [10, 30, 60];
    public int $timeout = 300;
    public int $maxExceptions = 3;

    public function __construct(
        public ImportJob $importJob
    ) {
        $this->onQueue('imports');
    }

    public function handle(ImportService $importService): void
    {
        // Idempotency check - skip if already processed
        $this->importJob->refresh();
        if ($this->importJob->isComplete() || $this->importJob->isParsed()) {
            Log::info('Import job already processed, skipping', ['job_uuid' => $this->importJob->job_uuid]);
            return;
        }

        // Prevent duplicate processing
        if ($this->importJob->isRunning()) {
            Log::info('Import job already running, skipping', ['job_uuid' => $this->importJob->job_uuid]);
            return;
        }

        try {
            $importService->processImport($this->importJob);
        } catch (\Exception $e) {
            Log::error('Document import failed', [
                'job_uuid' => $this->importJob->job_uuid,
                'error' => $e->getMessage(),
            ]);

            $this->importJob->markFailed('Processing failed: ' . $e->getMessage());
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Document import job failed permanently', [
            'job_uuid' => $this->importJob->job_uuid,
            'error' => $exception->getMessage(),
        ]);

        $this->importJob->markFailed('Job failed after all retries: ' . $exception->getMessage());
    }
}
