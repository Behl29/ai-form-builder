import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../lib/api';

interface Submission {
    id: number;
    form_id: number;
    form_version_id: number;
    version_number?: number;
    data: Record<string, unknown>;
    status: string;
    submitted_at: string;
    files: SubmissionFile[];
    created_at: string;
}

interface SubmissionFile {
    id: number;
    field_key: string;
    original_name: string;
    mime_type: string;
    size: number;
    created_at: string;
}

interface PaginatedResponse<T> {
    data: T[];
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
}

interface SubmissionFilters {
    search?: string;
    from_date?: string;
    to_date?: string;
    page?: number;
    per_page?: number;
}

export function useSubmissions(formId: number, filters: SubmissionFilters = {}) {
    return useQuery({
        queryKey: ['submissions', formId, filters],
        queryFn: async () => {
            const params = new URLSearchParams();
            if (filters.search) params.append('search', filters.search);
            if (filters.from_date) params.append('from_date', filters.from_date);
            if (filters.to_date) params.append('to_date', filters.to_date);
            if (filters.page) params.append('page', String(filters.page));
            if (filters.per_page) params.append('per_page', String(filters.per_page));

            const { data } = await api.get<PaginatedResponse<Submission>>(`/forms/${formId}/submissions?${params}`);
            return data;
        },
        enabled: !!formId,
    });
}

export function useSubmission(formId: number, submissionId: number) {
    return useQuery({
        queryKey: ['submissions', formId, submissionId],
        queryFn: async () => {
            const { data } = await api.get<{ data: Submission }>(`/forms/${formId}/submissions/${submissionId}`);
            return data.data;
        },
        enabled: !!formId && !!submissionId,
    });
}

export function useDeleteSubmission() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async ({ formId, submissionId }: { formId: number; submissionId: number }) => {
            await api.delete(`/forms/${formId}/submissions/${submissionId}`);
        },
        onSuccess: (_, { formId }) => {
            queryClient.invalidateQueries({ queryKey: ['submissions', formId] });
        },
    });
}

export function useExportSubmissions() {
    return useMutation({
        mutationFn: async ({ formId, versionId }: { formId: number; versionId?: number }) => {
            const params = versionId ? `?version_id=${versionId}` : '';
            const response = await api.get(`/forms/${formId}/submissions/export${params}`, {
                responseType: 'blob',
            });

            // Create download link
            const url = window.URL.createObjectURL(new Blob([response.data]));
            const link = document.createElement('a');
            link.href = url;
            link.setAttribute('download', `submissions-${formId}.csv`);
            document.body.appendChild(link);
            link.click();
            link.remove();
            window.URL.revokeObjectURL(url);
        },
    });
}

export function getFileDownloadUrl(formId: number, submissionId: number, fileId: number): string {
    return `/api/forms/${formId}/submissions/${submissionId}/files/${fileId}`;
}
