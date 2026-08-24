# Sample Import Files

This directory contains sample files for testing the document import feature.

## DOCX Format

The DOCX parser recognizes:

1. **Headings** → Section titles
2. **Text ending with `:` or `?`** → Field labels
3. **Bulleted/numbered lists** → Select/checkbox options
4. **Tables** → Structured field definitions

### Example DOCX Structure

```
# Contact Form

Personal Information:
- Name:
- Email Address:
- Phone Number:

Preferences:
What is your preferred contact method?
○ Email
○ Phone
○ Mail

Additional Comments:
```

## XLSX Format

### Header Row Format

First row contains field labels, subsequent rows contain sample data:

| Name | Email | Phone | Department |
|------|-------|-------|------------|
| John | john@example.com | 555-1234 | Sales |
| Jane | jane@example.com | 555-5678 | Marketing |

### Mapping Format

Explicit field definitions with columns:

| field_type | key | label | options | validation |
|------------|-----|-------|---------|------------|
| text | name | Full Name | | required |
| email | email | Email Address | | required |
| select | department | Department | Sales,Marketing,Engineering | |

## Creating Test Files

Use the test helper trait to generate sample files programmatically:

```php
use Tests\Feature\Import\CreatesSampleFiles;

$docxPath = $this->createSampleDocx([
    ['type' => 'heading', 'text' => 'Contact Form', 'level' => 1],
    ['type' => 'paragraph', 'text' => 'Name:'],
    ['type' => 'paragraph', 'text' => 'Email Address:'],
]);

$xlsxPath = $this->createSampleXlsx([
    ['Name', 'Email', 'Phone'],
    ['John Doe', 'john@example.com', '555-1234'],
]);
```
