<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Services\SubmissionService;
use App\Services\SubmissionValidationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicFormController extends Controller
{
    public function __construct(
        private SubmissionService $submissionService
    ) {}

    public function show(string $slug): JsonResponse
    {
        $form = Form::withoutGlobalScopes()
            ->where('slug', $slug)
            ->where('status', Form::STATUS_PUBLISHED)
            ->with(['currentVersion' => function ($query) {
                $query->where('is_published', true);
            }])
            ->first();

        if (!$form || !$form->currentVersion) {
            return response()->json(['message' => 'Form not found.'], 404);
        }

        return response()->json([
            'data' => [
                'id' => $form->id,
                'title' => $form->title,
                'description' => $form->description,
                'slug' => $form->slug,
                'success_message' => $form->success_message ?? 'Thank you for your submission!',
                'schema' => $form->currentVersion->schema,
            ],
        ]);
    }

    public function submit(Request $request, string $slug): JsonResponse
    {
        $form = Form::withoutGlobalScopes()
            ->where('slug', $slug)
            ->where('status', Form::STATUS_PUBLISHED)
            ->with(['currentVersion' => function ($query) {
                $query->where('is_published', true);
            }])
            ->first();

        if (!$form || !$form->currentVersion) {
            return response()->json(['message' => 'Form not found.'], 404);
        }

        try {
            $submission = $this->submissionService->submit(
                $form,
                $request->except(['_token']),
                $request->allFiles(),
                $request
            );

            return response()->json([
                'message' => 'Submission received successfully.',
                'data' => [
                    'submission_id' => $submission->id,
                    'submitted_at' => $submission->submitted_at->toIso8601String(),
                ],
            ], 201);
        } catch (SubmissionValidationException $e) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $e->getErrors(),
            ], 422);
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
