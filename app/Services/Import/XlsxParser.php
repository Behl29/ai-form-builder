<?php

namespace App\Services\Import;

use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Cell\Cell;

class XlsxParser
{
    private const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10MB
    private const ALLOWED_MIME_TYPES = [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-excel',
    ];
    private const ALLOWED_EXTENSIONS = ['xlsx', 'xls'];

    // Expected columns for explicit mapping sheet
    private const MAPPING_COLUMNS = [
        'section', 'field_type', 'key', 'label', 'placeholder',
        'help_text', 'required', 'options', 'validation',
    ];

    // Minimum columns to detect header-row format
    private const MIN_HEADER_COLUMNS = 2;

    public function parse(UploadedFile $file): ImportResult
    {
        $validationErrors = $this->validateFile($file);
        if (!empty($validationErrors)) {
            return ImportResult::failure($validationErrors);
        }

        try {
            $spreadsheet = IOFactory::load($file->getRealPath());
        } catch (\Exception $e) {
            return ImportResult::failure(['Failed to parse Excel file: ' . $e->getMessage()]);
        }

        $sheet = $spreadsheet->getActiveSheet();
        $format = $this->detectFormat($sheet);

        return match ($format) {
            'mapping' => $this->parseMappingFormat($sheet, $file),
            'header' => $this->parseHeaderFormat($sheet, $file),
            default => ImportResult::failure(['Unable to detect Excel format. Expected mapping columns or header row.']),
        };
    }

