/**
 * Shared condition evaluation logic for preview and public renderer.
 * Mirrors the PHP ConditionEvaluator implementation.
 */

export interface Condition {
  action: 'show' | 'hide' | 'require' | 'skip_to_section' | 'skip_to_step';
  field: string;
  operator: string;
  value?: unknown;
  targetSection?: string;
}

export interface Field {
  key: string;
  type: string;
  label?: string;
  required?: boolean;
  conditions?: Condition[];
  options?: { value: string; label: string }[];
  [key: string]: unknown;
}

export interface Section {
  id: string;
  title?: string;
  description?: string;
  fields: Field[];
  conditions?: Condition[];
}

export interface FormSchema {
  sections: Section[];
  settings?: Record<string, unknown>;
}

export type FormData = Record<string, unknown>;

export class ConditionEvaluator {
  private schema: FormSchema;
  private data: FormData;
  private fieldMap: Map<string, Field & { sectionId: string }> = new Map();
  private sectionMap: Map<string, Section & { index: number }> = new Map();
  private visibilityCache: Map<string, boolean> = new Map();

  constructor(schema: FormSchema, data: FormData = {}) {
    this.schema = schema;
    this.data = data;
    this.buildMaps();
  }

  setData(data: FormData): this {
    this.data = data;
    this.visibilityCache.clear();
    return this;
  }

  isFieldVisible(fieldKey: string): boolean {
    const cacheKey = `field:${fieldKey}`;
    if (this.visibilityCache.has(cacheKey)) {
      return this.visibilityCache.get(cacheKey)!;
    }

    const field = this.fieldMap.get(fieldKey);
    if (!field) return true;

    // Check parent section visibility
    if (field.sectionId && !this.isSectionVisible(field.sectionId)) {
      this.visibilityCache.set(cacheKey, false);
      return false;
    }

    const result = this.evaluateVisibilityConditions(field.conditions || []);
    this.visibilityCache.set(cacheKey, result);
    return result;
  }

  isSectionVisible(sectionId: string): boolean {
    const cacheKey = `section:${sectionId}`;
    if (this.visibilityCache.has(cacheKey)) {
      return this.visibilityCache.get(cacheKey)!;
    }

    const section = this.sectionMap.get(sectionId);
    if (!section) return true;

    const result = this.evaluateVisibilityConditions(section.conditions || []);
    this.visibilityCache.set(cacheKey, result);
    return result;
  }

  isFieldRequired(fieldKey: string): boolean {
    const field = this.fieldMap.get(fieldKey);
    if (!field) return false;

    const baseRequired = field.required || false;
    const conditions = field.conditions || [];

    for (const condition of conditions) {
      if (condition.action !== 'require') continue;
      if (this.evaluateCondition(condition)) {
        return true;
      }
    }

    return baseRequired;
  }

  getNextSection(currentSectionId: string): string | null {
    const section = this.sectionMap.get(currentSectionId);
    if (!section) return null;

    // Check section-level skip conditions
    for (const condition of section.conditions || []) {
      if (!['skip_to_section', 'skip_to_step'].includes(condition.action)) continue;
      if (this.evaluateCondition(condition)) {
        return condition.targetSection || null;
      }
    }

    // Check field-level skip conditions
    for (const field of section.fields) {
      for (const condition of field.conditions || []) {
        if (!['skip_to_section', 'skip_to_step'].includes(condition.action)) continue;
        if (this.evaluateCondition(condition)) {
          return condition.targetSection || null;
        }
      }
    }

    // Return next sequential visible section
    const sections = this.schema.sections;
    for (let i = section.index + 1; i < sections.length; i++) {
      const nextSectionId = sections[i].id;
      if (this.isSectionVisible(nextSectionId)) {
        return nextSectionId;
      }
    }

    return null;
  }

  getVisibleFields(): Field[] {
    const visible: Field[] = [];
    for (const [key, field] of this.fieldMap) {
      if (this.isFieldVisible(key)) {
        visible.push(field);
      }
    }
    return visible;
  }

  getVisibleSections(): Section[] {
    const visible: Section[] = [];
    for (const [id, section] of this.sectionMap) {
      if (this.isSectionVisible(id)) {
        visible.push(section);
      }
    }
    return visible;
  }

  evaluateCondition(condition: Condition): boolean {
    const targetField = condition.field;
    if (!targetField) return false;

    const value = this.data[targetField];
    const operator = condition.operator || 'equals';
    const conditionValue = condition.value;

    return this.evaluateOperator(operator, value, conditionValue);
  }

  private evaluateVisibilityConditions(conditions: Condition[]): boolean {
    let hasShowCondition = false;
    let showConditionMet = false;

    for (const condition of conditions) {
      if (condition.action === 'show') {
        hasShowCondition = true;
        if (this.evaluateCondition(condition)) {
          showConditionMet = true;
        }
      }

      if (condition.action === 'hide' && this.evaluateCondition(condition)) {
        return false;
      }
    }

    if (hasShowCondition && !showConditionMet) {
      return false;
    }

    return true;
  }

