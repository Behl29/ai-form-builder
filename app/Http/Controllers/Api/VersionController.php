<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Models\FormVersion;
use App\Services\VersionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VersionController extends Controller
{
    public function __construct(private VersionService $versionService)
    {
    }

    /**
     * Get version history for a form
     */
    public function index(Request $request, Form $form): JsonResponse
    {
        $this->authorize('view', $form);

        $history = $this->versionService->getVersionHistory($form);

        return response()->json([
            'data' => $history,
            'current_version_id' => $form->current_version_id,
        ]);
    }

    /**
     * Get a specific version (preview)
     */
    public function show(Request $request, Form $form, FormVersion $version): JsonResponse
    {
        $this->authorize('view', $form);

        if ($version->form_id !== $form->id) {
            abort(404, 'Version not found');
        }

        return response()->json([
            'data' => [
                'id' => $version->id,
                'version_number' => $version->version_number,
                'schema_version' => $version->schema_version,
                'schema' => $version->schema,
                'change_type' => $version->change_type,
                'change_summary' => $version->change_summary,
                'is_published' => $version->is_published,
                'published_at' => $version->published_at?->toISOString(),
                'restored_from_version_id' => $version->restored_from_version_id,
                'created_by' => $version->creator ? [
                    'id' => $version->creator->id,
                    'name' => $version->creator->name,
                ] : null,
                'created_at' => $version->created_at->toISOString(),
            ],
        ]);
    }

    /**
     * Compare two versions
     */
    public function compare(Request $request, Form $form): JsonResponse
    {
        $this->authorize('view', $form);

        $request->validate([
            'old_version_id' => 'required|integer',
            'new_version_id' => 'required|integer',
        ]);

        $oldVersion = FormVersion::where('form_id', $form->id)
            ->where('id', $request->old_version_id)
            ->firstOrFail();

        $newVersion = FormVersion::where('form_id', $form->id)
            ->where('id', $request->new_version_id)
            ->firstOrFail();

        $comparison = $this->versionService->compareVersions($oldVersion, $newVersion);

        return response()->json([
            'data' => $comparison,
        ]);
    }

    /**
     * Rollback to a previous version (creates new version)
     */
    public function rollback(Request $request, Form $form, FormVersion $version): JsonResponse
    {
        $this->authorize('update', $form);

        if ($version->form_id !== $form->id) {
            abort(404, 'Version not found');
        }

        $newVersion = $this->versionService->rollback($form, $version, $request->user());

        return response()->json([
            'message' => "Rolled back to version {$version->version_number}. Created new version {$newVersion->version_number}.",
            'data' => [
                'id' => $newVersion->id,
                'version_number' => $newVersion->version_number,
                'restored_from_version_id' => $newVersion->restored_from_version_id,
                'change_type' => $newVersion->change_type,
                'created_at' => $newVersion->created_at->toISOString(),
            ],
        ]);
    }

    /**
     * Publish a version
     */
    public function publish(Request $request, Form $form, FormVersion $version): JsonResponse
    {
        $this->authorize('update', $form);

        if ($version->form_id !== $form->id) {
            abort(404, 'Version not found');
        }

        if ($version->is_published) {
            return response()->json([
                'message' => 'Version is already published',
            ], 422);
        }

        $version->publish();

        $form->update([
            'status' => Form::STATUS_PUBLISHED,
            'current_version_id' => $version->id,
        ]);

        return response()->json([
            'message' => "Version {$version->version_number} published successfully",
            'data' => [
                'id' => $version->id,
                'version_number' => $version->version_number,
                'is_published' => true,
                'published_at' => $version->published_at->toISOString(),
            ],
        ]);
    }
}
