<?php

namespace App\Services;

use App\Models\Form;
use App\Models\FormVersion;
use App\Models\User;
use Illuminate\Support\Collection;

class VersionService
{
    /**
     * Create a new immutable version
     */
    public function createVersion(
        Form $form,
        User $user,
        array $schema,
        string $changeType = FormVersion::CHANGE_UPDATED,
        ?int $restoredFromVersionId = null
    ): FormVersion {
        $previousVersion = $form->currentVersion;
        $changeSummary = $previousVersion
            ? $this->generateChangeSummary($previousVersion->schema ?? [], $schema)
            : ['type' => 'initial', 'message' => 'Initial version created'];

        $version = FormVersion::create([
            'form_id' => $form->id,
            'created_by' => $user->id,
            'version_number' => $form->getNextVersionNumber(),
            'schema_version' => FormVersion::CURRENT_SCHEMA_VERSION,
            'schema' => $schema,
            'change_type' => $changeType,
            'change_summary' => $changeSummary,
            'restored_from_version_id' => $restoredFromVersionId,
            'is_published' => false,
        ]);

        $form->update(['current_version_id' => $version->id]);

        return $version;
    }

    /**
     * Rollback to a previous version (creates NEW version with old schema)
     */
    public function rollback(Form $form, FormVersion $targetVersion, User $user): FormVersion
    {
        if ($targetVersion->form_id !== $form->id) {
            throw new \InvalidArgumentException('Version does not belong to this form');
        }

        return $this->createVersion(
            $form,
            $user,
            $targetVersion->schema,
            FormVersion::CHANGE_RESTORED,
            $targetVersion->id
        );
    }

    /**
     * Compare two versions and return detailed diff
     */
    public function compareVersions(FormVersion $oldVersion, FormVersion $newVersion): array
    {
        $oldSchema = $oldVersion->schema ?? [];
        $newSchema = $newVersion->schema ?? [];

        return [
            'old_version' => $oldVersion->version_number,
            'new_version' => $newVersion->version_number,
            'fields' => $this->compareFields($oldSchema, $newSchema),
            'sections' => $this->compareSections($oldSchema, $newSchema),
            'settings' => $this->compareSettings($oldSchema, $newSchema),
        ];
    }

    /**
     * Generate change summary between two schemas
     */
    public function generateChangeSummary(array $oldSchema, array $newSchema): array
    {
        $diff = $this->compareFields($oldSchema, $newSchema);
        $sectionDiff = $this->compareSections($oldSchema, $newSchema);

        return [
            'fields_added' => count($diff['added']),
            'fields_removed' => count($diff['removed']),
            'fields_modified' => count($diff['modified']),
            'sections_added' => count($sectionDiff['added']),
            'sections_removed' => count($sectionDiff['removed']),
            'sections_modified' => count($sectionDiff['modified']),
        ];
    }

    /**
     * Compare fields between schemas
     */
    private function compareFields(array $oldSchema, array $newSchema): array
    {
        $oldFields = $this->extractFields($oldSchema);
        $newFields = $this->extractFields($newSchema);

        $oldKeys = array_keys($oldFields);
        $newKeys = array_keys($newFields);

        $added = [];
        $removed = [];
        $modified = [];

        // Find added fields
        foreach (array_diff($newKeys, $oldKeys) as $key) {
            $added[$key] = [
                'key' => $key,
                'label' => $newFields[$key]['label'] ?? $key,
                'type' => $newFields[$key]['type'] ?? 'unknown',
            ];
        }

        // Find removed fields
        foreach (array_diff($oldKeys, $newKeys) as $key) {
            $removed[$key] = [
                'key' => $key,
                'label' => $oldFields[$key]['label'] ?? $key,
                'type' => $oldFields[$key]['type'] ?? 'unknown',
            ];
        }

        // Find modified fields
        foreach (array_intersect($oldKeys, $newKeys) as $key) {
            $changes = $this->compareFieldDetails($oldFields[$key], $newFields[$key]);
            if (!empty($changes)) {
                $modified[$key] = [
                    'key' => $key,
                    'label' => $newFields[$key]['label'] ?? $key,
                    'changes' => $changes,
                ];
            }
        }

        return compact('added', 'removed', 'modified');
    }

