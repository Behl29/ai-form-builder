# Form Schema Documentation

## Overview

The form schema is a JSON structure that defines the complete structure of a form, including metadata, settings, sections, and fields. This document describes the schema contract version 1.0.

## Schema Structure

```json
{
  "schemaVersion": "1.0",
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
      "id": "section_uuid",
      "title": "Section Title",
      "description": "Optional description",
      "fields": [...]
    }
  ]
}
```

## Field Types

### Text Input Fields

#### text
Standard single-line text input.

```json
{
  "id": "field_uuid",
  "key": "full_name",
  "type": "text",
  "label": "Full Name",
  "placeholder": "Enter your name",
  "required": true,
  "minLength": 2,
  "maxLength": 100,
  "pattern": "^[a-zA-Z ]+$",
  "defaultValue": "",
  "helpText": "Enter your legal name",
  "customError": "Please enter a valid name"
}
```

#### textarea
Multi-line text input.

```json
{
  "id": "field_uuid",
  "key": "message",
  "type": "textarea",
  "label": "Message",
  "rows": 5,
  "minLength": 10,
  "maxLength": 1000
}
```

#### email
Email address input with built-in validation.

```json
{
  "id": "field_uuid",
  "key": "email",
  "type": "email",
  "label": "Email Address",
  "placeholder": "you@example.com"
}
```

#### phone
Phone number input.

```json
{
  "id": "field_uuid",
  "key": "phone",
  "type": "phone",
  "label": "Phone Number",
  "pattern": "^\\+?[0-9]{10,15}$"
}
```

#### url
URL input with validation.

```json
{
  "id": "field_uuid",
  "key": "website",
  "type": "url",
  "label": "Website",
  "placeholder": "https://example.com"
}
```

### Numeric Fields

#### number
Numeric input with optional range.

```json
{
  "id": "field_uuid",
  "key": "age",
  "type": "number",
  "label": "Age",
  "min": 0,
  "max": 150,
  "step": 1
}
```

#### rating
Star/numeric rating input.

```json
{
  "id": "field_uuid",
  "key": "satisfaction",
  "type": "rating",
  "label": "Satisfaction",
  "min": 1,
  "max": 5,
  "step": 1
}
```

### Date Fields

#### date
Date picker input.

```json
{
  "id": "field_uuid",
  "key": "birth_date",
  "type": "date",
  "label": "Date of Birth",
  "min": "1900-01-01",
  "max": "2024-12-31"
}
```

### Selection Fields

#### select
Dropdown selection.

```json
{
  "id": "field_uuid",
  "key": "country",
  "type": "select",
  "label": "Country",
  "placeholder": "Select a country",
  "multiple": false,
  "options": [
    { "value": "us", "label": "United States" },
    { "value": "uk", "label": "United Kingdom" }
  ]
}
```

#### radio
Radio button group (single selection).

```json
{
  "id": "field_uuid",
  "key": "gender",
  "type": "radio",
  "label": "Gender",
  "options": [
    { "value": "male", "label": "Male" },
    { "value": "female", "label": "Female" },
    { "value": "other", "label": "Other" }
  ]
}
```

#### checkbox_group
Checkbox group (multiple selection).

```json
{
  "id": "field_uuid",
  "key": "interests",
  "type": "checkbox_group",
  "label": "Interests",
  "minSelected": 1,
  "maxSelected": 3,
  "options": [
    { "value": "tech", "label": "Technology" },
    { "value": "sports", "label": "Sports" },
    { "value": "music", "label": "Music" }
  ]
}
```

#### checkbox
Single checkbox (boolean).

```json
{
  "id": "field_uuid",
  "key": "agree_terms",
  "type": "checkbox",
  "label": "I agree to the terms and conditions",
  "required": true
}
```

### File Fields

#### file
File upload input.

```json
{
  "id": "field_uuid",
  "key": "resume",
  "type": "file",
  "label": "Upload Resume",
  "accept": [".pdf", ".doc", ".docx"],
  "maxSize": 5242880,
  "multiple": false,
  "maxFiles": 1
}
```

### Presentational Fields

#### heading
Section heading (does not collect data).

```json
{
  "id": "field_uuid",
  "key": "section_header",
  "type": "heading",
  "level": 2,
  "content": "Personal Information"
}
```

## Conditional Logic

Fields can have conditions that control their visibility or behavior based on other field values.

```json
{
  "id": "field_uuid",
  "key": "other_reason",
  "type": "textarea",
  "label": "Please specify",
  "conditions": [
    {
      "field": "field_reason_id",
      "operator": "equals",
      "value": "other",
      "action": "show"
    }
  ]
}
```

### Operators

| Operator | Description |
|----------|-------------|
| `equals` | Field value equals specified value |
| `not_equals` | Field value does not equal specified value |
| `contains` | Field value contains specified string |
| `not_contains` | Field value does not contain specified string |
| `greater_than` | Field value is greater than specified value |
| `less_than` | Field value is less than specified value |
| `is_empty` | Field has no value |
| `is_not_empty` | Field has a value |
| `in` | Field value is in specified array |
| `not_in` | Field value is not in specified array |

### Actions

| Action | Description |
|--------|-------------|
| `show` | Show the field when condition is met |
| `hide` | Hide the field when condition is met |
| `require` | Make field required when condition is met |
| `disable` | Disable the field when condition is met |

## Layout

Fields support width configuration for responsive layouts.

```json
{
  "width": "half"
}
```

| Width | Description |
|-------|-------------|
| `full` | Full width (default) |
| `half` | 50% width |
| `third` | 33% width |
| `quarter` | 25% width |

## Validation Rules

### Schema Limits

| Limit | Value |
|-------|-------|
| Maximum schema size | 1 MB |
| Maximum fields per form | 200 |
| Maximum sections per form | 50 |
| Maximum options per field | 500 |

### Field Key Format

- Must start with lowercase letter
- Can contain lowercase letters, numbers, and underscores
- Must be unique within the form
- Pattern: `^[a-z][a-z0-9_]*$`

### Field ID Format

- Must be unique within the form
- Typically UUID-style: `field_` prefix + unique identifier

## Versioning

The schema version follows semantic versioning:

- **Major version** (1.x): Breaking changes requiring migration
- **Minor version** (x.0): Backward-compatible additions

Current version: `1.0`

## Error Format

Validation errors include path information for precise error location:

```json
{
  "message": "Schema validation failed",
  "errors": [
    {
      "path": "sections[0].fields[2].validation.required",
      "message": "Invalid value for required",
      "code": "invalid_type"
    }
  ]
}
```
