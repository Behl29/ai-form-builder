import { useState } from 'react';
import { Button, Input, Modal } from './ui';
import api from '../lib/api';

interface CreateFormModalProps {
    open: boolean;
    onClose: () => void;
    onSubmit: (data: { title: string; description?: string }) => void;
    onFormCreated?: () => void;
    loading?: boolean;
}

export function CreateFormModal({ open, onClose, onSubmit, onFormCreated, loading }: CreateFormModalProps) {
    const [mode, setMode] = useState<'manual' | 'ai'>('manual');
    const [title, setTitle] = useState('');
    const [description, setDescription] = useState('');
    const [aiPrompt, setAiPrompt] = useState('');
    const [errors, setErrors] = useState<{ title?: string; prompt?: string }>({});
    const [aiLoading, setAiLoading] = useState(false);
    const [aiStatus, setAiStatus] = useState('');

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();

        if (mode === 'manual') {
            if (!title.trim()) {
                setErrors({ title: 'Title is required' });
                return;
            }
            onSubmit({
                title: title.trim(),
                description: description.trim() || undefined,
            });
        } else {
            handleAiGenerate();
        }
    };

    const handleAiGenerate = async () => {
        if (!aiPrompt.trim()) {
            setErrors({ prompt: 'Please describe the form you want to create' });
            return;
        }

        setAiLoading(true);
        setAiStatus('Generating form with AI...');
        setErrors({});

        try {
            // Start AI generation
            const { data } = await api.post('/ai/generate', {
                prompt: aiPrompt.trim(),
            });

            const jobUuid = data.job_uuid;
            setAiStatus('Processing...');

            // Poll for status
            let attempts = 0;
            const maxAttempts = 20;

            const pollStatus = async () => {
                if (attempts >= maxAttempts) {
                    setAiStatus('Taking longer than expected...');
                    setAiLoading(false);
                    return;
                }

                try {
                    const statusRes = await api.get(`/ai/jobs/${jobUuid}`);
                    const status = statusRes.data.status;

                    if (status === 'succeeded') {
                        setAiStatus('Creating form...');
                        // Create form with generated schema
                        await api.post('/ai/create-form', { job_uuid: jobUuid });
                        setAiStatus('Form created!');
                        handleClose();
                        onFormCreated?.();
                    } else if (status === 'failed') {
                        setErrors({ prompt: statusRes.data.error_message || 'AI generation failed' });
                        setAiLoading(false);
                        setAiStatus('');
                    } else {
                        // Still running or queued
                        attempts++;
                        setTimeout(pollStatus, 5000); // 5 seconds between polls
                    }
                } catch (err: any) {
                    if (err.response?.status === 429) {
                        // Rate limited, wait longer
                        const retryAfter = err.response?.data?.retry_after || 60;
                        setAiStatus(`Rate limited, waiting ${retryAfter}s...`);
                        setTimeout(pollStatus, retryAfter * 1000);
                    } else {
                        setErrors({ prompt: 'Failed to check status' });
                        setAiLoading(false);
                        setAiStatus('');
                    }
                }
            };

            // Wait a bit before first poll
            setTimeout(pollStatus, 3000);
        } catch (err: any) {
            setErrors({ prompt: err.response?.data?.message || 'Failed to generate form' });
            setAiLoading(false);
            setAiStatus('');
        }
    };

    const handleClose = () => {
        setTitle('');
        setDescription('');
        setAiPrompt('');
        setErrors({});
        setMode('manual');
        setAiLoading(false);
        setAiStatus('');
        onClose();
    };

    return (
        <Modal open={open} onClose={handleClose} title="Create New Form">
            {/* Mode Toggle */}
            <div className="flex gap-2 mb-4 p-1 bg-gray-100 rounded-lg">
                <button
                    type="button"
                    onClick={() => setMode('manual')}
                    className={`flex-1 py-2 px-4 rounded-md text-sm font-medium transition-colors ${
                        mode === 'manual'
                            ? 'bg-white text-blue-600 shadow-sm'
                            : 'text-gray-600 hover:text-gray-900'
                    }`}
                >
                    Manual
                </button>
                <button
                    type="button"
                    onClick={() => setMode('ai')}
                    className={`flex-1 py-2 px-4 rounded-md text-sm font-medium transition-colors ${
                        mode === 'ai'
                            ? 'bg-white text-blue-600 shadow-sm'
                            : 'text-gray-600 hover:text-gray-900'
                    }`}
                >
                    ✨ AI Generate
                </button>
            </div>

            <form onSubmit={handleSubmit} className="space-y-4">
                {mode === 'manual' ? (
                    <>
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
                    </>
                ) : (
                    <>
                        <div className="space-y-1">
                            <label htmlFor="aiPrompt" className="block text-sm font-medium text-gray-700">
                                Describe your form
                            </label>
                            <textarea
                                id="aiPrompt"
                                value={aiPrompt}
                                onChange={(e) => {
                                    setAiPrompt(e.target.value);
                                    setErrors({});
                                }}
                                placeholder="e.g., Create a contact form with name, email, phone number, and message fields"
                                rows={4}
                                className={`block w-full px-3 py-2 border rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 sm:text-sm ${
                                    errors.prompt ? 'border-red-500' : 'border-gray-300'
                                }`}
                                disabled={aiLoading}
                                autoFocus
                            />
                            {errors.prompt && (
                                <p className="text-sm text-red-600">{errors.prompt}</p>
                            )}
                        </div>

                        <div className="bg-blue-50 p-3 rounded-lg text-sm text-blue-700">
                            <p className="font-medium mb-1">💡 Tips:</p>
                            <ul className="list-disc list-inside space-y-1 text-blue-600">
                                <li>Be specific about the fields you need</li>
                                <li>Mention field types (text, email, dropdown, etc.)</li>
                                <li>Include validation requirements if needed</li>
                            </ul>
                        </div>

                        {aiStatus && (
                            <div className="flex items-center gap-2 text-sm text-gray-600">
                                <svg className="animate-spin h-4 w-4" viewBox="0 0 24 24">
                                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" fill="none" />
                                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                                </svg>
                                {aiStatus}
                            </div>
                        )}
                    </>
                )}

                <div className="flex justify-end gap-3 pt-4">
                    <Button type="button" variant="secondary" onClick={handleClose} disabled={loading || aiLoading}>
                        Cancel
                    </Button>
                    <Button type="submit" loading={loading || aiLoading}>
                        {mode === 'manual' ? 'Create Form' : '✨ Generate with AI'}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
