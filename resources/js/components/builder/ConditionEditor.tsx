import React, { useMemo } from 'react';
import {
  Condition,
  Field,
  Section,
  OPERATORS_BY_TYPE,
  OPERATOR_LABELS,
  ACTION_LABELS,
} from '../../utils/ConditionEvaluator';

interface ConditionEditorProps {
  conditions: Condition[];
  onChange: (conditions: Condition[]) => void;
  currentFieldKey: string;
  allFields: Field[];
  allSections: Section[];
}

export const ConditionEditor: React.FC<ConditionEditorProps> = ({
  conditions,
  onChange,
  currentFieldKey,
  allFields,
  allSections,
}) => {
  // Filter out current field and presentational fields
  const availableFields = useMemo(() => {
    return allFields.filter(
      (f) =>
        f.key !== currentFieldKey &&
        !['heading', 'paragraph', 'divider', 'spacer'].includes(f.type)
    );
  }, [allFields, currentFieldKey]);

  const addCondition = () => {
    const newCondition: Condition = {
      action: 'show',
      field: availableFields[0]?.key || '',
      operator: 'equals',
      value: '',
    };
    onChange([...conditions, newCondition]);
  };

  const updateCondition = (index: number, updates: Partial<Condition>) => {
    const updated = conditions.map((c, i) => (i === index ? { ...c, ...updates } : c));
    onChange(updated);
  };

  const removeCondition = (index: number) => {
    onChange(conditions.filter((_, i) => i !== index));
  };

  const getOperatorsForField = (fieldKey: string): string[] => {
    const field = allFields.find((f) => f.key === fieldKey);
    if (!field) return ['equals', 'not_equals'];
    return OPERATORS_BY_TYPE[field.type] || ['equals', 'not_equals', 'is_empty', 'is_not_empty'];
  };

  const getFieldOptions = (fieldKey: string) => {
    const field = allFields.find((f) => f.key === fieldKey);
    return field?.options || [];
  };

  const needsValueInput = (operator: string): boolean => {
    return !['is_empty', 'is_not_empty', 'is_checked', 'is_not_checked'].includes(operator);
  };

  const needsTargetSection = (action: string): boolean => {
    return ['skip_to_section', 'skip_to_step'].includes(action);
  };

  return (
    <div className="space-y-3">
      <div className="flex items-center justify-between">
        <label className="text-sm font-medium text-gray-700">Conditional Logic</label>
        <button
          type="button"
          onClick={addCondition}
          disabled={availableFields.length === 0}
          className="text-sm text-blue-600 hover:text-blue-800 disabled:text-gray-400"
        >
          + Add Condition
        </button>
      </div>

      {conditions.length === 0 && (
        <p className="text-sm text-gray-500 italic">No conditions. Field is always visible.</p>
      )}

      {conditions.map((condition, index) => (
        <div
          key={index}
          className="p-3 bg-gray-50 rounded-lg border border-gray-200 space-y-2"
        >
          <div className="flex items-center gap-2 flex-wrap">
            {/* Action */}
            <select
              value={condition.action}
              onChange={(e) => updateCondition(index, { action: e.target.value as Condition['action'] })}
              className="text-sm border rounded px-2 py-1"
              aria-label="Condition action"
            >
              {Object.entries(ACTION_LABELS).map(([value, label]) => (
                <option key={value} value={value}>
                  {label}
                </option>
              ))}
            </select>

            <span className="text-sm text-gray-500">when</span>

            {/* Target Field */}
            <select
              value={condition.field}
              onChange={(e) => {
                const newField = e.target.value;
                const newOperators = getOperatorsForField(newField);
                const newOperator = newOperators.includes(condition.operator)
                  ? condition.operator
                  : newOperators[0];
                updateCondition(index, { field: newField, operator: newOperator });
              }}
              className="text-sm border rounded px-2 py-1"
              aria-label="Target field"
            >
              {availableFields.map((f) => (
                <option key={f.key} value={f.key}>
                  {f.label || f.key}
                </option>
              ))}
            </select>

            {/* Operator */}
            <select
              value={condition.operator}
              onChange={(e) => updateCondition(index, { operator: e.target.value })}
              className="text-sm border rounded px-2 py-1"
              aria-label="Operator"
            >
              {getOperatorsForField(condition.field).map((op) => (
                <option key={op} value={op}>
                  {OPERATOR_LABELS[op] || op}
                </option>
              ))}
            </select>

            {/* Value Input */}
            {needsValueInput(condition.operator) && (
              <>
                {getFieldOptions(condition.field).length > 0 ? (
                  <select
                    value={String(condition.value || '')}
                    onChange={(e) => updateCondition(index, { value: e.target.value })}
                    className="text-sm border rounded px-2 py-1"
                    aria-label="Condition value"
                  >
                    <option value="">Select value...</option>
                    {getFieldOptions(condition.field).map((opt) => (
                      <option key={opt.value} value={opt.value}>
                        {opt.label}
                      </option>
                    ))}
                  </select>
                ) : (
                  <input
                    type="text"
                    value={String(condition.value || '')}
                    onChange={(e) => updateCondition(index, { value: e.target.value })}
                    placeholder="Value"
                    className="text-sm border rounded px-2 py-1 w-24"
                    aria-label="Condition value"
                  />
                )}
              </>
            )}

            {/* Target Section for skip actions */}
            {needsTargetSection(condition.action) && (
              <>
                <span className="text-sm text-gray-500">to</span>
                <select
                  value={condition.targetSection || ''}
                  onChange={(e) => updateCondition(index, { targetSection: e.target.value })}
                  className="text-sm border rounded px-2 py-1"
                  aria-label="Target section"
                >
                  <option value="">Select section...</option>
                  {allSections.map((s) => (
                    <option key={s.id} value={s.id}>
                      {s.title || s.id}
                    </option>
                  ))}
                </select>
              </>
            )}

            {/* Remove Button */}
            <button
              type="button"
              onClick={() => removeCondition(index)}
              className="text-red-500 hover:text-red-700 ml-auto"
              aria-label="Remove condition"
            >
              <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>

          {/* Validation warnings */}
          <ConditionWarnings
            condition={condition}
            currentFieldKey={currentFieldKey}
            allFields={allFields}
            allSections={allSections}
          />
        </div>
      ))}
    </div>
  );
};

