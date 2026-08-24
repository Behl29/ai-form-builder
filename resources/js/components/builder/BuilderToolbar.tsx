import { clsx } from 'clsx';
import {
    ArrowLeft,
    Check,
    Cloud,
    CloudOff,
    Code,
    Eye,
    Loader2,
    Monitor,
    Save,
    Smartphone,
    Upload,
} from 'lucide-react';
import { Button } from '../ui';
import { useBuilder } from './BuilderContext';

interface BuilderToolbarProps {
    formTitle: string;
    formStatus: 'draft' | 'published' | 'archived';
    saveStatus: 'idle' | 'saving' | 'saved' | 'error';
    lastSaved?: Date;
    onBack: () => void;
    onSave: () => void;
    onPublish: () => void;
    onPreview: () => void;
    onToggleJson?: () => void;
    isSaving?: boolean;
    isPublishing?: boolean;
}

export function BuilderToolbar({
    formTitle,
    formStatus,
    saveStatus,
    lastSaved,
    onBack,
    onSave,
    onPublish,
    onPreview,
    onToggleJson,
    isSaving,
    isPublishing,
}: BuilderToolbarProps) {
    const { state, setPreviewMode } = useBuilder();
    const { previewMode, isDirty } = state;

    return (
        <header className="bg-white border-b border-gray-200 px-4 py-3">
            <div className="flex items-center justify-between gap-4">
                {/* Left: Back + Title */}
                <div className="flex items-center gap-3 min-w-0">
                    <button
                        onClick={onBack}
                        className="p-2 text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                        title="Back to forms"
                    >
                        <ArrowLeft className="w-5 h-5" />
                    </button>
                    <div className="min-w-0">
                        <h1 className="text-lg font-semibold text-gray-900 truncate">
                            {formTitle || 'Untitled Form'}
                        </h1>
                        <SaveStatus status={saveStatus} lastSaved={lastSaved} isDirty={isDirty} />
                    </div>
                </div>

                {/* Center: Preview Mode Toggle */}
                <div className="hidden sm:flex items-center gap-1 bg-gray-100 p-1 rounded-lg">
                    <button
                        onClick={() => setPreviewMode('desktop')}
                        className={clsx(
                            'p-2 rounded-md transition-colors focus:outline-none focus:ring-2 focus:ring-blue-500',
                            previewMode === 'desktop'
                                ? 'bg-white text-gray-900 shadow-sm'
                                : 'text-gray-500 hover:text-gray-700'
                        )}
                        title="Desktop preview"
                    >
                        <Monitor className="w-4 h-4" />
                    </button>
                    <button
                        onClick={() => setPreviewMode('mobile')}
                        className={clsx(
                            'p-2 rounded-md transition-colors focus:outline-none focus:ring-2 focus:ring-blue-500',
                            previewMode === 'mobile'
                                ? 'bg-white text-gray-900 shadow-sm'
                                : 'text-gray-500 hover:text-gray-700'
                        )}
                        title="Mobile preview"
                    >
                        <Smartphone className="w-4 h-4" />
                    </button>
                </div>

                {/* Right: Actions */}
                <div className="flex items-center gap-2">
                    {onToggleJson && (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={onToggleJson}
                            title="Edit JSON"
                        >
                            <Code className="w-4 h-4" />
                        </Button>
                    )}

                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={onPreview}
                        title="Preview form"
                    >
                        <Eye className="w-4 h-4 sm:mr-2" />
                        <span className="hidden sm:inline">Preview</span>
                    </Button>

                    <Button
                        variant="secondary"
                        size="sm"
                        onClick={onSave}
                        loading={isSaving}
                        disabled={!isDirty || isSaving}
                    >
                        <Save className="w-4 h-4 sm:mr-2" />
                        <span className="hidden sm:inline">Save</span>
                    </Button>

                    {formStatus !== 'published' && (
                        <Button
                            variant="primary"
                            size="sm"
                            onClick={onPublish}
                            loading={isPublishing}
                            disabled={isPublishing || formStatus === 'archived'}
                        >
                            <Upload className="w-4 h-4 sm:mr-2" />
                            <span className="hidden sm:inline">Publish</span>
                        </Button>
                    )}
                </div>
            </div>
        </header>
    );
}

interface SaveStatusProps {
    status: 'idle' | 'saving' | 'saved' | 'error';
    lastSaved?: Date;
    isDirty: boolean;
}

function SaveStatus({ status, lastSaved, isDirty }: SaveStatusProps) {
    const formatTime = (date: Date) => {
        return date.toLocaleTimeString('en-US', {
            hour: 'numeric',
            minute: '2-digit',
        });
    };

    if (status === 'saving') {
        return (
            <div className="flex items-center gap-1 text-xs text-gray-500">
                <Loader2 className="w-3 h-3 animate-spin" />
                <span>Saving...</span>
            </div>
        );
    }

    if (status === 'error') {
        return (
            <div className="flex items-center gap-1 text-xs text-red-600">
                <CloudOff className="w-3 h-3" />
                <span>Save failed</span>
            </div>
        );
    }

    if (status === 'saved' && lastSaved) {
        return (
            <div className="flex items-center gap-1 text-xs text-green-600">
                <Check className="w-3 h-3" />
                <span>Saved at {formatTime(lastSaved)}</span>
            </div>
        );
    }

    if (isDirty) {
        return (
            <div className="flex items-center gap-1 text-xs text-amber-600">
                <Cloud className="w-3 h-3" />
                <span>Unsaved changes</span>
            </div>
        );
    }

    if (lastSaved) {
        return (
            <div className="flex items-center gap-1 text-xs text-gray-500">
                <Cloud className="w-3 h-3" />
                <span>Last saved {formatTime(lastSaved)}</span>
            </div>
        );
    }

    return null;
}
