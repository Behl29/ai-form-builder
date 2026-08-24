<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SubmissionCollection;
use App\Http\Resources\SubmissionResource;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\SubmissionFile;
use App\Services\SubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SubmissionController extends Controller
{
    public function __construct(
        private SubmissionService $submissionService
    ) {}

    public function index(Request $request, Form $form): SubmissionCollection
    {
        $this->authorize('view', $form);

        $submissions = $this->submissionService->list($form, [
            'search' => $request->query('search'),
            'from_date' => $request->query('from_date'),
            'to_date' => $request->query('to_date'),
            'per_page' => $request->query('per_page', 20),
        ]);

        return new SubmissionCollection($submissions);
    }

    public function show(Form $form, FormSubmission $submission): JsonResponse
    {
        $this->authorize('view', $form);

        if ($submission->form_id !== $form->id) {
            return response()->json(['message' => 'Submission not found.'], 404);
        }

        return response()->json([
            'data' => new SubmissionResource($submission->load(['formVersion', 'files'])),
        ]);
    }

    public function destroy(Form $form, FormSubmission $submission): JsonResponse
    {
        $this->authorize('delete', $form);

        if ($submission->form_id !== $form->id) {
            return response()->json(['message' => 'Submission not found.'], 404);
        }

        // Delete associated files
        foreach ($submission->files as $file) {
            $file->delete();
        }

        $submission->delete();

        return response()->json(['message' => 'Submission deleted successfully.']);
    }

    public function export(Request $request, Form $form): StreamedResponse
    {
        $this->authorize('view', $form);

        $versionId = $request->query('version_id');
        $csv = $this->submissionService->exportCsv($form, $versionId ? (int) $versionId : null);

        $filename = sprintf('%s-submissions-%s.csv', $form->slug, now()->format('Y-m-d'));

        return response()->streamDownload(function () use ($csv) {
            echo $csv;
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    public function downloadFile(Form $form, FormSubmission $submission, SubmissionFile $file): StreamedResponse|JsonResponse
    {
        $this->authorize('view', $form);

        if ($submission->form_id !== $form->id) {
            return response()->json(['message' => 'Submission not found.'], 404);
        }

        if ($file->form_submission_id !== $submission->id) {
            return response()->json(['message' => 'File not found.'], 404);
        }

        if (!$file->exists()) {
            return response()->json(['message' => 'File not found.'], 404);
        }

        return Storage::disk($file->disk)->download($file->path, $file->original_name);
    }
}
