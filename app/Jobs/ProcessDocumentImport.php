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
    public int $backoff = 10;
    public int $timeout = 120;

    public function __construct(
        public ImportJob $importJob
    ) {}

    public function handle(ImportService $importService): void
    {
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
