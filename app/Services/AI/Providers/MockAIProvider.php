<?php

namespace App\Services\AI\Providers;

use App\Services\AI\AIResponse;
use App\Services\AI\FormAIProvider;
use App\Services\FormSchema\FormSchemaContract;

/**
 * Mock AI Provider for Testing
 * 
 * Returns deterministic responses based on prompt keywords.
 */
class MockAIProvider implements FormAIProvider
{
    private ?AIResponse $forcedResponse = null;
    private array $callLog = [];

    public function generateForm(string $prompt, array $options = []): AIResponse
    {
        $this->callLog[] = ['method' => 'generateForm', 'prompt' => $prompt, 'options' => $options];

        if ($this->forcedResponse) {
            return $this->forcedResponse;
        }

        // Simulate latency
        $latencyMs = rand(100, 500);

        // Check for test scenarios in prompt
        if (str_contains(strtolower($prompt), 'timeout')) {
            return AIResponse::failure('Request timed out', AIResponse::ERROR_TIMEOUT, null, 0, 0, 30000);
        }

        if (str_contains(strtolower($prompt), 'rate limit')) {
            return AIResponse::failure('Rate limit exceeded', AIResponse::ERROR_RATE_LIMIT, null, 0, 0, $latencyMs);
        }

        if (str_contains(strtolower($prompt), 'auth error')) {
            return AIResponse::failure('Authentication failed', AIResponse::ERROR_AUTH_FAILURE, null, 0, 0, $latencyMs);
        }

        if (str_contains(strtolower($prompt), 'invalid json')) {
            return AIResponse::failure(
                'Failed to parse JSON',
                AIResponse::ERROR_INVALID_JSON,
                'This is not valid JSON {{{',
                100,
                50,
                $latencyMs
            );
        }

        if (str_contains(strtolower($prompt), 'unsupported field')) {
            return AIResponse::success(
                $this->getSchemaWithUnsupportedField(),
                json_encode($this->getSchemaWithUnsupportedField()),
                100,
                200,
                $latencyMs
            );
        }

        if (str_contains(strtolower($prompt), 'provider error')) {
            return AIResponse::failure('Internal server error', AIResponse::ERROR_PROVIDER_ERROR, null, 0, 0, $latencyMs);
        }

        // Default: generate valid schema based on prompt
        $schema = $this->generateSchemaFromPrompt($prompt, $options);

        return AIResponse::success(
            $schema,
            json_encode($schema),
            150,
            300,
            $latencyMs,
            ['model' => 'mock-model']
        );
    }

    public function modifyForm(array $currentSchema, string $instruction, array $options = []): AIResponse
    {
        $this->callLog[] = ['method' => 'modifyForm', 'schema' => $currentSchema, 'instruction' => $instruction];

        if ($this->forcedResponse) {
            return $this->forcedResponse;
        }

        $latencyMs = rand(100, 500);

        // Check for test scenarios
        if (str_contains(strtolower($instruction), 'timeout')) {
            return AIResponse::failure('Request timed out', AIResponse::ERROR_TIMEOUT, null, 0, 0, 30000);
        }

        // Apply modification based on instruction
        $modifiedSchema = $this->applyModification($currentSchema, $instruction);

        return AIResponse::success(
            $modifiedSchema,
            json_encode($modifiedSchema),
            200,
            400,
            $latencyMs,
            ['model' => 'mock-model']
        );
    }

    public function getProviderName(): string
    {
        return 'mock';
    }

