import React, { useState } from 'react';

interface Version {
  id: number;
  version_number: number;
  schema_version: string;
  change_type: 'created' | 'updated' | 'published' | 'restored';
  change_summary: {
    fields_added?: number;
    fields_removed?: number;
    fields_modified?: number;
    sections_added?: number;
    sections_removed?: number;
    sections_modified?: number;
  } | null;
  is_published: boolean;
  published_at: string | null;
  restored_from_version_id: number | null;
  created_by: { id: number; name: string } | null;
  created_at: string;
}

interface VersionHistoryProps {
  versions: Version[];
  currentVersionId: number | null;
  onPreview: (version: Version) => void;
  onCompare: (oldVersion: Version, newVersion: Version) => void;
  onRollback: (version: Version) => void;
  onPublish: (version: Version) => void;
  isLoading?: boolean;
}

export const VersionHistory: React.FC<VersionHistoryProps> = ({
  versions,
  currentVersionId,
  onPreview,
  onCompare,
  onRollback,
  onPublish,
  isLoading,
}) => {
  const [selectedForCompare, setSelectedForCompare] = useState<Version | null>(null);

  const getChangeTypeColor = (type: string) => {
    switch (type) {
      case 'created': return 'bg-green-100 text-green-800';
      case 'updated': return 'bg-blue-100 text-blue-800';
      case 'published': return 'bg-purple-100 text-purple-800';
      case 'restored': return 'bg-amber-100 text-amber-800';
      default: return 'bg-gray-100 text-gray-800';
    }
  };

  const formatDate = (dateStr: string) => {
    return new Date(dateStr).toLocaleString();
  };

  const renderChangeSummary = (summary: Version['change_summary']) => {
    if (!summary) return null;
    
    const changes: string[] = [];
    if (summary.fields_added) changes.push(`+${summary.fields_added} fields`);
    if (summary.fields_removed) changes.push(`-${summary.fields_removed} fields`);
    if (summary.fields_modified) changes.push(`~${summary.fields_modified} fields`);
    if (summary.sections_added) changes.push(`+${summary.sections_added} sections`);
    if (summary.sections_removed) changes.push(`-${summary.sections_removed} sections`);
    
    if (changes.length === 0) return <span className="text-gray-400">No changes</span>;
    return <span className="text-gray-600">{changes.join(', ')}</span>;
  };

  const handleCompareClick = (version: Version) => {
    if (selectedForCompare) {
      if (selectedForCompare.id !== version.id) {
        const [older, newer] = selectedForCompare.version_number < version.version_number
          ? [selectedForCompare, version]
          : [version, selectedForCompare];
        onCompare(older, newer);
      }
      setSelectedForCompare(null);
    } else {
      setSelectedForCompare(version);
    }
  };

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-8">
        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
      </div>
    );
  }

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h3 className="text-lg font-medium">Version History</h3>
        {selectedForCompare && (
          <div className="text-sm text-blue-600">
            Select another version to compare with v{selectedForCompare.version_number}
            <button
              onClick={() => setSelectedForCompare(null)}
              className="ml-2 text-gray-500 hover:text-gray-700"
            >
              Cancel
            </button>
          </div>
        )}
      </div>

      <div className="space-y-2">
        {versions.map((version) => (
          <div
            key={version.id}
            className={`p-4 border rounded-lg ${
              version.id === currentVersionId
                ? 'border-blue-500 bg-blue-50'
                : selectedForCompare?.id === version.id
                ? 'border-amber-500 bg-amber-50'
                : 'border-gray-200 hover:border-gray-300'
            }`}
          >
            <div className="flex items-start justify-between">
              <div className="space-y-1">
                <div className="flex items-center gap-2">
                  <span className="font-medium">Version {version.version_number}</span>
                  <span className={`text-xs px-2 py-0.5 rounded-full ${getChangeTypeColor(version.change_type)}`}>
                    {version.change_type}
                  </span>
                  {version.is_published && (
                    <span className="text-xs px-2 py-0.5 rounded-full bg-green-100 text-green-800">
                      Published
                    </span>
                  )}
                  {version.id === currentVersionId && (
                    <span className="text-xs px-2 py-0.5 rounded-full bg-blue-100 text-blue-800">
                      Current
                    </span>
                  )}
                </div>

                <div className="text-sm text-gray-500">
                  {formatDate(version.created_at)}
                  {version.created_by && ` by ${version.created_by.name}`}
                </div>

                <div className="text-sm">
                  {renderChangeSummary(version.change_summary)}
                </div>

                {version.restored_from_version_id && (
                  <div className="text-sm text-amber-600">
                    Restored from version {versions.find(v => v.id === version.restored_from_version_id)?.version_number || '?'}
                  </div>
                )}
              </div>

              <div className="flex items-center gap-2">
                <button
                  onClick={() => onPreview(version)}
                  className="text-sm px-3 py-1 text-gray-600 hover:text-gray-800 border rounded"
                  title="Preview this version"
                >
                  Preview
                </button>

                <button
                  onClick={() => handleCompareClick(version)}
                  className={`text-sm px-3 py-1 border rounded ${
                    selectedForCompare?.id === version.id
                      ? 'bg-amber-100 text-amber-800 border-amber-300'
                      : 'text-gray-600 hover:text-gray-800'
                  }`}
                  title="Compare versions"
                >
                  Compare
                </button>

                {version.id !== currentVersionId && (
                  <button
                    onClick={() => onRollback(version)}
                    className="text-sm px-3 py-1 text-amber-600 hover:text-amber-800 border border-amber-300 rounded"
                    title="Rollback to this version"
                  >
                    Rollback
                  </button>
                )}

                {!version.is_published && (
                  <button
                    onClick={() => onPublish(version)}
                    className="text-sm px-3 py-1 text-green-600 hover:text-green-800 border border-green-300 rounded"
                    title="Publish this version"
                  >
                    Publish
                  </button>
                )}
              </div>
            </div>
          </div>
        ))}
      </div>

      {versions.length === 0 && (
        <p className="text-center text-gray-500 py-8">No versions found</p>
      )}
    </div>
  );
};

export default VersionHistory;