  private evaluateOperator(operator: string, value: unknown, conditionValue: unknown): boolean {
    switch (operator) {
      case 'equals':
        return this.looseEquals(value, conditionValue);
      case 'not_equals':
        return !this.looseEquals(value, conditionValue);
      case 'contains':
        return this.contains(value, conditionValue);
      case 'not_contains':
        return !this.contains(value, conditionValue);
      case 'greater_than':
        return typeof value === 'number' && typeof conditionValue === 'number' && value > conditionValue;
      case 'less_than':
        return typeof value === 'number' && typeof conditionValue === 'number' && value < conditionValue;
      case 'greater_than_or_equals':
        return typeof value === 'number' && typeof conditionValue === 'number' && value >= conditionValue;
      case 'less_than_or_equals':
        return typeof value === 'number' && typeof conditionValue === 'number' && value <= conditionValue;
      case 'is_empty':
        return this.isEmpty(value);
      case 'is_not_empty':
        return !this.isEmpty(value);
      case 'in':
        return Array.isArray(conditionValue) && conditionValue.includes(value);
      case 'not_in':
        return Array.isArray(conditionValue) && !conditionValue.includes(value);
      case 'is_checked':
        return this.isTruthy(value);
      case 'is_not_checked':
        return !this.isTruthy(value);
      default:
        return false;
    }
  }

  private looseEquals(a: unknown, b: unknown): boolean {
    if (a === b) return true;
    if (typeof a === 'number' && typeof b === 'number') return a === b;
    if (typeof a === 'string' && typeof b === 'string') {
      return a.toLowerCase() === b.toLowerCase();
    }
    return a == b;
  }

  private contains(haystack: unknown, needle: unknown): boolean {
    if (Array.isArray(haystack)) {
      return haystack.includes(needle);
    }
    if (typeof haystack === 'string' && typeof needle === 'string') {
      return haystack.toLowerCase().includes(needle.toLowerCase());
    }
    return false;
  }

  private isEmpty(value: unknown): boolean {
    if (value === null || value === undefined || value === '') return true;
    if (Array.isArray(value)) return value.length === 0;
    return false;
  }

  private isTruthy(value: unknown): boolean {
    if (typeof value === 'boolean') return value;
    if (typeof value === 'string') {
      return ['true', '1', 'yes', 'on'].includes(value.toLowerCase());
    }
    if (typeof value === 'number') return value === 1;
    return Boolean(value);
  }

  private buildMaps(): void {
    this.fieldMap.clear();
    this.sectionMap.clear();

    this.schema.sections.forEach((section, index) => {
      const sectionId = section.id || `section_${index}`;
      this.sectionMap.set(sectionId, { ...section, index });

      section.fields.forEach((field) => {
        if (field.key) {
          this.fieldMap.set(field.key, { ...field, sectionId });
        }
      });
    });
  }
}

// Operator metadata for UI
export const OPERATORS_BY_TYPE: Record<string, string[]> = {
  text: ['equals', 'not_equals', 'contains', 'not_contains', 'is_empty', 'is_not_empty'],
  textarea: ['equals', 'not_equals', 'contains', 'not_contains', 'is_empty', 'is_not_empty'],
  email: ['equals', 'not_equals', 'contains', 'not_contains', 'is_empty', 'is_not_empty'],
  url: ['equals', 'not_equals', 'is_empty', 'is_not_empty'],
  phone: ['equals', 'not_equals', 'is_empty', 'is_not_empty'],
  number: ['equals', 'not_equals', 'greater_than', 'less_than', 'greater_than_or_equals', 'less_than_or_equals', 'is_empty', 'is_not_empty'],
  date: ['equals', 'not_equals', 'greater_than', 'less_than', 'is_empty', 'is_not_empty'],
  select: ['equals', 'not_equals', 'in', 'not_in', 'is_empty', 'is_not_empty'],
  radio: ['equals', 'not_equals', 'is_empty', 'is_not_empty'],
  checkbox: ['equals', 'is_checked', 'is_not_checked'],
  checkbox_group: ['contains', 'not_contains', 'is_empty', 'is_not_empty'],
  rating: ['equals', 'not_equals', 'greater_than', 'less_than', 'greater_than_or_equals', 'less_than_or_equals'],
  file: ['is_empty', 'is_not_empty'],
};

export const OPERATOR_LABELS: Record<string, string> = {
  equals: 'Equals',
  not_equals: 'Does not equal',
  contains: 'Contains',
  not_contains: 'Does not contain',
  greater_than: 'Greater than',
  less_than: 'Less than',
  greater_than_or_equals: 'Greater than or equals',
  less_than_or_equals: 'Less than or equals',
  is_empty: 'Is empty',
  is_not_empty: 'Is not empty',
  in: 'Is one of',
  not_in: 'Is not one of',
  is_checked: 'Is checked',
  is_not_checked: 'Is not checked',
};

export const CONDITION_ACTIONS = ['show', 'hide', 'require', 'skip_to_section', 'skip_to_step'] as const;

export const ACTION_LABELS: Record<string, string> = {
  show: 'Show this field',
  hide: 'Hide this field',
  require: 'Make required',
  skip_to_section: 'Skip to section',
  skip_to_step: 'Skip to step',
};
