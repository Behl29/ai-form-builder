import { clsx } from 'clsx';
import { FileText, Plus, Search } from 'lucide-react';
import { useCallback, useState } from 'react';
import { CreateFormModal } from '../components/CreateFormModal';
import { FormCard } from '../components/FormCard';
import { Button, EmptyState, ErrorState, Input, LoadingSpinner } from '../components/ui';
import {
    useArchiveForm,
    useCreateForm,
    useDeleteForm,
    useDuplicateForm,
    useForms,
    usePublishForm,
    useRestoreForm,
    useUnpublishForm,
} from '../hooks/useForms';
import type { Form, FormStatus } from '../types/form-schema';

const statusTabs: { value: FormStatus | 'all'; label: string }[] = [
    { value: 'all', label: 'All Forms' },
    { value: 'draft', label: 'Drafts' },
    { value: 'published', label: 'Published' },
    { value: 'archived', label: 'Archived' },
];

interface FormsDashboardProps {
    onEditForm?: (formId: number) => void;
    onViewSubmissions?: (formId: number) => void;
    onLogout?: () => void;
}

export function FormsDashboard({ onEditForm, onViewSubmissions, onLogout }: FormsDashboardProps) {
    const [search, setSearch] = useState('');
    const [debouncedSearch, setDebouncedSearch] = useState('');
    const [statusFilter, setStatusFilter] = useState<FormStatus | 'all'>('all');
    const [page, setPage] = useState(1);
    const [createModalOpen, setCreateModalOpen] = useState(false);
    const [loadingFormId, setLoadingFormId] = useState<number | null>(null);

    // Debounce search
    const handleSearchChange = useCallback((value: string) => {
        setSearch(value);
        const timer = setTimeout(() => {
            setDebouncedSearch(value);
            setPage(1);
        }, 300);
        return () => clearTimeout(timer);
    }, []);

    // Queries and mutations
    const { data, isLoading, isError, refetch } = useForms({
        status: statusFilter === 'all' ? undefined : statusFilter,
        search: debouncedSearch || undefined,
        page,
        per_page: 12,
    });

    const createForm = useCreateForm();
    const deleteForm = useDeleteForm();
    const duplicateForm = useDuplicateForm();
    const publishForm = usePublishForm();
    const unpublishForm = useUnpublishForm();
    const archiveForm = useArchiveForm();
    const restoreForm = useRestoreForm();

    // Handlers
    const handleCreate = async (formData: { title: string; description?: string }) => {
        const newForm = await createForm.mutateAsync(formData);
        setCreateModalOpen(false);
        // Navigate to editor after creation
        if (onEditForm) {
            onEditForm(newForm.id);
        }
    };

    const handleEdit = (form: Form) => {
        if (onEditForm) {
            onEditForm(form.id);
        } else {
            window.location.href = `/forms/${form.id}/edit`;
        }
    };

    const handleDuplicate = async (form: Form) => {
        setLoadingFormId(form.id);
        try {
            await duplicateForm.mutateAsync({ id: form.id });
        } finally {
            setLoadingFormId(null);
        }
    };

    const handlePublish = async (form: Form) => {
        setLoadingFormId(form.id);
        try {
            await publishForm.mutateAsync(form.id);
        } finally {
            setLoadingFormId(null);
        }
    };

    const handleUnpublish = async (form: Form) => {
        setLoadingFormId(form.id);
        try {
            await unpublishForm.mutateAsync(form.id);
        } finally {
            setLoadingFormId(null);
        }
    };

    const handleArchive = async (form: Form) => {
        setLoadingFormId(form.id);
        try {
            await archiveForm.mutateAsync(form.id);
        } finally {
            setLoadingFormId(null);
        }
    };

    const handleRestore = async (form: Form) => {
        setLoadingFormId(form.id);
        try {
            await restoreForm.mutateAsync(form.id);
        } finally {
            setLoadingFormId(null);
        }
    };

    const handleDelete = async (form: Form) => {
        setLoadingFormId(form.id);
        try {
            await deleteForm.mutateAsync(form.id);
        } finally {
            setLoadingFormId(null);
        }
    };

    return (
        <div className="min-h-screen bg-gray-50">
            {/* Header */}
            <header className="bg-white border-b border-gray-200">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
                    <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                        <div>
                            <h1 className="text-2xl font-bold text-gray-900">Forms</h1>
                            <p className="text-sm text-gray-500 mt-1">
                                Create and manage your forms
                            </p>
                        </div>
                        <div className="flex gap-2">
                            <Button onClick={() => setCreateModalOpen(true)}>
                                <Plus className="w-4 h-4 mr-2" />
                                Create Form
                            </Button>
                            {onLogout && (
                                <Button variant="secondary" onClick={onLogout}>
                                    Logout
                                </Button>
                            )}
                        </div>
                    </div>
                </div>
            </header>

            <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
                {/* Filters */}
                <div className="flex flex-col sm:flex-row gap-4 mb-6">
                    {/* Search */}
                    <div className="relative flex-1 max-w-md">
                        <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
                        <Input
                            type="search"
                            placeholder="Search forms..."
                            value={search}
                            onChange={(e) => handleSearchChange(e.target.value)}
                            className="pl-10"
                        />
                    </div>

                    {/* Status tabs */}
                    <div className="flex gap-1 bg-gray-100 p-1 rounded-lg">
                        {statusTabs.map((tab) => (
                            <button
                                key={tab.value}
                                onClick={() => {
                                    setStatusFilter(tab.value);
                                    setPage(1);
                                }}
                                className={clsx(
                                    'px-3 py-1.5 text-sm font-medium rounded-md transition-colors',
                                    statusFilter === tab.value
                                        ? 'bg-white text-gray-900 shadow-sm'
                                        : 'text-gray-600 hover:text-gray-900'
                                )}
                            >
                                {tab.label}
                            </button>
                        ))}
                    </div>
                </div>

                {/* Content */}
                {isLoading ? (
                    <LoadingSpinner />
                ) : isError ? (
                    <ErrorState message="Failed to load forms" onRetry={() => refetch()} />
                ) : !data?.data.length ? (
                    <EmptyState
                        icon={<FileText className="w-12 h-12" />}
                        title={debouncedSearch ? 'No forms found' : 'No forms yet'}
                        description={
                            debouncedSearch
                                ? 'Try adjusting your search or filters'
                                : 'Get started by creating your first form'
                        }
                        action={
                            !debouncedSearch && (
                                <Button onClick={() => setCreateModalOpen(true)}>
                                    <Plus className="w-4 h-4 mr-2" />
                                    Create Form
                                </Button>
                            )
                        }
                    />
                ) : (
                    <>
                        {/* Forms grid */}
                        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                            {data.data.map((form) => (
                                <FormCard
                                    key={form.id}
                                    form={form}
                                    onEdit={handleEdit}
                                    onViewSubmissions={onViewSubmissions ? (f) => onViewSubmissions(f.id) : undefined}
                                    onDuplicate={handleDuplicate}
                                    onPublish={handlePublish}
                                    onUnpublish={handleUnpublish}
                                    onArchive={handleArchive}
                                    onRestore={handleRestore}
                                    onDelete={handleDelete}
                                    isLoading={loadingFormId === form.id}
                                />
                            ))}
                        </div>

                        {/* Pagination */}
                        {data.meta.last_page > 1 && (
                            <div className="flex items-center justify-between mt-8 pt-6 border-t border-gray-200">
                                <p className="text-sm text-gray-500">
                                    Showing {(data.meta.current_page - 1) * data.meta.per_page + 1} to{' '}
                                    {Math.min(data.meta.current_page * data.meta.per_page, data.meta.total)} of{' '}
                                    {data.meta.total} forms
                                </p>
                                <div className="flex gap-2">
                                    <Button
                                        variant="secondary"
                                        size="sm"
                                        onClick={() => setPage((p) => Math.max(1, p - 1))}
                                        disabled={page === 1}
                                    >
                                        Previous
                                    </Button>
                                    <Button
                                        variant="secondary"
                                        size="sm"
                                        onClick={() => setPage((p) => p + 1)}
                                        disabled={page >= data.meta.last_page}
                                    >
                                        Next
                                    </Button>
                                </div>
                            </div>
                        )}
                    </>
                )}
            </main>

            {/* Create Form Modal */}
            <CreateFormModal
                open={createModalOpen}
                onClose={() => setCreateModalOpen(false)}
                onSubmit={handleCreate}
                loading={createForm.isPending}
            />
        </div>
    );
}
