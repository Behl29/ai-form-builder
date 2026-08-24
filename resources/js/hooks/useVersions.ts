import { useState, useCallback } from 'react';
import axios from 'axios';

interface Version {
  id: number;
  version_number: number;
  schema_version: string;
  schema?: Record<string, unknown>;
  change_type: 'created' | 'updated' | 'published' | 'restored';
  change_summary: Record<string, number> | null;
  is_published: boolean;
  published_at: string | null;
  restored_from_version_id: number | null;
  created_by: { id: number; name: string } | null;
  created_at: string;
}

interface VersionComparison {
  old_version: number;
  new_version: number;
  fields: {
    added: Record<string, unknown>;
    removed: Record<string, unknown>;
    modified: Record<string, unknown>;
  };
  sections: {
    added: Record<string, unknown>;
    removed: Record<string, unknown>;
    modified: Record<string, unknown>;
  };
  settings: Record<string, { old: unknown; new: unknown }>;
}

interface UseVersionsReturn {
  versions: Version[];
  currentVersionId: number | null;
  isLoading: boolean;
  error: string | null;
  fetchVersions: (formId: number) => Promise<void>;
  fetchVersion: (formId: number, versionId: number) => Promise<Version>;
  compareVersions: (formId: number, oldVersionId: number, newVersionId: number) => Promise<VersionComparison>;
  rollback: (formId: number, versionId: number) => Promise<Version>;
  publish: (formId: number, versionId: number) => Promise<void>;
}

export function useVersions(): UseVersionsReturn {
  const [versions, setVersions] = useState<Version[]>([]);
  const [currentVersionId, setCurrentVersionId] = useState<number | null>(null);
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const fetchVersions = useCallback(async (formId: number) => {
    setIsLoading(true);
    setError(null);
    try {
      const response = await axios.get(`/api/forms/${formId}/versions`);
      setVersions(response.data.data);
      setCurrentVersionId(response.data.current_version_id);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to fetch versions');
      throw err;
    } finally {
      setIsLoading(false);
    }
  }, []);

  const fetchVersion = useCallback(async (formId: number, versionId: number): Promise<Version> => {
    const response = await axios.get(`/api/forms/${formId}/versions/${versionId}`);
    return response.data.data;
  }, []);

  const compareVersions = useCallback(async (
    formId: number,
    oldVersionId: number,
    newVersionId: number
  ): Promise<VersionComparison> => {
    const response = await axios.post(`/api/forms/${formId}/versions/compare`, {
      old_version_id: oldVersionId,
      new_version_id: newVersionId,
    });
    return response.data.data;
  }, []);

  const rollback = useCallback(async (formId: number, versionId: number): Promise<Version> => {
    const response = await axios.post(`/api/forms/${formId}/versions/${versionId}/rollback`);
    // Refresh versions list
    await fetchVersions(formId);
    return response.data.data;
  }, [fetchVersions]);

  const publish = useCallback(async (formId: number, versionId: number): Promise<void> => {
    await axios.post(`/api/forms/${formId}/versions/${versionId}/publish`);
    // Refresh versions list
    await fetchVersions(formId);
  }, [fetchVersions]);

  return {
    versions,
    currentVersionId,
    isLoading,
    error,
    fetchVersions,
    fetchVersion,
    compareVersions,
    rollback,
    publish,
  };
}

export default useVersions;
