<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ImportJob;
use App\Services\Import\ImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ImportController extends Controller
{
    public function __construct(
        private ImportService $importService
    ) {}

    /**
     * Upload and start import
     */
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|max:10240|mimes:docx,xlsx,xls',
            'use_ai' => 'boolean',
        ]);

        $file = $request->file('file');
        $user = $request->user();
        $tenantId = $user->current_tenant_id;

        try {
            $job = $this->importService->startImport(
                $user,
                $tenantId,
                $file,
                $request->boolean('use_ai', false)
            );

            return response()->json([
                'job_uuid' => $job->job_uuid,
                'status' => $job->status,
                'message' => $job->isParsed()
                    ? 'Import parsed successfully'
                    : 'Import queued for processing',
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Get import job status
     */
    public function status(Request $request, string $jobUuid): JsonResponse
    {
        $job = $this->importService->getJobStatus($jobUuid);

        if (!$job) {
            return response()->json(['error' => 'Import job not found'], 404);
        }

        // Check tenant access
        $tenantId = $request->user()->current_tenant_id;
        if ($job->tenant_id !== $tenantId) {
            return response()->json(['error' => 'Import job not found'], 404);
        }

        return response()->json([
            'job_uuid' => $job->job_uuid,
            'status' => $job->status,
            'import_type' => $job->import_type,
            'original_filename' => $job->original_filename,
            'error_message' => $job->error_message,
            'created_at' => $job->created_at,
            'completed_at' => $job->completed_at,
        ]);
    }

    /**
     * Get import preview
     */
    public function preview(Request $request, string $jobUuid): JsonResponse
    {
        $job = $this->importService->getJobStatus($jobUuid);

        if (!$job) {
            return response()->json(['error' => 'Import job not found'], 404);
        }

        $tenantId = $request->user()->current_tenant_id;
        if ($job->tenant_id !== $tenantId) {
            return response()->json(['error' => 'Import job not found'], 404);
        }

        if (!$job->isParsed()) {
            return response()->json([
                'status' => $job->status,
                'error' => $job->error_message ?? 'Import not yet parsed',
            ], $job->isFailed() ? 400 : 202);
        }

        $preview = $this->importService->getPreview($job);

        return response()->json($preview);
    }

    /**
     * Apply corrections to parsed elements
     */
    public function correct(Request $request, string $jobUuid): JsonResponse
    {
        $request->validate([
            'corrections' => 'required|array',
            'corrections.*.index' => 'required|integer|min:0',
            'corrections.*.label' => 'string|nullable',
            'corrections.*.key' => 'string|nullable',
            'corrections.*.detected_field_type' => 'string|nullable',
            'corrections.*.options' => 'array|nullable',
            'corrections.*.validations' => 'array|nullable',
        ]);

        $job = $this->importService->getJobStatus($jobUuid);

        if (!$job) {
            return response()->json(['error' => 'Import job not found'], 404);
        }

        $tenantId = $request->user()->current_tenant_id;
        if ($job->tenant_id !== $tenantId) {
            return response()->json(['error' => 'Import job not found'], 404);
        }

        if (!$job->isParsed()) {
            return response()->json(['error' => 'Import must be parsed before corrections'], 400);
        }

        // Transform corrections array to indexed format
        $corrections = [];
        foreach ($request->input('corrections') as $correction) {
            $index = $correction['index'];
            unset($correction['index']);
            $corrections[$index] = $correction;
        }

        try {
            $job = $this->importService->applyCorrections($job, $corrections);

            return response()->json([
                'message' => 'Corrections applied',
                'job_uuid' => $job->job_uuid,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Commit import and create form
     */
    public function commit(Request $request, string $jobUuid): JsonResponse
    {
        $request->validate([
            'title' => 'string|max:255|nullable',
        ]);

        $job = $this->importService->getJobStatus($jobUuid);

        if (!$job) {
            return response()->json(['error' => 'Import job not found'], 404);
        }

        $tenantId = $request->user()->current_tenant_id;
        if ($job->tenant_id !== $tenantId) {
            return response()->json(['error' => 'Import job not found'], 404);
        }

        if (!$job->canCommit()) {
            return response()->json([
                'error' => 'Import cannot be committed',
                'status' => $job->status,
                'message' => $job->error_message,
            ], 400);
        }

        try {
            $form = $this->importService->commitImport(
                $job,
                $request->user(),
                $request->input('title')
            );

            return response()->json([
                'message' => 'Form created successfully',
                'form' => [
                    'id' => $form->id,
                    'title' => $form->title,
                    'slug' => $form->slug,
                    'status' => $form->status,
                ],
            ], 201);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Cancel import
     */
    public function cancel(Request $request, string $jobUuid): JsonResponse
    {
        $job = $this->importService->getJobStatus($jobUuid);

        if (!$job) {
            return response()->json(['error' => 'Import job not found'], 404);
        }

        $tenantId = $request->user()->current_tenant_id;
        if ($job->tenant_id !== $tenantId) {
            return response()->json(['error' => 'Import job not found'], 404);
        }

        if ($job->isComplete()) {
            return response()->json(['error' => 'Cannot cancel completed import'], 400);
        }

        $this->importService->cancelImport($job);

        return response()->json(['message' => 'Import cancelled']);
    }

    /**
     * List import jobs
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->user()->current_tenant_id;

        $jobs = ImportJob::where('tenant_id', $tenantId)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['id', 'job_uuid', 'import_type', 'status', 'original_filename', 'created_at', 'completed_at']);

        return response()->json(['jobs' => $jobs]);
    }
}
