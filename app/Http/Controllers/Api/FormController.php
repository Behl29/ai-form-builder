<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\SchemaValidationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Form\StoreFormRequest;
use App\Http\Requests\Form\UpdateFormRequest;
use App\Http\Requests\Form\UpdateSchemaRequest;
use App\Models\Form;
use App\Models\FormVersion;
use App\Services\FormService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FormController extends Controller
{
    public function __construct(private FormService $formService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $forms = Form::with('currentVersion:id,form_id,version_number,schema_version')
            ->when($request->status, fn($q, $status) => $q->where('status', $status))
            ->orderByDesc('updated_at')
            ->paginate($request->per_page ?? 15);

        return response()->json($forms);
    }

    public function store(StoreFormRequest $request): JsonResponse
    {
        try {
            $form = $this->formService->create($request->user(), $request->validated());
            return response()->json($form, 201);
        } catch (SchemaValidationException $e) {
            return response()->json($e->toArray(), 422);
        }
    }

    public function show(Form $form): JsonResponse
    {
        $this->authorize('view', $form);

        return response()->json(
            $form->load(['currentVersion', 'creator:id,name,email'])
        );
    }

    public function update(UpdateFormRequest $request, Form $form): JsonResponse
    {
        $this->authorize('update', $form);

        try {
            $form = $this->formService->update($form, $request->user(), $request->validated());
            return response()->json($form);
        } catch (SchemaValidationException $e) {
            return response()->json($e->toArray(), 422);
        }
    }

    public function destroy(Form $form): JsonResponse
    {
        $this->authorize('delete', $form);

        $form->delete();

        return response()->json(['message' => 'Form deleted successfully']);
    }

    public function updateSchema(UpdateSchemaRequest $request, Form $form): JsonResponse
    {
        $this->authorize('update', $form);

        try {
            $version = $this->formService->updateSchema($form, $request->user(), $request->schema);
            return response()->json([
                'message' => 'Schema updated successfully',
                'version' => $version,
            ]);
        } catch (SchemaValidationException $e) {
            return response()->json($e->toArray(), 422);
        }
    }

    public function publish(Request $request, Form $form): JsonResponse
    {
        $this->authorize('publish', $form);

        $form = $this->formService->publish($form, $request->user());

        return response()->json([
            'message' => 'Form published successfully',
            'form' => $form,
        ]);
    }

    public function archive(Form $form): JsonResponse
    {
        $this->authorize('update', $form);

        $form = $this->formService->archive($form);

        return response()->json([
            'message' => 'Form archived successfully',
            'form' => $form,
        ]);
    }

    public function restore(Form $form): JsonResponse
    {
        $this->authorize('update', $form);

        $form = $this->formService->restore($form);

        return response()->json([
            'message' => 'Form restored successfully',
            'form' => $form,
        ]);
    }

    public function duplicate(Request $request, Form $form): JsonResponse
    {
        $this->authorize('duplicate', $form);

        $newForm = $this->formService->duplicate(
            $form,
            $request->user(),
            $request->input('title')
        );

        return response()->json([
            'message' => 'Form duplicated successfully',
            'form' => $newForm,
        ], 201);
    }

    public function versions(Form $form): JsonResponse
    {
        $this->authorize('view', $form);

        $versions = $form->versions()
            ->with('creator:id,name')
            ->select(['id', 'form_id', 'version_number', 'schema_version', 'change_type', 'is_published', 'published_at', 'created_by', 'created_at'])
            ->get();

        return response()->json($versions);
    }

    public function showVersion(Form $form, FormVersion $version): JsonResponse
    {
        $this->authorize('view', $form);

        if ($version->form_id !== $form->id) {
            return response()->json(['message' => 'Version not found'], 404);
        }

        return response()->json($version->load('creator:id,name'));
    }

    public function restoreVersion(Request $request, Form $form, FormVersion $version): JsonResponse
    {
        $this->authorize('update', $form);

        if ($version->form_id !== $form->id) {
            return response()->json(['message' => 'Version not found'], 404);
        }

        $form = $this->formService->restoreVersion($form, $request->user(), $version);

        return response()->json([
            'message' => 'Version restored successfully',
            'form' => $form,
        ]);
    }
}