    public function getModelName(): string
    {
        return 'mock-model-v1';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * Force a specific response for testing
     */
    public function setForcedResponse(?AIResponse $response): void
    {
        $this->forcedResponse = $response;
    }

    /**
     * Get call log for assertions
     */
    public function getCallLog(): array
    {
        return $this->callLog;
    }

    /**
     * Clear call log
     */
    public function clearCallLog(): void
    {
        $this->callLog = [];
    }

    private function generateSchemaFromPrompt(string $prompt, array $options): array
    {
        $title = 'Generated Form';

        // Extract title from prompt
        if (preg_match('/(?:create|build|make)\s+(?:a|an)?\s*(.+?)\s*form/i', $prompt, $matches)) {
            $title = ucwords(trim($matches[1])) . ' Form';
        }

        $schema = [
            'schemaVersion' => FormSchemaContract::SCHEMA_VERSION,
            'metadata' => [
                'title' => $title,
                'description' => 'AI-generated form',
            ],
            'settings' => [
                'submitButtonText' => 'Submit',
                'showProgressBar' => false,
                'allowSaveDraft' => false,
            ],
            'sections' => [],
        ];

        // Add sections based on keywords in prompt
        if (str_contains(strtolower($prompt), 'personal') || str_contains(strtolower($prompt), 'contact')) {
            $schema['sections'][] = $this->getPersonalInfoSection();
        }

        if (str_contains(strtolower($prompt), 'education')) {
            $schema['sections'][] = $this->getEducationSection();
        }

        if (str_contains(strtolower($prompt), 'skill')) {
            $schema['sections'][] = $this->getSkillsSection();
        }

        if (str_contains(strtolower($prompt), 'resume') || str_contains(strtolower($prompt), 'upload')) {
            $schema['sections'][] = $this->getFileUploadSection();
        }

        // Default section if none matched
        if (empty($schema['sections'])) {
            $schema['sections'][] = [
                'id' => 'section_default',
                'title' => 'Information',
                'fields' => [
                    ['id' => 'field_name', 'key' => 'name', 'type' => 'text', 'label' => 'Name', 'required' => true],
                    ['id' => 'field_email', 'key' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true],
                ],
            ];
        }

        return $schema;
    }

    private function getPersonalInfoSection(): array
    {
        return [
            'id' => 'section_personal',
            'title' => 'Personal Information',
            'fields' => [
                ['id' => 'field_full_name', 'key' => 'full_name', 'type' => 'text', 'label' => 'Full Name', 'required' => true],
                ['id' => 'field_email', 'key' => 'email', 'type' => 'email', 'label' => 'Email Address', 'required' => true],
                ['id' => 'field_phone', 'key' => 'phone', 'type' => 'phone', 'label' => 'Phone Number', 'required' => false],
            ],
        ];
    }

    private function getEducationSection(): array
    {
        return [
            'id' => 'section_education',
            'title' => 'Education',
            'fields' => [
                [
                    'id' => 'field_degree',
                    'key' => 'degree',
                    'type' => 'select',
                    'label' => 'Highest Degree',
                    'required' => true,
                    'options' => [
                        ['value' => 'high_school', 'label' => 'High School'],
                        ['value' => 'bachelors', 'label' => "Bachelor's Degree"],
                        ['value' => 'masters', 'label' => "Master's Degree"],
                        ['value' => 'phd', 'label' => 'PhD'],
                    ],
                ],
                ['id' => 'field_institution', 'key' => 'institution', 'type' => 'text', 'label' => 'Institution Name', 'required' => true],
                ['id' => 'field_graduation_year', 'key' => 'graduation_year', 'type' => 'number', 'label' => 'Graduation Year', 'min' => 1950, 'max' => 2030],
            ],
        ];
    }

    private function getSkillsSection(): array
    {
        return [
            'id' => 'section_skills',
            'title' => 'Skills',
            'fields' => [
                [
                    'id' => 'field_skills',
                    'key' => 'skills',
                    'type' => 'checkbox_group',
                    'label' => 'Select your skills',
                    'options' => [
                        ['value' => 'communication', 'label' => 'Communication'],
                        ['value' => 'teamwork', 'label' => 'Teamwork'],
                        ['value' => 'leadership', 'label' => 'Leadership'],
                        ['value' => 'problem_solving', 'label' => 'Problem Solving'],
                    ],
                ],
                ['id' => 'field_experience_years', 'key' => 'experience_years', 'type' => 'number', 'label' => 'Years of Experience', 'min' => 0, 'max' => 50],
            ],
        ];
    }

    private function getFileUploadSection(): array
    {
        return [
            'id' => 'section_documents',
            'title' => 'Documents',
            'fields' => [
                [
                    'id' => 'field_resume',
                    'key' => 'resume',
                    'type' => 'file',
                    'label' => 'Upload Resume',
                    'required' => true,
                    'accept' => ['.pdf', '.doc', '.docx'],
                    'maxSize' => 5242880, // 5MB
                    'helpText' => 'PDF or Word document, max 5MB',
                ],
            ],
        ];
    }

    private function getSchemaWithUnsupportedField(): array
    {
        return [
            'schemaVersion' => FormSchemaContract::SCHEMA_VERSION,
            'metadata' => ['title' => 'Test Form', 'description' => ''],
            'settings' => ['submitButtonText' => 'Submit'],
            'sections' => [
                [
                    'id' => 'section_1',
                    'title' => 'Section 1',
                    'fields' => [
                        ['id' => 'field_1', 'key' => 'name', 'type' => 'text', 'label' => 'Name'],
                        ['id' => 'field_2', 'key' => 'magic', 'type' => 'magic_field', 'label' => 'Magic'], // Unsupported
                    ],
                ],
            ],
        ];
    }

    private function applyModification(array $schema, string $instruction): array
    {
        $instruction = strtolower($instruction);

        // Add section
        if (str_contains($instruction, 'add') && str_contains($instruction, 'section')) {
            $sectionTitle = 'New Section';
            if (preg_match('/add\s+(?:a\s+)?(.+?)\s+section/i', $instruction, $matches)) {
                $sectionTitle = ucwords(trim($matches[1]));
            }

            $schema['sections'][] = [
                'id' => 'section_' . uniqid(),
                'title' => $sectionTitle,
                'fields' => [],
            ];
        }

        // Make field required
        if (str_contains($instruction, 'required')) {
            foreach ($schema['sections'] as &$section) {
                foreach ($section['fields'] as &$field) {
                    $fieldName = strtolower($field['label'] ?? $field['key'] ?? '');
                    if (str_contains($instruction, $fieldName)) {
                        $field['required'] = true;
                    }
                }
            }
        }

        // Translate labels (mock translation)
        if (str_contains($instruction, 'translate') && str_contains($instruction, 'hindi')) {
            $translations = [
                'Full Name' => 'पूरा नाम',
                'Email Address' => 'ईमेल पता',
                'Phone Number' => 'फ़ोन नंबर',
                'Submit' => 'जमा करें',
            ];

            foreach ($schema['sections'] as &$section) {
                foreach ($section['fields'] as &$field) {
                    if (isset($translations[$field['label']])) {
                        $field['label'] = $translations[$field['label']];
                    }
                }
            }

            if (isset($translations[$schema['settings']['submitButtonText']])) {
                $schema['settings']['submitButtonText'] = $translations[$schema['settings']['submitButtonText']];
            }
        }

        // Add file upload
        if (str_contains($instruction, 'upload') || str_contains($instruction, 'file')) {
            $maxSize = 5242880; // Default 5MB
            if (preg_match('/(\d+)\s*mb/i', $instruction, $matches)) {
                $maxSize = (int) $matches[1] * 1024 * 1024;
            }

            $accept = ['.pdf'];
            if (str_contains($instruction, 'pdf')) {
                $accept = ['.pdf'];
            }

            // Add to last section or create new
            $lastIndex = count($schema['sections']) - 1;
            if ($lastIndex >= 0) {
                $schema['sections'][$lastIndex]['fields'][] = [
                    'id' => 'field_upload_' . uniqid(),
                    'key' => 'file_upload',
                    'type' => 'file',
                    'label' => 'File Upload',
                    'accept' => $accept,
                    'maxSize' => $maxSize,
                ];
            }
        }

        return $schema;
    }
}
