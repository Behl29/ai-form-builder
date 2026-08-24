import React from 'react';

interface FieldChange {
  key: string;
  label: string;
  type?: string;
  changes?: Record<string, { old: unknown; new: unknown }>;
}

interface SectionChange {
  id: string;
  title: string;
  changes?: Record<string, { old: unknown; new: unknown }>;
}

interface VersionComparison {
  old_version: number;
  new_version: number;
  fields: {
    added: Record<string, FieldChange>;
    removed: Record<string, FieldChange>;
    modified: Record<string, FieldChange>;
  };
  sections: {
    added: Record<string, SectionChange>;
    removed: Record<string, SectionChange>;
    modified: Record<string, SectionChange>;
  };
  settings: Record<string, { old: unknown; new: unknown }>;
}

interface VersionComparisonProps {
  comparison: VersionComparison;
  onClose: () => void;
}

export const VersionComparisonView: React.FC<VersionComparisonProps> = ({
  comparison,
  onClose,
}) => {
  const hasFieldChanges =
    Object.keys(comparison.fields.added).length > 0 ||
    Object.keys(comparison.fields.removed).length > 0 ||
    Object.keys(comparison.fields.modified).length > 0;

  const hasSectionChanges =
    Object.keys(comparison.sections.added).length > 0 ||
    Object.keys(comparison.sections.removed).length > 0 ||
    Object.keys(comparison.sections.modified).length > 0;

  const hasSettingsChanges = Object.keys(comparison.settings).length > 0;

  const formatValue = (value: unknown): string => {
    if (value === null || value === undefined) return '(empty)';
    if (typeof value === 'boolean') return value ? 'Yes' : 'No';
    if (Array.isArray(value)) return value.map(v => typeof v === 'object' ? JSON.stringify(v) : v).join(', ');
    if (typeof value === 'object') return JSON.stringify(value);
    return String(value);
  };

  return (
    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
      <div className="bg-white rounded-lg shadow-xl max-w-4xl w-full max-h-[90vh] overflow-hidden">
        <div className="flex items-center justify-between p-4 border-b">
          <h2 className="text-lg font-semibold">
            Comparing Version {comparison.old_version} → Version {comparison.new_version}
          </h2>
          <button
            onClick={onClose}
            className="text-gray-500 hover:text-gray-700"
            aria-label="Close"
          >
            <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <div className="p-4 overflow-y-auto max-h-[calc(90vh-120px)] space-y-6">
          {/* Fields Section */}
          {hasFieldChanges && (
            <div>
              <h3 className="text-md font-medium mb-3 flex items-center gap-2">
                <svg className="w-5 h-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 6h16M4 12h16M4 18h7" />
                </svg>
                Field Changes
              </h3>

              {/* Added Fields */}
              {Object.keys(comparison.fields.added).length > 0 && (
                <div className="mb-4">
                  <h4 className="text-sm font-medium text-green-700 mb-2">Added Fields</h4>
                  <div className="space-y-2">
                    {Object.values(comparison.fields.added).map((field) => (
                      <div key={field.key} className="p-2 bg-green-50 border border-green-200 rounded">
                        <span className="font-medium">{field.label}</span>
                        <span className="text-sm text-gray-500 ml-2">({field.type})</span>
                      </div>
                    ))}
                  </div>
                </div>
              )}

              {/* Removed Fields */}
              {Object.keys(comparison.fields.removed).length > 0 && (
                <div className="mb-4">
                  <h4 className="text-sm font-medium text-red-700 mb-2">Removed Fields</h4>
                  <div className="space-y-2">
                    {Object.values(comparison.fields.removed).map((field) => (
                      <div key={field.key} className="p-2 bg-red-50 border border-red-200 rounded">
                        <span className="font-medium">{field.label}</span>
                        <span className="text-sm text-gray-500 ml-2">({field.type})</span>
                      </div>
                    ))}
                  </div>
                </div>
              )}

              {/* Modified Fields */}
              {Object.keys(comparison.fields.modified).length > 0 && (
                <div className="mb-4">
                  <h4 className="text-sm font-medium text-blue-700 mb-2">Modified Fields</h4>
                  <div className="space-y-2">
                    {Object.values(comparison.fields.modified).map((field) => (
                      <div key={field.key} className="p-3 bg-blue-50 border border-blue-200 rounded">
                        <div className="font-medium mb-2">{field.label}</div>
                        {field.changes && (
                          <div className="space-y-1 text-sm">
                            {Object.entries(field.changes).map(([prop, change]) => (
                              <div key={prop} className="flex items-start gap-2">
                                <span className="text-gray-600 min-w-[100px]">{prop}:</span>
                                <span className="text-red-600 line-through">{formatValue(change.old)}</span>
                                <span className="text-gray-400">→</span>
                                <span className="text-green-600">{formatValue(change.new)}</span>
                              </div>
                            ))}
                          </div>
                        )}
                      </div>
                    ))}
                  </div>
                </div>
              )}
            </div>
          )}

          {/* Sections Section */}
          {hasSectionChanges && (
            <div>
              <h3 className="text-md font-medium mb-3 flex items-center gap-2">
                <svg className="w-5 h-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                </svg>
                Section Changes
              </h3>

              {Object.keys(comparison.sections.added).length > 0 && (
                <div className="mb-4">
                  <h4 className="text-sm font-medium text-green-700 mb-2">Added Sections</h4>
                  {Object.values(comparison.sections.added).map((section) => (
                    <div key={section.id} className="p-2 bg-green-50 border border-green-200 rounded">
                      {section.title}
                    </div>
                  ))}
                </div>
              )}

              {Object.keys(comparison.sections.removed).length > 0 && (
                <div className="mb-4">
                  <h4 className="text-sm font-medium text-red-700 mb-2">Removed Sections</h4>
                  {Object.values(comparison.sections.removed).map((section) => (
                    <div key={section.id} className="p-2 bg-red-50 border border-red-200 rounded">
                      {section.title}
                    </div>
                  ))}
                </div>
              )}

              {Object.keys(comparison.sections.modified).length > 0 && (
                <div className="mb-4">
                  <h4 className="text-sm font-medium text-blue-700 mb-2">Modified Sections</h4>
                  {Object.values(comparison.sections.modified).map((section) => (
                    <div key={section.id} className="p-3 bg-blue-50 border border-blue-200 rounded">
                      <div className="font-medium mb-2">{section.title}</div>
                      {section.changes && (
                        <div className="space-y-1 text-sm">
                          {Object.entries(section.changes).map(([prop, change]) => (
                            <div key={prop} className="flex items-start gap-2">
                              <span className="text-gray-600 min-w-[100px]">{prop}:</span>
                              <span className="text-red-600 line-through">{formatValue(change.old)}</span>
                              <span className="text-gray-400">→</span>
                              <span className="text-green-600">{formatValue(change.new)}</span>
                            </div>
                          ))}
                        </div>
                      )}
                    </div>
                  ))}
                </div>
              )}
            </div>
          )}

          {/* Settings Section */}
          {hasSettingsChanges && (
            <div>
              <h3 className="text-md font-medium mb-3 flex items-center gap-2">
                <svg className="w-5 h-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
                Settings Changes
              </h3>
              <div className="space-y-2">
                {Object.entries(comparison.settings).map(([key, change]) => (
                  <div key={key} className="p-2 bg-gray-50 border border-gray-200 rounded flex items-start gap-2 text-sm">
                    <span className="text-gray-600 min-w-[120px]">{key}:</span>
                    <span className="text-red-600 line-through">{formatValue(change.old)}</span>
                    <span className="text-gray-400">→</span>
                    <span className="text-green-600">{formatValue(change.new)}</span>
                  </div>
                ))}
              </div>
            </div>
          )}

          {/* No Changes */}
          {!hasFieldChanges && !hasSectionChanges && !hasSettingsChanges && (
            <div className="text-center text-gray-500 py-8">
              No differences found between these versions
            </div>
          )}
        </div>

        <div className="p-4 border-t bg-gray-50">
          <button
            onClick={onClose}
            className="w-full px-4 py-2 bg-gray-200 text-gray-800 rounded hover:bg-gray-300"
          >
            Close
          </button>
        </div>
      </div>
    </div>
  );
};

export default VersionComparisonView;
