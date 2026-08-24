import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../lib/api';
import type { Form, FormStatus } from '../types/form-schema';

interface PaginatedResponse<T> {
    data: T[];
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    links: {
        first: string;
        last: string;
        prev: string | null;
        next: string | null;
    };
}

interface FormFilters {
    status?: FormStatus;
    search?: string;
    page?: number;
    per_page?: number;
}

interface CreateFormData {
    title: string;
    description?: string;
    slug?: string;
}

interface UpdateFormData {
    title?: string;
    description?: string;
    slug?: string;
    success_message?: string;
}

// List forms
export function useForms(filters: FormFilters = {}) {
    return useQuery({
        queryKey: ['forms', filters],
        queryFn: async () => {
            const params = new URLSearchParams();
            if (filters.status) params.append('status', filters.status);
            if (filters.search) params.append('search', filters.search);
            if (filters.page) params.append('page', String(filters.page));
            if (filters.per_page) params.append('per_page', String(filters.per_page));

            const { data } = await api.get<PaginatedResponse<Form>>(`/forms?${params}`);
            return data;
        },
    });
}

// Get single form
export function useForm(id: number) {
    return useQuery({
        queryKey: ['forms', id],
        queryFn: async () => {
            const { data } = await api.get<{ data: Form }>(`/forms/${id}`);
            return data.data;
        },
        enabled: !!id,
    });
}

// Create form
export function useCreateForm() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (formData: CreateFormData) => {
            const { data } = await api.post<{ data: Form }>('/forms', formData);
            return data.data;
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['forms'] });
        },
    });
}

// Update form
export function useUpdateForm() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async ({ id, ...formData }: UpdateFormData & { id: number }) => {
            const { data } = await api.put<{ data: Form }>(`/forms/${id}`, formData);
            return data.data;
        },
        onSuccess: (_, variables) => {
            queryClient.invalidateQueries({ queryKey: ['forms'] });
            queryClient.invalidateQueries({ queryKey: ['forms', variables.id] });
        },
    });
}

// Delete form
export function useDeleteForm() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (id: number) => {
            await api.delete(`/forms/${id}`);
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['forms'] });
        },
    });
}

// Duplicate form
export function useDuplicateForm() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async ({ id, title }: { id: number; title?: string }) => {
            const { data } = await api.post<{ data: Form }>(`/forms/${id}/duplicate`, { title });
            return data.data;
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['forms'] });
        },
    });
}

// Publish form
export function usePublishForm() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (id: number) => {
            const { data } = await api.post<{ data: Form }>(`/forms/${id}/publish`);
            return data.data;
        },
        onSuccess: (_, id) => {
            queryClient.invalidateQueries({ queryKey: ['forms'] });
            queryClient.invalidateQueries({ queryKey: ['forms', id] });
        },
    });
}

// Unpublish form
export function useUnpublishForm() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (id: number) => {
            const { data } = await api.post<{ data: Form }>(`/forms/${id}/unpublish`);
            return data.data;
        },
        onSuccess: (_, id) => {
            queryClient.invalidateQueries({ queryKey: ['forms'] });
            queryClient.invalidateQueries({ queryKey: ['forms', id] });
        },
    });
}

// Archive form
export function useArchiveForm() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (id: number) => {
            const { data } = await api.post<{ data: Form }>(`/forms/${id}/archive`);
            return data.data;
        },
        onSuccess: (_, id) => {
            queryClient.invalidateQueries({ queryKey: ['forms'] });
            queryClient.invalidateQueries({ queryKey: ['forms', id] });
        },
    });
}

// Restore form
export function useRestoreForm() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (id: number) => {
            const { data } = await api.post<{ data: Form }>(`/forms/${id}/restore`);
            return data.data;
        },
        onSuccess: (_, id) => {
            queryClient.invalidateQueries({ queryKey: ['forms'] });
            queryClient.invalidateQueries({ queryKey: ['forms', id] });
        },
    });
}
