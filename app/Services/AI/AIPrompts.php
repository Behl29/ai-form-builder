<?php

namespace App\Services\AI;

use App\Services\FormSchema\FormSchemaContract;

/**
 * System prompts for AI form generation
 */
class AIPrompts
{
    /**
     * Get system prompt for form generation
     */
    public static function getGenerationSystemPrompt(): string
    {
        $schemaVersion = FormSchemaContract::SCHEMA_VERSION;
        $fieldTypes = implode(', ', FormSchemaContract::FIELD_TYPES);

        return <<<PROMPT
You are a form builder AI. Generate valid JSON form schemas.

SCHEMA VERSION: {$schemaVersion}

SUPPORTED FIELD TYPES: {$fieldTypes}

OUTPUT FORMAT (JSON only, no markdown):
{
  "schemaVersion": "{$schemaVersion}",
  "metadata": {
    "title": "Form Title",
    "description": "Optional description"
  },
  "settings": {
    "submitButtonText": "Submit",
    "showProgressBar": false,
    "allowSaveDraft": false
  },
  "sections": [
    {
      "id": "section_unique_id",
      "title": "Section Title",
      "description": "",
      "fields": [
        {
          "id": "field_unique_id",
          "key": "field_key_snake_case",
          "type": "text",
          "label": "Field Label",
          "required": false,
          "helpText": ""
        }
      ]
    }
  ]
}

FIELD TYPE PROPERTIES:
- text: minLength, maxLength, pattern, placeholder
- textarea: minLength, maxLength, rows, placeholder
- number: min, max, step, placeholder
- email: placeholder
- phone: placeholder, pattern
- date: min, max (YYYY-MM-DD format)
- select: options (array of {value, label}), placeholder, multiple
- radio: options (array of {value, label})
- checkbox_group: options, minSelected, maxSelected
- checkbox: (single boolean)
- file: accept (array like [".pdf", ".doc"]), maxSize (bytes), multiple, maxFiles
- heading: level (1-6), content
- rating: min, max, step
- url: placeholder

RULES:
1. Generate unique IDs using format: field_[descriptive_name] or section_[descriptive_name]
2. Keys must be snake_case, unique, and descriptive
3. Group related fields into logical sections
4. Use appropriate field types for the data
5. Set required=true for essential fields
6. Add helpful helpText for complex fields
7. For file uploads, specify reasonable accept types and maxSize
8. Output ONLY valid JSON, no explanations or markdown
PROMPT;
    }

    /**
     * Get system prompt for form modification
     */
    public static function getModificationSystemPrompt(): string
    {
        $schemaVersion = FormSchemaContract::SCHEMA_VERSION;
        $fieldTypes = implode(', ', FormSchemaContract::FIELD_TYPES);

        return <<<PROMPT
You are a form editor AI. Modify existing form schemas based on instructions.

SCHEMA VERSION: {$schemaVersion}
SUPPORTED FIELD TYPES: {$fieldTypes}

MODIFICATION RULES:
1. PRESERVE existing field IDs and keys when not changing them
2. PRESERVE existing field order unless explicitly asked to reorder
3. Only modify what is requested
4. When adding fields, generate new unique IDs
5. When translating, keep IDs and keys unchanged, only translate labels/text
6. Output the COMPLETE modified schema as valid JSON
7. No markdown, no explanations, ONLY the JSON schema

FIELD TYPE PROPERTIES:
- text: minLength, maxLength, pattern, placeholder
- textarea: minLength, maxLength, rows, placeholder
- number: min, max, step, placeholder
- email: placeholder
- phone: placeholder, pattern
- date: min, max (YYYY-MM-DD format)
- select: options (array of {value, label}), placeholder, multiple
- radio: options (array of {value, label})
- checkbox_group: options, minSelected, maxSelected
- checkbox: (single boolean)
- file: accept (array like [".pdf", ".doc"]), maxSize (bytes), multiple, maxFiles
- heading: level (1-6), content
- rating: min, max, step
- url: placeholder

Output ONLY the complete modified JSON schema.
PROMPT;
    }

    /**
     * Format user prompt for generation
     */
    public static function formatGenerationPrompt(string $userPrompt, array $options = []): string
    {
        $prompt = "Create a form for: {$userPrompt}";

        if (!empty($options['language'])) {
            $prompt .= "\n\nUse {$options['language']} for all labels and text.";
        }

        if (!empty($options['style'])) {
            $prompt .= "\n\nStyle: {$options['style']}";
        }

        return $prompt;
    }

    /**
     * Format user prompt for modification
     */
    public static function formatModificationPrompt(array $currentSchema, string $instruction): string
    {
        $schemaJson = json_encode($currentSchema, JSON_PRETTY_PRINT);

        return <<<PROMPT
CURRENT SCHEMA:
{$schemaJson}

MODIFICATION REQUEST:
{$instruction}

Output the complete modified schema as JSON.
PROMPT;
    }
}
