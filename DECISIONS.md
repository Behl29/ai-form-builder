# DECISIONS.md - Phase 6

## Feature 1: Form Versioning

### User Problem
Form creators need to track changes over time, compare versions, and safely rollback to previous states without losing data. Submissions must always reference the exact form version used at submission time for data integrity and compliance.

### Implementation

**Immutable Versions**
- Each schema change creates a new FormVersion record
- Published versions are marked immutable (`is_published = true`)
- Version numbers auto-increment per form
- Schema version tracks format compatibility

**Version Metadata**
- `version_number`: Sequential integer per form
- `schema_version`: Schema format version (e.g., "1.0")
- `change_type`: created | updated | published | restored
- `change_summary`: JSON with counts of added/removed/modified fields/sections
- `created_by`: User who created the version
- `restored_from_version_id`: Links rollback versions to source

**Rollback Strategy**
```
Old Version (v1) → Rollback → New Version (v3)
                              └── schema copied from v1
                              └── restored_from_version_id = v1.id
                              └── change_type = 'restored'
```
Rollback NEVER mutates old versions. It creates a NEW version with the old schema.

**Version Comparison**
- Field-level diff: added, removed, modified
- Section-level diff: added, removed, modified
- Settings diff
- Tracks specific property changes (label, validation, options, conditions)

**Submission Integrity**
- Each submission stores `form_version_id`
- Submissions always reference exact schema used
- Historical submissions viewable with original field structure

### Alternatives Considered

1. **Mutable versions with audit log**: Simpler but loses exact schema state
2. **Git-style branching**: Too complex for form use case
3. **Snapshot on publish only**: Loses draft history

### Trade-offs

| Approach | Pros | Cons |
|----------|------|------|
| Immutable versions | Full history, safe rollback | More storage |
| Copy-on-rollback | Never loses data | Version numbers can grow |
| JSON diff | Human-readable changes | Complex nested comparison |

### Limitations

- No merge capability for concurrent edits
- No partial rollback (field-level)
- Change summary is count-based, not semantic
- Large schemas increase storage

### Two Additional Weeks Would Add

1. **Visual diff viewer**: Side-by-side schema comparison in UI
2. **Partial rollback**: Restore specific fields/sections only
3. **Version branching**: Create variants from any version
4. **Diff annotations**: Comments on why changes were made
5. **Version tagging**: Named versions (e.g., "v2.0 Release")
6. **Automated changelog**: Generate release notes from diffs

---

## Feature 2: Conditional Logic

### User Problem
Form creators need dynamic forms that adapt based on user input:
- Show/hide fields based on answers
- Make fields required conditionally
- Skip sections based on responses
- Create branching survey flows

### Implementation

**Condition Structure**
```typescript
interface Condition {
  action: 'show' | 'hide' | 'require' | 'skip_to_section' | 'skip_to_step';
  field: string;      // Target field key to evaluate
  operator: string;   // Comparison operator
  value?: unknown;    // Value to compare against
  targetSection?: string; // For skip actions
}
```

**Supported Operators by Field Type**

| Field Type | Operators |
|------------|-----------|
| text, textarea, email | equals, not_equals, contains, not_contains, is_empty, is_not_empty |
| number, rating | equals, not_equals, greater_than, less_than, >=, <=, is_empty, is_not_empty |
| select | equals, not_equals, in, not_in, is_empty, is_not_empty |
| checkbox | equals, is_checked, is_not_checked |
| checkbox_group | contains, not_contains, is_empty, is_not_empty |
| file | is_empty, is_not_empty |

**Validation Rules**
1. Referenced field must exist
2. Operator must be compatible with field type
3. No self-reference (field cannot depend on itself)
4. No backward skip (prevents infinite loops)
5. Target section must exist for skip actions
6. Option values must be valid for select/radio fields

**Shared Evaluation Logic**
- `ConditionEvaluator` class in PHP and TypeScript
- Same logic for preview, public renderer, and server validation
- Caches visibility results for performance

**Hidden Field Behavior**
- Hidden fields skip required validation
- Hidden sections skip all field validation
- Server re-evaluates all conditions (never trusts client)

### Alternatives Considered

1. **Expression language**: More powerful but harder to validate
2. **Visual flow builder**: Better UX but complex implementation
3. **Rule engine**: Overkill for form conditions

### Trade-offs

| Approach | Pros | Cons |
|----------|------|------|
| Declarative conditions | Easy to validate, serialize | Limited expressiveness |
| Per-field conditions | Simple mental model | Can't express OR logic |
| Type-specific operators | Prevents invalid comparisons | More code to maintain |

### Limitations

- No OR logic (all conditions are AND)
- No nested conditions
- No computed/calculated fields
- No cross-section field references for skip
- Cycle detection is basic (direct only)

### Two Additional Weeks Would Add

1. **OR/AND groups**: Complex condition combinations
2. **Calculated fields**: Derive values from other fields
3. **Visual condition builder**: Drag-drop flow editor
4. **Condition templates**: Reusable condition patterns
5. **Advanced cycle detection**: Full graph traversal
6. **Condition testing**: Simulate different input scenarios
7. **Condition analytics**: Track which paths users take

---

## API Endpoints

### Versioning
```
GET    /api/forms/{form}/versions              # List version history
GET    /api/forms/{form}/versions/{version}    # Get version details
POST   /api/forms/{form}/versions/compare      # Compare two versions
POST   /api/forms/{form}/versions/{version}/rollback  # Rollback to version
POST   /api/forms/{form}/versions/{version}/publish   # Publish version
```

### Condition Validation
Conditions are validated as part of schema validation when:
- Creating/updating form schema
- Publishing form

---

## Test Coverage

### Versioning Tests (18 tests)
- Version creation and numbering
- Immutability after publish
- Change summary generation
- Rollback creates new version
- Rollback doesn't mutate old version
- Version comparison (fields, sections, settings)
- API endpoints
- Submission version integrity

### Conditional Logic Tests (22 tests)
- Field reference validation
- Operator compatibility
- Self-reference rejection
- Skip target validation
- Backward skip cycle rejection
- Option value validation
- Runtime evaluation (show, hide, equals, greater_than, contains, is_empty)
- Conditional require
- Hidden required field behavior
- Section visibility cascading
- Operator support by field type
