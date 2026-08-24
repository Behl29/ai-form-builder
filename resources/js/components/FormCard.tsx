import { clsx } from 'clsx';
import {
    Archive,
    Copy,
    Edit,
    Eye,
    MoreVertical,
    RotateCcw,
    Trash2,
    Upload,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';
import type { Form, FormStatus } from '../types/form-schema';
import { Badge, Button, ConfirmDialog } from './ui';

interface FormCardProps {
    form: Form;
    onEdit: (form: Form) => void;
    onDuplicate: (form: Form) => void;
    onPublish: (form: Form) => void;
    onUnpublish: (form: Form) => void;
    onArchive: (form: Form) => void;
    onRestore: (form: Form) => void;
    onDelete: (form: Form) => void;
    isLoading?: boolean;
}

const statusConfig: Record<FormStatus, { label: string; variant: 'default' | 'success' | 'warning' | 'danger' }> = {
    draft: { label: 'Draft', variant: 'default' },
    published: { label: 'Published', variant: 'success' },
    archived: { label: 'Archived', variant: 'warning' },
};

export function FormCard({
    form,
    onEdit,
    onDuplicate,
    onPublish,
    onUnpublish,
    onArchive,
    onRestore,
    onDelete,
    isLoading,
}: FormCardProps) {
    const [menuOpen, setMenuOpen] = useState(false);
    const [confirmAction, setConfirmAction] = useState<'delete' | 'archive' | null>(null);

    const status = statusConfig[form.status];
    const updatedAt = new Date(form.updated_at).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });

    const handleAction = (action: () => void) => {
        setMenuOpen(false);
        action();
    };

    return (
        <>
            <div className={clsx(
                'bg-white rounded-xl border border-gray-200 p-5 hover:shadow-md transition-shadow',
                isLoading && 'opacity-50 pointer-events-none'
            )}>
                <div className="flex items-start justify-between mb-3">
                    <div className="flex-1 min-w-0">
                        <h3 className="text-lg font-semibold text-gray-900 truncate">{form.title}</h3>
                        {form.description && (
                            <p className="text-sm text-gray-500 mt-1 line-clamp-2">{form.description}</p>
                        )}
                    </div>
                    <div className="relative ml-4">
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => setMenuOpen(!menuOpen)}
                            className="p-1"
                        >
                            <MoreVertical className="w-5 h-5" />
                        </Button>

                        {menuOpen && (
                            <>
                                <div className="fixed inset-0 z-10" onClick={() => setMenuOpen(false)} />
                                <div className="absolute right-0 mt-1 w-48 bg-white rounded-lg shadow-lg border border-gray-200 py-1 z-20">
                                    <MenuItem icon={<Edit className="w-4 h-4" />} onClick={() => handleAction(() => onEdit(form))}>
                                        Edit
                                    </MenuItem>
                                    <MenuItem icon={<Eye className="w-4 h-4" />} onClick={() => handleAction(() => window.open(`/forms/${form.slug}/preview`, '_blank'))}>
                                        Preview
                                    </MenuItem>
                                    <MenuItem icon={<Copy className="w-4 h-4" />} onClick={() => handleAction(() => onDuplicate(form))}>
                                        Duplicate
                                    </MenuItem>

                                    <div className="border-t border-gray-100 my-1" />

                                    {form.status === 'draft' && (
                                        <MenuItem icon={<Upload className="w-4 h-4" />} onClick={() => handleAction(() => onPublish(form))}>
                                            Publish
                                        </MenuItem>
                                    )}
                                    {form.status === 'published' && (
                                        <MenuItem icon={<XCircle className="w-4 h-4" />} onClick={() => handleAction(() => onUnpublish(form))}>
                                            Unpublish
                                        </MenuItem>
                                    )}
                                    {form.status !== 'archived' && (
                                        <MenuItem icon={<Archive className="w-4 h-4" />} onClick={() => { setMenuOpen(false); setConfirmAction('archive'); }}>
                                            Archive
                                        </MenuItem>
                                    )}
                                    {form.status === 'archived' && (
                                        <MenuItem icon={<RotateCcw className="w-4 h-4" />} onClick={() => handleAction(() => onRestore(form))}>
                                            Restore
                                        </MenuItem>
                                    )}

                                    <div className="border-t border-gray-100 my-1" />

                                    <MenuItem
                                        icon={<Trash2 className="w-4 h-4" />}
                                        onClick={() => { setMenuOpen(false); setConfirmAction('delete'); }}
                                        danger
                                    >
                                        Delete
                                    </MenuItem>
                                </div>
                            </>
                        )}
                    </div>
                </div>

                <div className="flex items-center justify-between text-sm">
                    <Badge variant={status.variant}>{status.label}</Badge>
                    <span className="text-gray-500">Updated {updatedAt}</span>
                </div>
            </div>

            <ConfirmDialog
                open={confirmAction === 'delete'}
                onClose={() => setConfirmAction(null)}
                onConfirm={() => {
                    onDelete(form);
                    setConfirmAction(null);
                }}
                title="Delete Form"
                message={`Are you sure you want to delete "${form.title}"? This action cannot be undone.`}
                confirmText="Delete"
                variant="danger"
            />

            <ConfirmDialog
                open={confirmAction === 'archive'}
                onClose={() => setConfirmAction(null)}
                onConfirm={() => {
                    onArchive(form);
                    setConfirmAction(null);
                }}
                title="Archive Form"
                message={`Are you sure you want to archive "${form.title}"? You can restore it later.`}
                confirmText="Archive"
                variant="primary"
            />
        </>
    );
}

interface MenuItemProps {
    icon: React.ReactNode;
    onClick: () => void;
    danger?: boolean;
    children: React.ReactNode;
}

function MenuItem({ icon, onClick, danger, children }: MenuItemProps) {
    return (
        <button
            onClick={onClick}
            className={clsx(
                'w-full flex items-center gap-2 px-3 py-2 text-sm text-left hover:bg-gray-50',
                danger ? 'text-red-600' : 'text-gray-700'
            )}
        >
            {icon}
            {children}
        </button>
    );
}
