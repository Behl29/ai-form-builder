<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\SchemaValidationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Form\StoreFormRequest;
use App\Http\Requests\Form\UpdateFormRequest;
use App\Http\Requests\Form\UpdateSchemaRequest;
use App\Http\Resources\FormCollection;
use App\Http\Resources\FormResource;
use App\Http\Resources\FormVersionResource;
use App\Models\Form;
use App\Models\FormVersion;
use App\Services\FormService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FormController extends Controller
{
    public function __construct(private FormService $formService)
    {
    }

    public function index(Request $request): FormCollection
    {
        $this->authorize('viewAny', Form::class);

        $forms = $this->formService->list([
            'status' => $request->query('status'),
            'search' => $request->query('search'),
            'per_page' => $request->query('per_page', 15),
        ]);

        return new FormCollection($forms);
    }

    public function store(StoreFormRequest $request): JsonResponse
    {
        $this->authorize('create', Form::class);

        try {
            $form = $this->formService->create($request->user(), $request->validated());
            return response()->json([
                'message' => 'Form created successfully.',
                'data' => new FormResource($form),
            ], 201);
        } catch (SchemaValidationException $e) {
            return response()->json($e->toArray(), 422);
        }
    }

    public function show(Form $form): JsonResponse
    {
        $this->authorize('view', $form);

        return response()->json([
            'data' => new FormResource($form->load(['currentVersion', 'creator'])),
        ]);
    }

    public function update(UpdateFormRequest $request, Form $form): JsonResponse
    {
        $this->authorize('update', $form);

        try {
            $form = $this->formService->update($form, $request->user(), $request->validated());
            return response()->json([
                'message' => 'Form updated successfully.',
                'data' => new FormResource($form),
            ]);
        } catch (SchemaValidationException $e) {
            return response()->json($e->toArray(), 422);
        }
    }

    public function destroy(Form $form): JsonResponse
    {
        $this->authorize('delete', $form);

        $this->formService->delete($form);

        return response()->json(['message' => 'Form deleted successfully.']);
    }

    public function updateSchema(UpdateSchemaRequest $request, Form $form): JsonResponse
    {
        $this->authorize('update', $form);

        try {
            $version = $this->formService->updateSchema($form, $request->user(), $request->schema);
            return response()->json([
                'message' => 'Schema updated successfully.',
                'data' => new FormVersionResource($version),
            ]);
        } catch (SchemaValidationException $e) {
            return response()->json($e->toArray(), 422);
        }
    }

    public function publish(Request $request, Form $form): JsonResponse
    {
        $this->authorize('publish', $form);

        try {
            $form = $this->formService->publish($form, $request->user());
            return response()->json([
                'message' => 'Form published successfully.',
                'data' => new FormResource($form),
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (SchemaValidationException $e) {
            return response()->json($e->toArray(), 422);
        }
    }

    public function unpublish(Form $form): JsonResponse
    {
        $this->authorize('update', $form);

        try {
            $form = $this->formService->unpublish($form);
            return response()->json([
                'message' => 'Form unpublished successfully.',
                'data' => new FormResource($form),
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function archive(Form $form): JsonResponse
    {
        $this->authorize('update', $form);

        $form = $this->formService->archive($form);

        return response()->json([
            'message' => 'Form archived successfully.',
            'data' => new FormResource($form),
        ]);
    }

    public function restore(Form $form): JsonResponse
    {
        $this->authorize('update', $form);

        try {
            $form = $this->formService->restore($form);
            return response()->json([
                'message' => 'Form restored successfully.',
                'data' => new FormResource($form),
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function duplicate(Request $request, Form $form): JsonResponse
    {
        $this->authorize('duplicate', $form);

        try {
            $newForm = $this->formService->duplicate(
                $form,
                $request->user(),
                $request->input('title')
            );

            return response()->json([
                'message' => 'Form duplicated successfully.',
                'data' => new FormResource($newForm),
            ], 201);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function versions(Form $form): AnonymousResourceCollection
    {
        $this->authorize('view', $form);

        $versions = $form->versions()->with('creator')->get();

        return FormVersionResource::collection($versions);
    }

    public function showVersion(Form $form, FormVersion $version): JsonResponse
    {
        $this->authorize('view', $form);

        if ($version->form_id !== $form->id) {
            return response()->json(['message' => 'Version not found.'], 404);
        }

        return response()->json([
            'data' => new FormVersionResource($version->load('creator')),
        ]);
    }

    public function restoreVersion(Request $request, Form $form, FormVersion $version): JsonResponse
    {
        $this->authorize('update', $form);

        if ($version->form_id !== $form->id) {
            return response()->json(['message' => 'Version not found.'], 404);
        }

        $form = $this->formService->restoreVersion($form, $request->user(), $version);

        return response()->json([
            'message' => 'Version restored successfully.',
            'data' => new FormResource($form),
        ]);
    }
}
