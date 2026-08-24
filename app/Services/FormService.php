<?php

namespace App\Services;

use App\Models\Form;
use App\Models\FormVersion;
use App\Models\User;
use App\Services\FormSchema\FormSchemaContract;
use App\Services\FormSchema\FormSchemaValidator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FormService
{
    public function __construct(
        private FormSchemaValidator $validator,
        private TenantService $tenantService
    ) {}

    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = Form::with('currentVersion:id,form_id,version_number,schema_version')
            ->orderByDesc('updated_at');

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        return $query->paginate($filters['per_page'] ?? 15);
    }

    public function create(User $user, array $data): Form
    {
        $schema = $data['schema'] ?? FormSchemaContract::defaultSchema($data['title'] ?? 'Untitled Form');

        $this->validator->validate($schema);
        $this->validator->validateConditionReferences($schema);

        return DB::transaction(function () use ($user, $data, $schema) {
            $slug = $data['slug'] ?? $this->generateUniqueSlug($data['title'] ?? 'form');

            $form = Form::create([
                'tenant_id' => $this->tenantService->current()->id,
                'created_by' => $user->id,
                'title' => $data['title'] ?? $schema['metadata']['title'] ?? 'Untitled Form',
                'description' => $data['description'] ?? $schema['metadata']['description'] ?? null,
                'slug' => $slug,
                'status' => Form::STATUS_DRAFT,
                'success_message' => $data['success_message'] ?? null,
                'settings' => $data['settings'] ?? null,
            ]);

            $version = FormVersion::create([
                'form_id' => $form->id,
                'created_by' => $user->id,
                'version_number' => 1,
                'schema_version' => FormSchemaContract::SCHEMA_VERSION,
                'schema' => $schema,
                'change_type' => FormVersion::CHANGE_CREATED,
            ]);

            $form->update(['current_version_id' => $version->id]);

            return $form->load('currentVersion');
        });
    }

    public function update(Form $form, User $user, array $data): Form
    {
        if (isset($data['schema'])) {
            $this->validator->validate($data['schema']);
            $this->validator->validateConditionReferences($data['schema']);
        }

        return DB::transaction(function () use ($form, $user, $data) {
            $updateData = array_filter([
                'title' => $data['title'] ?? null,
                'description' => array_key_exists('description', $data) ? $data['description'] : null,
                'slug' => $data['slug'] ?? null,
                'success_message' => array_key_exists('success_message', $data) ? $data['success_message'] : null,
                'settings' => array_key_exists('settings', $data) ? $data['settings'] : null,
            ], fn($v) => $v !== null);

            if (!empty($updateData)) {
                $form->update($updateData);
            }

            if (isset($data['schema'])) {
                $this->createNewVersion($form, $user, $data['schema'], FormVersion::CHANGE_UPDATED);
            }

            return $form->fresh('currentVersion');
        });
    }

    public function updateSchema(Form $form, User $user, array $schema): FormVersion
    {
        $this->validator->validate($schema);
        $this->validator->validateConditionReferences($schema);

        return DB::transaction(function () use ($form, $user, $schema) {
            return $this->createNewVersion($form, $user, $schema, FormVersion::CHANGE_UPDATED);
        });
    }

    public function publish(Form $form, User $user): Form
    {
        if ($form->isArchived()) {
            throw new \RuntimeException('Cannot publish an archived form. Restore it first.');
        }

        $currentVersion = $form->currentVersion;

        if (!$currentVersion) {
            throw new \RuntimeException('Form has no version to publish.');
        }

        // Validate schema before publishing
        $this->validator->validate($currentVersion->schema);
        $this->validator->validateConditionReferences($currentVersion->schema);

        return DB::transaction(function () use ($form, $user, $currentVersion) {
            if ($currentVersion->isPublished()) {
                $newVersion = $this->createNewVersion(
                    $form,
                    $user,
                    $currentVersion->schema,
                    FormVersion::CHANGE_PUBLISHED
                );
                $newVersion->publish();
            } else {
                $currentVersion->publish();
            }

            $form->update(['status' => Form::STATUS_PUBLISHED]);

            return $form->fresh('currentVersion');
        });
    }

    public function unpublish(Form $form): Form
    {
        if (!$form->isPublished()) {
            throw new \RuntimeException('Form is not published.');
        }

        $form->update(['status' => Form::STATUS_DRAFT]);

        return $form;
    }

    public function archive(Form $form): Form
    {
        $form->update(['status' => Form::STATUS_ARCHIVED]);
        return $form;
    }

    public function restore(Form $form): Form
    {
        if (!$form->isArchived()) {
            throw new \RuntimeException('Form is not archived.');
        }

        $form->update(['status' => Form::STATUS_DRAFT]);
        return $form;
    }

    public function delete(Form $form): bool
    {
        return DB::transaction(function () use ($form) {
            $form->versions()->delete();
            return $form->delete();
        });
    }

    public function restoreVersion(Form $form, User $user, FormVersion $version): Form
    {
        if ($version->form_id !== $form->id) {
            throw new \InvalidArgumentException('Version does not belong to this form.');
        }

        return DB::transaction(function () use ($form, $user, $version) {
            $this->createNewVersion($form, $user, $version->schema, FormVersion::CHANGE_RESTORED);
            return $form->fresh('currentVersion');
        });
    }

    public function duplicate(Form $form, User $user, ?string $newTitle = null): Form
    {
        $currentVersion = $form->currentVersion;

        if (!$currentVersion) {
            throw new \RuntimeException('Form has no version to duplicate.');
        }

        $schema = $currentVersion->schema;
        $title = $newTitle ?? $form->title . ' (Copy)';
        $schema['metadata']['title'] = $title;

        return $this->create($user, [
            'title' => $title,
            'description' => $form->description,
            'success_message' => $form->success_message,
            'settings' => $form->settings,
            'schema' => $schema,
        ]);
    }

    private function createNewVersion(Form $form, User $user, array $schema, string $changeType): FormVersion
    {
        $version = FormVersion::create([
            'form_id' => $form->id,
            'created_by' => $user->id,
            'version_number' => $form->getNextVersionNumber(),
            'schema_version' => FormSchemaContract::SCHEMA_VERSION,
            'schema' => $schema,
            'change_type' => $changeType,
        ]);

        $form->update(['current_version_id' => $version->id]);

        return $version;
    }

    private function generateUniqueSlug(string $title): string
    {
        $baseSlug = Str::slug($title);
        $slug = $baseSlug . '-' . Str::random(8);
        $tenantId = $this->tenantService->current()->id;

        while (Form::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('slug', $slug)->exists()) {
            $slug = $baseSlug . '-' . Str::random(8);
        }

        return $slug;
    }
}