    /**
     * Compare individual field details
     */
    private function compareFieldDetails(array $oldField, array $newField): array
    {
        $changes = [];
        $compareKeys = ['label', 'type', 'required', 'placeholder', 'helpText', 'options', 'conditions', 'min', 'max', 'minLength', 'maxLength', 'pattern'];

        foreach ($compareKeys as $key) {
            $oldValue = $oldField[$key] ?? null;
            $newValue = $newField[$key] ?? null;

            if ($oldValue !== $newValue) {
                $changes[$key] = [
                    'old' => $oldValue,
                    'new' => $newValue,
                ];
            }
        }

        return $changes;
    }

    /**
     * Compare sections between schemas
     */
    private function compareSections(array $oldSchema, array $newSchema): array
    {
        $oldSections = $this->extractSections($oldSchema);
        $newSections = $this->extractSections($newSchema);

        $oldIds = array_keys($oldSections);
        $newIds = array_keys($newSections);

        $added = [];
        $removed = [];
        $modified = [];

        foreach (array_diff($newIds, $oldIds) as $id) {
            $added[$id] = [
                'id' => $id,
                'title' => $newSections[$id]['title'] ?? 'Untitled',
            ];
        }

        foreach (array_diff($oldIds, $newIds) as $id) {
            $removed[$id] = [
                'id' => $id,
                'title' => $oldSections[$id]['title'] ?? 'Untitled',
            ];
        }

        foreach (array_intersect($oldIds, $newIds) as $id) {
            $oldSection = $oldSections[$id];
            $newSection = $newSections[$id];
            $changes = [];

            if (($oldSection['title'] ?? '') !== ($newSection['title'] ?? '')) {
                $changes['title'] = ['old' => $oldSection['title'] ?? '', 'new' => $newSection['title'] ?? ''];
            }
            if (($oldSection['description'] ?? '') !== ($newSection['description'] ?? '')) {
                $changes['description'] = ['old' => $oldSection['description'] ?? '', 'new' => $newSection['description'] ?? ''];
            }

            $oldFieldCount = count($oldSection['fields'] ?? []);
            $newFieldCount = count($newSection['fields'] ?? []);
            if ($oldFieldCount !== $newFieldCount) {
                $changes['field_count'] = ['old' => $oldFieldCount, 'new' => $newFieldCount];
            }

            if (!empty($changes)) {
                $modified[$id] = ['id' => $id, 'title' => $newSection['title'] ?? 'Untitled', 'changes' => $changes];
            }
        }

        return compact('added', 'removed', 'modified');
    }

    /**
     * Compare settings between schemas
     */
    private function compareSettings(array $oldSchema, array $newSchema): array
    {
        $oldSettings = $oldSchema['settings'] ?? [];
        $newSettings = $newSchema['settings'] ?? [];
        $changes = [];

        $allKeys = array_unique(array_merge(array_keys($oldSettings), array_keys($newSettings)));

        foreach ($allKeys as $key) {
            $oldValue = $oldSettings[$key] ?? null;
            $newValue = $newSettings[$key] ?? null;

            if ($oldValue !== $newValue) {
                $changes[$key] = ['old' => $oldValue, 'new' => $newValue];
            }
        }

        return $changes;
    }

    /**
     * Extract all fields from schema indexed by key
     */
    private function extractFields(array $schema): array
    {
        $fields = [];
        foreach ($schema['sections'] ?? [] as $section) {
            foreach ($section['fields'] ?? [] as $field) {
                if (isset($field['key'])) {
                    $fields[$field['key']] = $field;
                }
            }
        }
        return $fields;
    }

    /**
     * Extract sections indexed by id
     */
    private function extractSections(array $schema): array
    {
        $sections = [];
        foreach ($schema['sections'] ?? [] as $section) {
            $id = $section['id'] ?? uniqid();
            $sections[$id] = $section;
        }
        return $sections;
    }

    /**
     * Get version history with metadata
     */
    public function getVersionHistory(Form $form): Collection
    {
        return $form->versions()
            ->with('creator:id,name,email')
            ->orderByDesc('version_number')
            ->get()
            ->map(fn($v) => [
                'id' => $v->id,
                'version_number' => $v->version_number,
                'schema_version' => $v->schema_version,
                'change_type' => $v->change_type,
                'change_summary' => $v->change_summary,
                'is_published' => $v->is_published,
                'published_at' => $v->published_at?->toISOString(),
                'restored_from_version_id' => $v->restored_from_version_id,
                'created_by' => $v->creator ? [
                    'id' => $v->creator->id,
                    'name' => $v->creator->name,
                ] : null,
                'created_at' => $v->created_at->toISOString(),
            ]);
    }
}
