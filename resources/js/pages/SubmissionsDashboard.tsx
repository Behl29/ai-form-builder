import { clsx } from 'clsx';
import { Calendar, Download, Eye, FileText, Search, Trash2, X } from 'lucide-react';
import { useCallback, useState } from 'react';
import { Button, ConfirmDialog, EmptyState, ErrorState, Input, LoadingSpinner, Modal } from '../components/ui';
import {
    getFileDownloadUrl,
    useDeleteSubmission,
    useExportSubmissions,
    useSubmission,
    useSubmissions,
} from '../hooks/useSubmissions';
import type { Form, FormSchema } from '../types/form-schema';

interface SubmissionsDashboardProps {
    form: Form;
    onBack: () => void;
}

export function SubmissionsDashboard({ form, onBack }: SubmissionsDashboardProps) {
    const [search, setSearch] = useState('');
    const [debouncedSearch, setDebouncedSearch] = useState('');
    const [fromDate, setFromDate] = useState('');
    const [toDate, setToDate] = useState('');
    const [page, setPage] = useState(1);
    const [selectedSubmissionId, setSelectedSubmissionId] = useState<number | null>(null);
    const [deleteSubmissionId, setDeleteSubmissionId] = useState<number | null>(null);

    const { data, isLoading, isError, refetch } = useSubmissions(form.id, {
        search: debouncedSearch || undefined,
        from_date: fromDate || undefined,
        to_date: toDate || undefined,
        page,
        per_page: 20,
    });

    const deleteSubmission = useDeleteSubmission();
    const exportSubmissions = useExportSubmissions();

    const handleSearchChange = useCallback((value: string) => {
        setSearch(value);
        const timer = setTimeout(() => {
            setDebouncedSearch(value);
            setPage(1);
        }, 300);
        return () => clearTimeout(timer);
    }, []);

    const handleExport = useCallback(() => {
        exportSubmissions.mutate({ formId: form.id });
    }, [form.id, exportSubmissions]);

    const handleDelete = useCallback(async () => {
        if (!deleteSubmissionId) return;
        await deleteSubmission.mutateAsync({ formId: form.id, submissionId: deleteSubmissionId });
        setDeleteSubmissionId(null);
    }, [form.id, deleteSubmissionId, deleteSubmission]);

    const schema = form.current_version?.schema as FormSchema | undefined;
    const fields = schema ? getFieldsFromSchema(schema) : [];

    return (
        <div className="min-h-screen bg-gray-50">
            {/* Header */}
            <header className="bg-white border-b border-gray-200">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
                    <div className="flex items-center justify-between">
                        <div className="flex items-center gap-4">
                            <button
                                onClick={onBack}
                                className="p-2 text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded-lg"
                            >
                                <X className="w-5 h-5" />
                            </button>
                            <div>
                                <h1 className="text-2xl font-bold text-gray-900">Submissions</h1>
                                <p className="text-sm text-gray-500">{form.title}</p>
                            </div>
                        </div>
                        <Button
                            variant="secondary"
                            onClick={handleExport}
                            loading={exportSubmissions.isPending}
                        >
                            <Download className="w-4 h-4 mr-2" />
                            Export CSV
                        </Button>
                    </div>
                </div>
            </header>

            <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
                {/* Filters */}
                <div className="flex flex-col sm:flex-row gap-4 mb-6">
                    <div className="relative flex-1 max-w-md">
                        <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
                        <Input
                            type="search"
                            placeholder="Search submissions..."
                            value={search}
                            onChange={(e) => handleSearchChange(e.target.value)}
                            className="pl-10"
                        />
                    </div>
                    <div className="flex items-center gap-2">
                        <Calendar className="w-4 h-4 text-gray-400" />
                        <Input
                            type="date"
                            value={fromDate}
                            onChange={(e) => { setFromDate(e.target.value); setPage(1); }}
                            className="w-40"
                        />
                        <span className="text-gray-500">to</span>
                        <Input
                            type="date"
                            value={toDate}
                            onChange={(e) => { setToDate(e.target.value); setPage(1); }}
                            className="w-40"
                        />
                    </div>
                </div>

                {/* Content */}
                {isLoading ? (
                    <LoadingSpinner />
                ) : isError ? (
                    <ErrorState message="Failed to load submissions" onRetry={() => refetch()} />
                ) : !data?.data.length ? (
                    <EmptyState
                        icon={<FileText className="w-12 h-12" />}
                        title="No submissions yet"
                        description="Submissions will appear here once your form receives responses."
                    />
                ) : (
                    <>
                        {/* Table */}
                        <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
                            <div className="overflow-x-auto">
                                <table className="min-w-full divide-y divide-gray-200">
                                    <thead className="bg-gray-50">
                                        <tr>
                                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                ID
                                            </th>
                                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Submitted
                                            </th>
                                            {fields.slice(0, 3).map((field) => (
                                                <th
                                                    key={field.key}
                                                    className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"
                                                >
                                                    {field.label}
                                                </th>
                                            ))}
                                            <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Actions
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="bg-white divide-y divide-gray-200">
                                        {data.data.map((submission) => (
                                            <tr key={submission.id} className="hover:bg-gray-50">
                                                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                    #{submission.id}
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    {new Date(submission.submitted_at).toLocaleString()}
                                                </td>
                                                {fields.slice(0, 3).map((field) => (
                                                    <td
                                                        key={field.key}
                                                        className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 max-w-xs truncate"
                                                    >
                                                        {formatValue(submission.data[field.key])}
                                                    </td>
                                                ))}
                                                <td className="px-6 py-4 whitespace-nowrap text-right text-sm">
                                                    <button
                                                        onClick={() => setSelectedSubmissionId(submission.id)}
                                                        className="text-blue-600 hover:text-blue-800 mr-3"
                                                        title="View"
                                                    >
                                                        <Eye className="w-4 h-4" />
                                                    </button>
                                                    <button
                                                        onClick={() => setDeleteSubmissionId(submission.id)}
                                                        className="text-red-600 hover:text-red-800"
                                                        title="Delete"
                                                    >
                                                        <Trash2 className="w-4 h-4" />
                                                    </button>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        {/* Pagination */}
                        {data.meta.last_page > 1 && (
                            <div className="flex items-center justify-between mt-6">
                                <p className="text-sm text-gray-500">
                                    Showing {(data.meta.current_page - 1) * data.meta.per_page + 1} to{' '}
                                    {Math.min(data.meta.current_page * data.meta.per_page, data.meta.total)} of{' '}
                                    {data.meta.total} submissions
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

            {/* Submission Detail Modal */}
            {selectedSubmissionId && (
                <SubmissionDetailModal
                    formId={form.id}
                    submissionId={selectedSubmissionId}
                    fields={fields}
                    onClose={() => setSelectedSubmissionId(null)}
                />
            )}

            {/* Delete Confirmation */}
            <ConfirmDialog
                open={deleteSubmissionId !== null}
                onClose={() => setDeleteSubmissionId(null)}
                onConfirm={handleDelete}
                title="Delete Submission"
                message="Are you sure you want to delete this submission? This action cannot be undone."
                confirmText="Delete"
                variant="danger"
                loading={deleteSubmission.isPending}
            />
        </div>
    );
}

interface SubmissionDetailModalProps {
    formId: number;
    submissionId: number;
    fields: { key: string; label: string; type: string }[];
    onClose: () => void;
}

function SubmissionDetailModal({ formId, submissionId, fields, onClose }: SubmissionDetailModalProps) {
    const { data: submission, isLoading } = useSubmission(formId, submissionId);

    return (
        <Modal open={true} onClose={onClose} title={`Submission #${submissionId}`}>
            {isLoading ? (
                <LoadingSpinner className="py-8" />
            ) : submission ? (
                <div className="space-y-4 max-h-[60vh] overflow-y-auto">
                    <div className="text-sm text-gray-500">
                        Submitted: {new Date(submission.submitted_at).toLocaleString()}
                    </div>

                    {fields.map((field) => {
                        const value = submission.data[field.key];
                        const files = submission.files?.filter((f) => f.field_key === field.key) || [];

                        return (
                            <div key={field.key} className="border-b border-gray-100 pb-3">
                                <div className="text-sm font-medium text-gray-700">{field.label}</div>
                                {field.type === 'file' && files.length > 0 ? (
                                    <div className="mt-1 space-y-1">
                                        {files.map((file) => (
                                            <a
                                                key={file.id}
                                                href={getFileDownloadUrl(formId, submissionId, file.id)}
                                                className="text-blue-600 hover:text-blue-800 text-sm flex items-center gap-1"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                            >
                                                <Download className="w-3 h-3" />
                                                {file.original_name}
                                            </a>
                                        ))}
                                    </div>
                                ) : (
                                    <div className="mt-1 text-gray-900">{formatValue(value) || '-'}</div>
                                )}
                            </div>
                        );
                    })}
                </div>
            ) : (
                <ErrorState message="Failed to load submission" />
            )}
        </Modal>
    );
}

function getFieldsFromSchema(schema: FormSchema): { key: string; label: string; type: string }[] {
    const fields: { key: string; label: string; type: string }[] = [];
    for (const section of schema.sections || []) {
        for (const field of section.fields || []) {
            if (field.type === 'heading') continue;
            fields.push({
                key: field.key,
                label: field.label || field.key,
                type: field.type,
            });
        }
    }
    return fields;
}

function formatValue(value: unknown): string {
    if (value === null || value === undefined) return '';
    if (Array.isArray(value)) return value.join(', ');
    if (typeof value === 'boolean') return value ? 'Yes' : 'No';
    return String(value);
}
