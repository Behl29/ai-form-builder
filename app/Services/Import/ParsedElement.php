<?php

namespace App\Services\Import;

/**
 * Represents a parsed element from a document
 */
class ParsedElement
{
    public const TYPE_HEADING = 'heading';
    public const TYPE_QUESTION = 'question';
    public const TYPE_CHECKBOX_LIST = 'checkbox_list';
    public const TYPE_CHOICE_LIST = 'choice_list';
    public const TYPE_TEXT_INPUT = 'text_input';
    public const TYPE_TABLE = 'table';
    public const TYPE_PARAGRAPH = 'paragraph';
    public const TYPE_UNKNOWN = 'unknown';

    public function __construct(
        public string $type,
        public string $sourceText,
        public ?string $detectedSection = null,
        public ?string $detectedFieldType = null,
        public ?string $label = null,
        public ?string $key = null,
        public array $options = [],
        public array $validations = [],
        public array $warnings = [],
        public bool $parseable = true,
        public ?int $headingLevel = null,
        public array $metadata = [],
    ) {}

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'source_text' => $this->sourceText,
            'detected_section' => $this->detectedSection,
            'detected_field_type' => $this->detectedFieldType,
            'label' => $this->label,
            'key' => $this->key,
            'options' => $this->options,
            'validations' => $this->validations,
            'warnings' => $this->warnings,
            'parseable' => $this->parseable,
            'heading_level' => $this->headingLevel,
            'metadata' => $this->metadata,
        ];
    }
}
