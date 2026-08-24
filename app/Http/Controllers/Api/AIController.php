<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AIJob;
use App\Models\Form;
use App\Services\AI\AIFormService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AIController extends Controller
{
    public function __construct(
        private AIFormService $aiService
    ) {}

    /**
     * Queue AI form generation
     */
    public function generate(Request $request): JsonResponse
    {
        $request->validate([
            'prompt' => 'required|string|min:10|max:2000',
            'language' => 'nullable|string|max:50',
        ]);

        if (!$this->aiService->isProviderAvailable()) {
            return response()->json([
                'error' => 'AI provider is not configured',
            ], 503);
        }

        $job = $this->aiService->queueGeneration(
            $request->user(),
            $request->user()->current_tenant_id,
            $request->input('prompt'),
            array_filter([
                'language' => $request->input('language'),
            ])
        );

        return response()->json([
            'message' => 'Form generation queued',
            'job_uuid' => $job->job_uuid,
            'status' => $job->status,
        ], 202);
    }

    /**
     * Queue AI form modification
     */
    public function modify(Request $request, Form $form): JsonResponse
    {
        $this->authorize('update', $form);

        $request->validate([
            'instruction' => 'required|string|min:5|max:2000',
        ]);

        if (!$this->aiService->isProviderAvailable()) {
            return response()->json([
                'error' => 'AI provider is not configured',
            ], 503);
        }

        $job = $this->aiService->queueModification(
            $request->user(),
            $form,
            $request->input('instruction'),
            $request->input('options', [])
        );

        return response()->json([
            'message' => 'Form modification queued',
            'job_uuid' => $job->job_uuid,
            'status' => $job->status,
        ], 202);
    }

    /**
     * Get AI job status
     */
    public function status(Request $request, string $jobUuid): JsonResponse
    {
        $job = AIJob::where('job_uuid', $jobUuid)
            ->where('tenant_id', $request->user()->current_tenant_id)
            ->first();

        if (!$job) {
            return response()->json(['error' => 'Job not found'], 404);
        }

        $response = [
            'job_uuid' => $job->job_uuid,
            'status' => $job->status,
            'request_type' => $job->request_type,
            'form_id' => $job->form_id,
            'created_at' => $job->created_at->toISOString(),
        ];

        if ($job->isSucceeded()) {
            $response['result_schema'] = $job->result_schema;
            $response['repair_log'] = $job->repair_log;
            $response['completed_at'] = $job->completed_at?->toISOString();
            $response['metrics'] = [
                'input_tokens' => $job->input_tokens,
                'output_tokens' => $job->output_tokens,
                'latency_ms' => $job->latency_ms,
            ];
        }

        if ($job->isFailed()) {
            $response['error_type'] = $job->error_type;
            $response['error_message'] = $job->error_message;
            $response['validation_errors'] = $job->validation_errors;
            $response['completed_at'] = $job->completed_at?->toISOString();
        }

        if ($job->isRunning()) {
            $response['started_at'] = $job->started_at?->toISOString();
        }

        return response()->json($response);
    }

    /**
     * Preview diff for AI modification
     */
    public function previewDiff(Request $request, Form $form): JsonResponse
    {
        $this->authorize('view', $form);

        $request->validate([
            'job_uuid' => 'required|uuid',
        ]);

        $job = AIJob::where('job_uuid', $request->input('job_uuid'))
            ->where('form_id', $form->id)
            ->where('status', AIJob::STATUS_SUCCEEDED)
            ->first();

        if (!$job) {
            return response()->json(['error' => 'Job not found or not completed'], 404);
        }

        $diff = $this->aiService->generateDiff($form, $job->result_schema);

        return response()->json([
            'diff' => $diff,
            'new_schema' => $job->result_schema,
        ]);
    }

    /**
     * Accept AI-generated schema
     */
    public function accept(Request $request, Form $form): JsonResponse
    {
        $this->authorize('update', $form);

        $request->validate([
            'job_uuid' => 'required|uuid',
        ]);

        $job = AIJob::where('job_uuid', $request->input('job_uuid'))
            ->where('tenant_id', $request->user()->current_tenant_id)
            ->where('status', AIJob::STATUS_SUCCEEDED)
            ->first();

        if (!$job) {
            return response()->json(['error' => 'Job not found or not completed'], 404);
        }

        $form = $this->aiService->acceptGeneratedSchema(
            $form,
            $request->user(),
            $job->result_schema,
            $job
        );

        return response()->json([
            'message' => 'Schema accepted and new version created',
            'form' => [
                'id' => $form->id,
                'title' => $form->title,
                'current_version_id' => $form->current_version_id,
            ],
        ]);
    }

    /**
     * Create new form from AI generation
     */
    public function createForm(Request $request): JsonResponse
    {
        $request->validate([
            'job_uuid' => 'required|uuid',
            'title' => 'nullable|string|max:255',
        ]);

        $job = AIJob::where('job_uuid', $request->input('job_uuid'))
            ->where('tenant_id', $request->user()->current_tenant_id)
            ->where('request_type', AIJob::TYPE_GENERATE)
            ->where('status', AIJob::STATUS_SUCCEEDED)
            ->first();

        if (!$job) {
            return response()->json(['error' => 'Job not found or not completed'], 404);
        }

        $schema = $job->result_schema;

        // Override title if provided
        if ($request->filled('title')) {
            $schema['metadata']['title'] = $request->input('title');
        }

        $form = $this->aiService->createFormFromSchema(
            $request->user(),
            $request->user()->current_tenant_id,
            $schema,
            $job
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
    }

    /**
     * List AI jobs for current user
     */
    public function listJobs(Request $request): JsonResponse
    {
        $jobs = AIJob::where('tenant_id', $request->user()->current_tenant_id)
            ->where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn($job) => [
                'job_uuid' => $job->job_uuid,
                'request_type' => $job->request_type,
                'status' => $job->status,
                'form_id' => $job->form_id,
                'provider' => $job->provider,
                'model' => $job->model,
                'created_at' => $job->created_at->toISOString(),
                'completed_at' => $job->completed_at?->toISOString(),
            ]);

        return response()->json(['data' => $jobs]);
    }

    /**
     * Get provider info
     */
    public function providerInfo(): JsonResponse
    {
        return response()->json($this->aiService->getProviderInfo());
    }
}
