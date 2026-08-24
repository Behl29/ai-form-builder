<?php

namespace App\Services\Import;

use Illuminate\Http\UploadedFile;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Element\AbstractElement;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\Title;
use PhpOffice\PhpWord\Element\ListItem;
use PhpOffice\PhpWord\Element\ListItemRun;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\Row;
use PhpOffice\PhpWord\Element\Cell;
use PhpOffice\PhpWord\Element\CheckBox;

class DocxParser
{
    private const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10MB
    private const ALLOWED_MIME_TYPES = [
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    private array $currentSection = [];
    private array $elements = [];
    private array $warnings = [];
    private ?string $suggestedTitle = null;

    public function parse(UploadedFile $file): ImportResult
    {
        // Validate file
        $validationErrors = $this->validateFile($file);
        if (!empty($validationErrors)) {
            return ImportResult::failure($validationErrors);
        }

        try {
            $phpWord = IOFactory::load($file->getRealPath());
        } catch (\Exception $e) {
            return ImportResult::failure(['Failed to parse DOCX file: ' . $e->getMessage()]);
        }

        $this->elements = [];
        $this->warnings = [];
        $this->currentSection = [];
        $this->suggestedTitle = null;

        // Process all sections
        foreach ($phpWord->getSections() as $section) {
            $this->processSection($section);
        }

        // Process any remaining list items
        $this->flushListBuffer();

        return new ImportResult(
            success: true,
            elements: $this->elements,
            warnings: $this->warnings,
            metadata: [
                'element_count' => count($this->elements),
                'file_name' => $file->getClientOriginalName(),
            ],
            suggestedTitle: $this->suggestedTitle,
        );
    }

    private function validateFile(UploadedFile $file): array
    {
        $errors = [];

        // Check extension
        if (strtolower($file->getClientOriginalExtension()) !== 'docx') {
            $errors[] = 'File must have .docx extension';
        }

        // Check MIME type
        $mimeType = $file->getMimeType();
        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES)) {
            $errors[] = "Invalid MIME type: {$mimeType}. Expected Word document.";
        }

        // Check size
        if ($file->getSize() > self::MAX_FILE_SIZE) {
            $errors[] = 'File exceeds maximum size of 10MB';
        }

