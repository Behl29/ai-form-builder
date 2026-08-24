import { useState } from 'react';
import { Button, Input, Modal } from './ui';

interface CreateFormModalProps {
    open: boolean;
    onClose: () => void;
    onSubmit: (data: { title: string; description?: string }) => void;
    loading?: boolean;
}

export function CreateFormModal({ open, onClose, onSubmit, loading }: CreateFormModalProps) {
    const [title, setTitle] = useState('');
    const [description, setDescription] = useState('');
    const [errors, setErrors] = useState<{ title?: string }>({});

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();

        if (!title.trim()) {
            setErrors({ title: 'Title is required' });
            return;
        }

        onSubmit({
            title: title.trim(),
            description: description.trim() || undefined,
        });
    };

    const handleClose = () => {
        setTitle('');
        setDescription('');
        setErrors({});
        onClose();
    };

    return (
        <Modal open={open} onClose={handleClose} title="Create New Form">
            <form onSubmit={handleSubmit} className="space-y-4">
                <Input
                    id="title"
                    label="Form Title"
                    value={title}
                    onChange={(e) => {
                        setTitle(e.target.value);
                        setErrors({});
                    }}
                    placeholder="Enter form title"
                    error={errors.title}
                    autoFocus
                />

                <div className="space-y-1">
                    <label htmlFor="description" className="block text-sm font-medium text-gray-700">
                        Description (optional)
                    </label>
                    <textarea
                        id="description"
                        value={description}
                        onChange={(e) => setDescription(e.target.value)}
                        placeholder="Enter form description"
                        rows={3}
                        className="block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
                    />
                </div>

                <div className="flex justify-end gap-3 pt-4">
                    <Button type="button" variant="secondary" onClick={handleClose} disabled={loading}>
                        Cancel
                    </Button>
                    <Button type="submit" loading={loading}>
                        Create Form
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
