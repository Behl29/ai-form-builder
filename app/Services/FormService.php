<?php

namespace App\Services;

use App\Exceptions\SchemaValidationException;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\User;
use App\Services\FormSchema\FormSchemaContract;
use App\Services\FormSchema\FormSchemaValidator;
use Illuminate\Support\Facades\DB;

class FormService
{
    public function __construct(
        private FormSchemaValidator $validator,
        private TenantService $tenantService
    ) {}

    public function create(User $user, array $data): Form
    {
        $schema = $data['schema'] ?? FormSchemaContract::defaultSchema($data['title'] ?? 'Untitled Form');

        $this->validator->validate($schema);
        $this->validator->validateConditionReferences($schema);

        return DB::transaction(function () use ($user, $data, $schema) {
            $form = Form::create([
                'tenant_id' => $this->tenantService->current()->id,
                'created_by' => $user->id,
                'title' => $data['title'] ?? $schema['metadata']['title'] ?? 'Untitled Form',
                'description' => $data['description'] ?? $schema['metadata']['description'] ?? null,
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
            // Update form metadata
            $form->update([
                'title' => $data['title'] ?? $form->title,
                'description' => $data['description'] ?? $form->description,
                'success_message' => $data['success_message'] ?? $form->success_message,
                'settings' => $data['settings'] ?? $form->settings,
            ]);

            // Create new version if schema changed
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
        return DB::transaction(function () use ($form, $user) {
            $currentVersion = $form->currentVersion;

            if (!$currentVersion) {
                throw new \RuntimeException('Form has no version to publish');
            }

            // If current version is already published, create a new published version
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

    public function archive(Form $form): Form
    {
        $form->update(['status' => Form::STATUS_ARCHIVED]);
        return $form;
    }

    public function restore(Form $form): Form
    {
        $form->update(['status' => Form::STATUS_DRAFT]);
        return $form;
    }

    public function restoreVersion(Form $form, User $user, FormVersion $version): Form
    {
        if ($version->form_id !== $form->id) {
            throw new \InvalidArgumentException('Version does not belong to this form');
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
            throw new \RuntimeException('Form has no version to duplicate');
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
}
