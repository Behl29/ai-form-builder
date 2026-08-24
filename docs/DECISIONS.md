# Architecture Decisions

## ADR-001: Form Schema as Single Source of Truth

**Date:** 2024-01-02

**Status:** Accepted

### Context

The form builder needs a consistent way to define form structure that works across:
- Backend validation and storage
- Frontend rendering and editing
- AI-generated form creation
- Form submission processing

### Decision

The JSON schema stored in `form_versions.schema` is the **single source of truth** for form structure. All components (backend, frontend, AI) must conform to this schema contract.

### Consequences

- Schema validation happens server-side before persistence
- Frontend TypeScript types are generated to match backend contract
- Invalid schemas are never persisted
- Schema changes require version bumps

---

## ADR-002: Immutable Form Versions

**Date:** 2024-01-02

**Status:** Accepted

### Context

Forms need version history for:
- Audit trails
- Rollback capability
- Submission integrity (submissions reference specific versions)

### Decision

Published form versions are immutable. Editing a form creates a new version. Historical versions are never modified.

### Consequences

- `form_versions.is_published` marks immutable versions
- Editing creates new version with incremented `version_number`
- Submissions will reference specific version IDs
- Storage grows with each edit (acceptable trade-off)

---

## ADR-003: Schema Versioning Strategy

**Date:** 2024-01-02

**Status:** Accepted

### Context

The schema format may evolve over time. We need a strategy for handling schema changes.

### Decision

- Schema version follows semver: `MAJOR.MINOR`
- Current version: `1.0`
- Major version bump: Breaking changes requiring migration
- Minor version bump: Backward-compatible additions
- Schema version stored in `form_versions.schema_version`

### Consequences

- Old schemas remain valid until explicitly migrated
- Migration scripts needed for major version changes
- Frontend must handle multiple schema versions during transition

---

## ADR-004: Field Identification Strategy

**Date:** 2024-01-02

**Status:** Accepted

### Context

Fields need stable identifiers for:
- Conditional logic references
- Submission data mapping
- Analytics and reporting

### Decision

Each field has two identifiers:
- `id`: UUID-style unique identifier (e.g., `field_abc123`), used for internal references
- `key`: Machine-readable key (e.g., `email_address`), used for submission data

### Consequences

- `id` is auto-generated and immutable
- `key` is user-editable but must be unique within form
- Submissions use `key` for data storage
- Conditions reference fields by `id`

---

## ADR-005: Tenant Isolation for Forms

**Date:** 2024-01-02

**Status:** Accepted

### Context

Forms must be isolated between tenants for security and data integrity.

### Decision

- Forms use `BelongsToTenant` trait with global scope
- All form queries automatically filtered by current tenant
- Authorization policies verify tenant ownership
- Direct ID access blocked by policy checks

### Consequences

- No cross-tenant form access possible
- Tenant context required for all form operations
- Performance: tenant_id indexed for efficient filtering