interface ConditionWarningsProps {
  condition: Condition;
  currentFieldKey: string;
  allFields: Field[];
  allSections: Section[];
}

const ConditionWarnings: React.FC<ConditionWarningsProps> = ({
  condition,
  currentFieldKey,
  allFields,
  allSections,
}) => {
  const warnings: string[] = [];

  // Check if referenced field exists
  if (!allFields.find((f) => f.key === condition.field)) {
    warnings.push(`Referenced field "${condition.field}" does not exist`);
  }

  // Check self-reference
  if (condition.field === currentFieldKey) {
    warnings.push('Field cannot reference itself');
  }

  // Check target section exists for skip actions
  if (['skip_to_section', 'skip_to_step'].includes(condition.action)) {
    if (!condition.targetSection) {
      warnings.push('Target section is required');
    } else if (!allSections.find((s) => s.id === condition.targetSection)) {
      warnings.push(`Target section "${condition.targetSection}" does not exist`);
    }
  }

  // Check operator compatibility
  const field = allFields.find((f) => f.key === condition.field);
  if (field) {
    const validOperators = OPERATORS_BY_TYPE[field.type] || [];
    if (!validOperators.includes(condition.operator)) {
      warnings.push(`Operator "${condition.operator}" is not compatible with field type "${field.type}"`);
    }
  }

  if (warnings.length === 0) return null;

  return (
    <div className="text-xs text-amber-600 space-y-1">
      {warnings.map((w, i) => (
        <div key={i} className="flex items-center gap-1">
          <svg className="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
            <path fillRule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clipRule="evenodd" />
          </svg>
          {w}
        </div>
      ))}
    </div>
  );
};

export default ConditionEditor;