    private function validateFile(UploadedFile $file): array
    {
        $errors = [];

        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, self::ALLOWED_EXTENSIONS)) {
            $errors[] = 'File must have .xlsx or .xls extension';
        }

        $mimeType = $file->getMimeType();
        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES)) {
            $errors[] = "Invalid MIME type: {$mimeType}. Expected Excel document.";
        }

        if ($file->getSize() > self::MAX_FILE_SIZE) {
            $errors[] = 'File exceeds maximum size of 10MB';
        }

        return $errors;
    }

    private function detectFormat(Worksheet $sheet): string
    {
        $headerRow = $this->getHeaderRow($sheet);
        if (empty($headerRow)) {
            return 'unknown';
        }

        $normalizedHeaders = array_map(fn($h) => strtolower(trim($h)), $headerRow);

        // Check for mapping format (has field_type or type column)
        $mappingIndicators = ['field_type', 'type', 'fieldtype'];
        foreach ($mappingIndicators as $indicator) {
            if (in_array($indicator, $normalizedHeaders)) {
                return 'mapping';
            }
        }

        // Check for header format (simple column names that look like field labels)
        if (count($headerRow) >= self::MIN_HEADER_COLUMNS) {
            return 'header';
        }

        return 'unknown';
    }

    private function getHeaderRow(Worksheet $sheet): array
    {
        $headers = [];
        $highestColumn = $sheet->getHighestColumn();
        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $value = $sheet->getCellByColumnAndRow($col, 1)->getValue();
            if ($value !== null && $value !== '') {
                $headers[] = (string) $value;
            }
        }

        return $headers;
    }

    private function parseMappingFormat(Worksheet $sheet, UploadedFile $file): ImportResult
    {
        $headers = $this->getHeaderRow($sheet);
        $normalizedHeaders = array_map(fn($h) => strtolower(trim($h)), $headers);

        // Map column indices
        $columnMap = [];
        foreach (self::MAPPING_COLUMNS as $col) {
            $index = array_search($col, $normalizedHeaders);
            if ($index !== false) {
                $columnMap[$col] = $index;
            }
            // Also check for 'type' as alias for 'field_type'
            if ($col === 'field_type' && $index === false) {
                $index = array_search('type', $normalizedHeaders);
                if ($index !== false) {
                    $columnMap[$col] = $index;
                }
            }
        }

        $elements = [];
        $warnings = [];
        $highestRow = $sheet->getHighestRow();

        for ($row = 2; $row <= $highestRow; $row++) {
            $rowData = $this->getRowData($sheet, $row, count($headers));

            // Skip empty rows
            if ($this->isEmptyRow($rowData)) {
                continue;
            }

            $element = $this->parseMapppingRow($rowData, $columnMap, $row);
            if ($element !== null) {
                $elements[] = $element;
            }
        }

        return new ImportResult(
            success: true,
            elements: $elements,
            warnings: $warnings,
            metadata: [
                'format' => 'mapping',
                'row_count' => count($elements),
                'file_name' => $file->getClientOriginalName(),
            ],
        );
    }

    private function parseMapppingRow(array $rowData, array $columnMap, int $rowNum): ?ParsedElement
    {
        $getValue = fn($col) => isset($columnMap[$col]) ? trim($rowData[$columnMap[$col]] ?? '') : '';

        $fieldType = $getValue('field_type');
        $label = $getValue('label');

        if (empty($fieldType) && empty($label)) {
            return null;
        }

        // Validate and normalize field type
        $normalizedType = $this->normalizeFieldType($fieldType);
        $warnings = [];

        if ($fieldType && $normalizedType !== $fieldType) {
            $warnings[] = "Field type '{$fieldType}' normalized to '{$normalizedType}'";
        }

        // Parse options
        $optionsStr = $getValue('options');
        $options = $this->parseOptions($optionsStr);

        // Parse required
        $requiredStr = $getValue('required');
        $required = $this->parseBoolean($requiredStr);

        // Parse validation
        $validationStr = $getValue('validation');
        $validations = $this->parseValidation($validationStr);
        if ($required) {
            $validations['required'] = true;
        }

        $key = $getValue('key');
        if (empty($key)) {
            $key = $this->generateKey($label);
        }

        return new ParsedElement(
            type: ParsedElement::TYPE_QUESTION,
            sourceText: "Row {$rowNum}: {$label}",
            detectedSection: $getValue('section') ?: null,
            detectedFieldType: $normalizedType ?: 'text',
            label: $label,
            key: $key,
            options: $options,
            validations: $validations,
            warnings: $warnings,
            metadata: [
                'placeholder' => $getValue('placeholder'),
                'help_text' => $getValue('help_text'),
                'row_number' => $rowNum,
            ],
        );
    }

    private function parseHeaderFormat(Worksheet $sheet, UploadedFile $file): ImportResult
    {
        $headers = $this->getHeaderRow($sheet);
        $elements = [];
        $warnings = [];

        // Sample data rows to infer types
        $sampleData = $this->getSampleData($sheet, 5);

        foreach ($headers as $index => $header) {
            $header = trim($header);
            if (empty($header)) {
                continue;
            }

            $samples = array_column($sampleData, $index);
            $inferredType = $this->inferTypeFromSamples($header, $samples);

            $elements[] = new ParsedElement(
                type: ParsedElement::TYPE_QUESTION,
                sourceText: $header,
                detectedFieldType: $inferredType,
                label: $header,
                key: $this->generateKey($header),
                metadata: [
                    'column_index' => $index,
                    'sample_values' => array_slice($samples, 0, 3),
                ],
                warnings: $inferredType === 'text' && !empty($samples)
                    ? ['Type inferred as text - verify if correct']
                    : [],
            );
        }

        return new ImportResult(
            success: true,
            elements: $elements,
            warnings: $warnings,
            metadata: [
                'format' => 'header',
                'column_count' => count($elements),
                'file_name' => $file->getClientOriginalName(),
            ],
            suggestedTitle: $this->inferTitleFromFilename($file->getClientOriginalName()),
        );
    }

    private function getSampleData(Worksheet $sheet, int $maxRows): array
    {
        $data = [];
        $highestRow = min($sheet->getHighestRow(), $maxRows + 1);
        $highestColumn = $sheet->getHighestColumn();
        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

        for ($row = 2; $row <= $highestRow; $row++) {
            $rowData = $this->getRowData($sheet, $row, $highestColumnIndex);
            if (!$this->isEmptyRow($rowData)) {
                $data[] = $rowData;
            }
        }

        return $data;
    }

    private function getRowData(Worksheet $sheet, int $row, int $columnCount): array
    {
        $data = [];
        for ($col = 1; $col <= $columnCount; $col++) {
            $data[] = (string) ($sheet->getCellByColumnAndRow($col, $row)->getValue() ?? '');
        }
        return $data;
    }

    private function isEmptyRow(array $rowData): bool
    {
        foreach ($rowData as $cell) {
            if (trim($cell) !== '') {
                return false;
            }
        }
        return true;
    }

    private function inferTypeFromSamples(string $header, array $samples): string
    {
        // First check header for type hints
        $headerType = $this->inferTypeFromHeader($header);
        if ($headerType !== 'text') {
            return $headerType;
        }

        // Filter out empty samples
        $samples = array_filter($samples, fn($s) => trim($s) !== '');
        if (empty($samples)) {
            return 'text';
        }

        // Check all samples for type patterns
        $typeScores = [
            'email' => 0,
            'phone' => 0,
            'date' => 0,
            'number' => 0,
            'url' => 0,
            'text' => 0,
        ];

        foreach ($samples as $sample) {
            $sample = trim($sample);

            if (filter_var($sample, FILTER_VALIDATE_EMAIL)) {
                $typeScores['email']++;
            } elseif (preg_match('/^[\d\s\-\+\(\)]{7,}$/', $sample)) {
                $typeScores['phone']++;
            } elseif ($this->isDateValue($sample)) {
                $typeScores['date']++;
            } elseif (is_numeric($sample)) {
                $typeScores['number']++;
            } elseif (filter_var($sample, FILTER_VALIDATE_URL)) {
                $typeScores['url']++;
            } else {
                $typeScores['text']++;
            }
        }

        // Find the type with highest score (excluding text)
        $maxScore = 0;
        $inferredType = 'text';

        foreach ($typeScores as $type => $score) {
            if ($type !== 'text' && $score > $maxScore && $score >= count($samples) / 2) {
                $maxScore = $score;
                $inferredType = $type;
            }
        }

        return $inferredType;
    }

    private function inferTypeFromHeader(string $header): string
    {
        $header = strtolower($header);

        if (preg_match('/\b(email|e-mail)\b/', $header)) {
            return 'email';
        }
        if (preg_match('/\b(phone|telephone|mobile|cell)\b/', $header)) {
            return 'phone';
        }
        if (preg_match('/\b(date|birthday|dob)\b/', $header)) {
            return 'date';
        }
        if (preg_match('/\b(age|number|amount|quantity|count)\b/', $header)) {
            return 'number';
        }
        if (preg_match('/\b(url|website|link)\b/', $header)) {
            return 'url';
        }

        return 'text';
    }

    private function isDateValue(string $value): bool
    {
        // Common date patterns
        $patterns = [
            '/^\d{4}-\d{2}-\d{2}$/',           // YYYY-MM-DD
            '/^\d{2}\/\d{2}\/\d{4}$/',         // MM/DD/YYYY or DD/MM/YYYY
            '/^\d{2}-\d{2}-\d{4}$/',           // MM-DD-YYYY or DD-MM-YYYY
            '/^\d{1,2}\s+\w+\s+\d{4}$/',       // D Month YYYY
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $value)) {
                return true;
            }
        }

        // Try strtotime
        $timestamp = strtotime($value);
        return $timestamp !== false && $timestamp > 0;
    }

    private function normalizeFieldType(string $type): string
    {
        $type = strtolower(trim($type));

        $typeMap = [
            'string' => 'text',
            'textfield' => 'text',
            'input' => 'text',
            'textbox' => 'text',
            'multiline' => 'textarea',
            'longtext' => 'textarea',
            'int' => 'number',
            'integer' => 'number',
            'float' => 'number',
            'decimal' => 'number',
            'dropdown' => 'select',
            'combobox' => 'select',
            'choice' => 'radio',
            'boolean' => 'checkbox',
            'bool' => 'checkbox',
            'yesno' => 'checkbox',
            'checkboxes' => 'checkbox_group',
            'multi_checkbox' => 'checkbox_group',
            'upload' => 'file',
            'attachment' => 'file',
            'title' => 'heading',
            'header' => 'heading',
            'stars' => 'rating',
            'score' => 'rating',
            'link' => 'url',
            'website' => 'url',
            'mail' => 'email',
            'tel' => 'phone',
            'telephone' => 'phone',
            'mobile' => 'phone',
        ];

        return $typeMap[$type] ?? $type;
    }

    private function parseOptions(string $optionsStr): array
    {
        if (empty($optionsStr)) {
            return [];
        }

        // Try JSON first
        $decoded = json_decode($optionsStr, true);
        if (is_array($decoded)) {
            return $this->normalizeOptions($decoded);
        }

        // Try comma-separated
        $parts = preg_split('/[,;|]/', $optionsStr);
        $options = [];

        foreach ($parts as $part) {
            $part = trim($part);
            if (!empty($part)) {
                // Check for value:label format
                if (str_contains($part, ':')) {
                    [$value, $label] = explode(':', $part, 2);
                    $options[] = ['value' => trim($value), 'label' => trim($label)];
                } else {
                    $options[] = ['value' => $this->generateOptionValue($part), 'label' => $part];
                }
            }
        }

        return $options;
    }

    private function normalizeOptions(array $options): array
    {
        $normalized = [];

        foreach ($options as $key => $value) {
            if (is_array($value) && isset($value['value']) && isset($value['label'])) {
                $normalized[] = $value;
            } elseif (is_string($value)) {
                $normalized[] = ['value' => is_string($key) ? $key : $this->generateOptionValue($value), 'label' => $value];
            }
        }

        return $normalized;
    }

    private function parseBoolean(string $value): bool
    {
        $value = strtolower(trim($value));
        return in_array($value, ['true', 'yes', '1', 'y', 'required']);
    }

    private function parseValidation(string $validationStr): array
    {
        if (empty($validationStr)) {
            return [];
        }

        // Try JSON first
        $decoded = json_decode($validationStr, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Parse simple validation rules
        $validations = [];
        $rules = preg_split('/[,;|]/', $validationStr);

        foreach ($rules as $rule) {
            $rule = trim($rule);
            if (empty($rule)) {
                continue;
            }

            if (preg_match('/^min[:\s]*(\d+)$/i', $rule, $m)) {
                $validations['min'] = (int) $m[1];
            } elseif (preg_match('/^max[:\s]*(\d+)$/i', $rule, $m)) {
                $validations['max'] = (int) $m[1];
            } elseif (preg_match('/^minLength[:\s]*(\d+)$/i', $rule, $m)) {
                $validations['minLength'] = (int) $m[1];
            } elseif (preg_match('/^maxLength[:\s]*(\d+)$/i', $rule, $m)) {
                $validations['maxLength'] = (int) $m[1];
            } elseif (strtolower($rule) === 'required') {
                $validations['required'] = true;
            }
        }

        return $validations;
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

    private function inferTitleFromFilename(string $filename): string
    {
        $name = pathinfo($filename, PATHINFO_FILENAME);
        $name = preg_replace('/[-_]/', ' ', $name);
        return ucwords(trim($name));
    }
}