        return $errors;
    }

    private array $listBuffer = [];
    private ?string $listType = null;

    private function processSection(Section $section): void
    {
        foreach ($section->getElements() as $element) {
            $this->processElement($element);
        }
    }

    private function processElement(AbstractElement $element): void
    {
        if ($element instanceof Title) {
            $this->flushListBuffer();
            $this->processTitle($element);
        } elseif ($element instanceof ListItem || $element instanceof ListItemRun) {
            $this->processListItem($element);
        } elseif ($element instanceof Table) {
            $this->flushListBuffer();
            $this->processTable($element);
        } elseif ($element instanceof TextRun) {
            $this->flushListBuffer();
            $this->processTextRun($element);
        } elseif ($element instanceof Text) {
            $this->flushListBuffer();
            $this->processText($element);
        } elseif ($element instanceof CheckBox) {
            $this->processCheckbox($element);
        }
    }

    private function processTitle(Title $title): void
    {
        $text = $this->extractText($title);
        if (empty(trim($text))) {
            return;
        }

        $level = $title->getDepth() ?? 1;

        // First heading might be the form title
        if ($this->suggestedTitle === null && $level <= 2) {
            $this->suggestedTitle = trim($text);
        }

        $this->elements[] = new ParsedElement(
            type: ParsedElement::TYPE_HEADING,
            sourceText: $text,
            detectedSection: trim($text),
            detectedFieldType: 'heading',
            label: trim($text),
            headingLevel: $level,
        );
    }

    private function processListItem($item): void
    {
        $text = $this->extractText($item);
        if (empty(trim($text))) {
            return;
        }

        // Detect if it's a checkbox item
        $isCheckbox = $this->detectCheckboxItem($text);

        if ($isCheckbox) {
            $this->listType = 'checkbox';
        } elseif ($this->listType === null) {
            $this->listType = 'choice';
        }

        $this->listBuffer[] = $this->cleanOptionText($text);
    }

    private function flushListBuffer(): void
    {
        if (empty($this->listBuffer)) {
            return;
        }

        $type = $this->listType === 'checkbox'
            ? ParsedElement::TYPE_CHECKBOX_LIST
            : ParsedElement::TYPE_CHOICE_LIST;

        $fieldType = $this->listType === 'checkbox' ? 'checkbox_group' : 'radio';

        // If more than 5 options, suggest select instead of radio
        if ($fieldType === 'radio' && count($this->listBuffer) > 5) {
            $fieldType = 'select';
        }

        $options = array_map(fn($opt) => [
            'value' => $this->generateOptionValue($opt),
            'label' => $opt,
        ], $this->listBuffer);

        $this->elements[] = new ParsedElement(
            type: $type,
            sourceText: implode("\n", $this->listBuffer),
            detectedFieldType: $fieldType,
            options: $options,
            warnings: count($this->listBuffer) > 20
                ? ['Large number of options detected']
                : [],
        );

        $this->listBuffer = [];
        $this->listType = null;
    }

    private function processTable(Table $table): void
    {
        $rows = $table->getRows();
        if (empty($rows)) {
            return;
        }

        $tableData = [];
        foreach ($rows as $row) {
            $rowData = [];
            foreach ($row->getCells() as $cell) {
                $rowData[] = $this->extractCellText($cell);
            }
            $tableData[] = $rowData;
        }

        // Detect if this is a question-answer table
        $isQuestionTable = $this->detectQuestionTable($tableData);

        if ($isQuestionTable) {
            $this->processQuestionTable($tableData);
        } else {
            // Generic table - might be structured data
            $this->elements[] = new ParsedElement(
                type: ParsedElement::TYPE_TABLE,
                sourceText: $this->tableToString($tableData),
                metadata: ['rows' => $tableData],
                warnings: ['Table structure detected - manual review recommended'],
                parseable: false,
            );
        }
    }

    private function processQuestionTable(array $tableData): void
    {
        // Skip header row if present
        $startRow = $this->hasHeaderRow($tableData) ? 1 : 0;

        for ($i = $startRow; $i < count($tableData); $i++) {
            $row = $tableData[$i];
            if (count($row) >= 2) {
                $question = trim($row[0]);
                $answerHint = trim($row[1] ?? '');

                if (!empty($question)) {
                    $fieldType = $this->inferFieldTypeFromText($question, $answerHint);

                    $this->elements[] = new ParsedElement(
                        type: ParsedElement::TYPE_QUESTION,
                        sourceText: $question,
                        detectedFieldType: $fieldType,
                        label: $this->cleanQuestionText($question),
                        key: $this->generateKey($question),
                        validations: $this->inferValidations($question, $fieldType),
                    );
                }
            }
        }
    }

    private function processTextRun(TextRun $textRun): void
    {
        $text = $this->extractText($textRun);
        $this->processTextContent($text);
    }

    private function processText(Text $textElement): void
    {
        $text = $textElement->getText();
        $this->processTextContent($text);
    }

    private function processTextContent(string $text): void
    {
        $text = trim($text);
        if (empty($text)) {
            return;
        }

        // Detect question patterns
        if ($this->isQuestion($text)) {
            $fieldType = $this->inferFieldTypeFromText($text);

            $this->elements[] = new ParsedElement(
                type: ParsedElement::TYPE_QUESTION,
                sourceText: $text,
                detectedFieldType: $fieldType,
                label: $this->cleanQuestionText($text),
                key: $this->generateKey($text),
                validations: $this->inferValidations($text, $fieldType),
            );
            return;
        }

        // Detect blank line / underscore patterns (text input)
        if ($this->isTextInputPlaceholder($text)) {
            $this->elements[] = new ParsedElement(
                type: ParsedElement::TYPE_TEXT_INPUT,
                sourceText: $text,
                detectedFieldType: 'text',
                label: $this->extractLabelFromPlaceholder($text),
                key: $this->generateKey($text),
            );
            return;
        }

        // Regular paragraph - might be instructions or unparseable
        $this->elements[] = new ParsedElement(
            type: ParsedElement::TYPE_PARAGRAPH,
            sourceText: $text,
            parseable: false,
            warnings: ['Paragraph text - may be instructions or context'],
        );
    }

    private function processCheckbox(CheckBox $checkbox): void
    {
        $text = $checkbox->getText() ?? '';
        $this->listType = 'checkbox';
        $this->listBuffer[] = $this->cleanOptionText($text);
    }

    private function extractText($element): string
    {
        if ($element instanceof Text) {
            return $element->getText() ?? '';
        }

        if ($element instanceof Title) {
            $text = '';
            foreach ($element->getElements() as $child) {
                $text .= $this->extractText($child);
            }
            return $text;
        }

        if ($element instanceof TextRun || $element instanceof ListItemRun) {
            $text = '';
            foreach ($element->getElements() as $child) {
                $text .= $this->extractText($child);
            }
            return $text;
        }

        if ($element instanceof ListItem) {
            return $element->getTextObject()?->getText() ?? '';
        }

        return '';
    }

    private function extractCellText(Cell $cell): string
    {
        $text = '';
        foreach ($cell->getElements() as $element) {
            $text .= $this->extractText($element) . ' ';
        }
        return trim($text);
    }

    private function isQuestion(string $text): bool
    {
        // Ends with question mark
        if (str_ends_with(trim($text), '?')) {
            return true;
        }

        // Ends with colon (label pattern)
        if (str_ends_with(trim($text), ':')) {
            return true;
        }

        // Common question patterns
        $patterns = [
            '/^(what|when|where|who|why|how|which|please|enter|provide|specify|select|choose)/i',
            '/^(your|the)\s+(name|email|phone|address|date)/i',
            '/\b(required|optional)\b/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }

        return false;
    }

    private function isTextInputPlaceholder(string $text): bool
    {
        // Multiple underscores
        if (preg_match('/_{3,}/', $text)) {
            return true;
        }

        // Blank line indicators
        if (preg_match('/\[.*blank.*\]/i', $text)) {
            return true;
        }

        return false;
    }

    private function inferFieldTypeFromText(string $text, string $hint = ''): string
    {
        $combined = strtolower($text . ' ' . $hint);

        // Email patterns
        if (preg_match('/\b(email|e-mail)\b/i', $combined)) {
            return 'email';
        }

        // Phone patterns
        if (preg_match('/\b(phone|telephone|mobile|cell)\b/i', $combined)) {
            return 'phone';
        }

        // Date patterns
        if (preg_match('/\b(date|birthday|dob|when|day|month|year)\b/i', $combined)) {
            return 'date';
        }

        // Number patterns
        if (preg_match('/\b(number|amount|quantity|age|count|how many)\b/i', $combined)) {
            return 'number';
        }

        // URL patterns
        if (preg_match('/\b(url|website|link|http)\b/i', $combined)) {
            return 'url';
        }

        // Rating patterns
        if (preg_match('/\b(rate|rating|score|stars?)\b/i', $combined)) {
            return 'rating';
        }

        // Long text patterns
        if (preg_match('/\b(describe|explain|comment|feedback|message|details|notes)\b/i', $combined)) {
            return 'textarea';
        }

        // Yes/No patterns
        if (preg_match('/\b(yes\s*\/?\s*no|agree|consent)\b/i', $combined)) {
            return 'checkbox';
        }

        return 'text';
    }

    private function inferValidations(string $text, string $fieldType): array
    {
        $validations = [];

        // Check for required indicators
        if (preg_match('/\*|required|\(required\)/i', $text)) {
            $validations['required'] = true;
        }

        return $validations;
    }

    private function detectCheckboxItem(string $text): bool
    {
        // Common checkbox indicators
        return preg_match('/^[\[\]☐☑✓✗○●□■]\s*/u', $text) ||
               preg_match('/^\(\s*\)\s*/', $text);
    }

    private function cleanOptionText(string $text): string
    {
        // Remove checkbox indicators
        $text = preg_replace('/^[\[\]☐☑✓✗○●□■]\s*/u', '', $text);
        $text = preg_replace('/^\(\s*\)\s*/', '', $text);
        return trim($text);
    }

    private function cleanQuestionText(string $text): string
    {
        // Remove trailing colon or asterisk
        $text = preg_replace('/[:\*]+$/', '', $text);
        // Remove (required) indicators
        $text = preg_replace('/\s*\(required\)\s*/i', '', $text);
        return trim($text);
    }

    private function generateKey(string $text): string
    {
        $key = strtolower($text);
        $key = preg_replace('/[^a-z0-9\s]/', '', $key);
        $key = preg_replace('/\s+/', '_', trim($key));
        $key = substr($key, 0, 50);

        if (empty($key) || !preg_match('/^[a-z]/', $key)) {
            $key = 'field_' . substr(md5($text), 0, 8);
        }

        return $key;
    }

    private function generateOptionValue(string $label): string
    {
        $value = strtolower($label);
        $value = preg_replace('/[^a-z0-9\s]/', '', $value);
        $value = preg_replace('/\s+/', '_', trim($value));
        return substr($value, 0, 50) ?: 'option_' . substr(md5($label), 0, 6);
    }

    private function detectQuestionTable(array $tableData): bool
    {
        if (count($tableData) < 2) {
            return false;
        }

        // Check if first column contains question-like text
        $questionCount = 0;
        foreach ($tableData as $row) {
            if (!empty($row[0]) && $this->isQuestion($row[0])) {
                $questionCount++;
            }
        }

        return $questionCount >= count($tableData) / 2;
    }

    private function hasHeaderRow(array $tableData): bool
    {
        if (empty($tableData)) {
            return false;
        }

        $firstRow = $tableData[0];
        $headerPatterns = ['question', 'field', 'label', 'answer', 'response', 'value'];

        foreach ($firstRow as $cell) {
            foreach ($headerPatterns as $pattern) {
                if (stripos($cell, $pattern) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    private function tableToString(array $tableData): string
    {
        $lines = [];
        foreach ($tableData as $row) {
            $lines[] = implode(' | ', $row);
        }
        return implode("\n", $lines);
    }

    private function extractLabelFromPlaceholder(string $text): string
    {
        // Extract text before underscores
        $parts = preg_split('/_{3,}/', $text);
        $label = trim($parts[0] ?? '');

        if (empty($label)) {
            return 'Text Input';
        }

        return $this->cleanQuestionText($label);
    }
}
