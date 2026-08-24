import { useDraggable } from '@dnd-kit/core';
import { clsx } from 'clsx';
import {
    AlignLeft,
    Calendar,
    CheckCircle,
    CheckSquare,
    FileUp,
    Hash,
    Heading,
    Link,
    List,
    Mail,
    Phone,
    Star,
    TextCursorInput,
    Type,
} from 'lucide-react';
import type { ReactNode } from 'react';
import type { FieldType } from '../../types/form-schema';
import { useBuilder } from './BuilderContext';

interface FieldTypeConfig {
    type: FieldType;
    label: string;
    icon: ReactNode;
    category: 'input' | 'choice' | 'layout' | 'advanced';
}

const FIELD_TYPES: FieldTypeConfig[] = [
    { type: 'text', label: 'Text', icon: <Type className="w-4 h-4" />, category: 'input' },
    { type: 'textarea', label: 'Text Area', icon: <AlignLeft className="w-4 h-4" />, category: 'input' },
    { type: 'number', label: 'Number', icon: <Hash className="w-4 h-4" />, category: 'input' },
    { type: 'email', label: 'Email', icon: <Mail className="w-4 h-4" />, category: 'input' },
    { type: 'phone', label: 'Phone', icon: <Phone className="w-4 h-4" />, category: 'input' },
    { type: 'date', label: 'Date', icon: <Calendar className="w-4 h-4" />, category: 'input' },
    { type: 'url', label: 'URL', icon: <Link className="w-4 h-4" />, category: 'input' },
    { type: 'select', label: 'Dropdown', icon: <List className="w-4 h-4" />, category: 'choice' },
    { type: 'radio', label: 'Radio', icon: <CheckCircle className="w-4 h-4" />, category: 'choice' },
    { type: 'checkbox_group', label: 'Checkboxes', icon: <CheckSquare className="w-4 h-4" />, category: 'choice' },
    { type: 'checkbox', label: 'Checkbox', icon: <CheckSquare className="w-4 h-4" />, category: 'choice' },
    { type: 'heading', label: 'Heading', icon: <Heading className="w-4 h-4" />, category: 'layout' },
    { type: 'file', label: 'File Upload', icon: <FileUp className="w-4 h-4" />, category: 'advanced' },
    { type: 'rating', label: 'Rating', icon: <Star className="w-4 h-4" />, category: 'advanced' },
];

const CATEGORIES = [
    { id: 'input', label: 'Input Fields' },
    { id: 'choice', label: 'Choice Fields' },
    { id: 'layout', label: 'Layout' },
    { id: 'advanced', label: 'Advanced' },
] as const;

export function FieldPalette() {
    return (
        <div className="w-64 bg-white border-r border-gray-200 flex flex-col h-full overflow-hidden">
            <div className="p-4 border-b border-gray-200">
                <h2 className="font-semibold text-gray-900">Fields</h2>
                <p className="text-xs text-gray-500 mt-1">Drag or click to add</p>
            </div>
            <div className="flex-1 overflow-y-auto p-3 space-y-4">
                {CATEGORIES.map((category) => (
                    <div key={category.id}>
                        <h3 className="text-xs font-medium text-gray-500 uppercase tracking-wider mb-2">
                            {category.label}
                        </h3>
                        <div className="space-y-1">
                            {FIELD_TYPES.filter((f) => f.category === category.id).map((fieldType) => (
                                <DraggableFieldType key={fieldType.type} config={fieldType} />
                            ))}
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
}

interface DraggableFieldTypeProps {
    config: FieldTypeConfig;
}

function DraggableFieldType({ config }: DraggableFieldTypeProps) {
    const { state, addField } = useBuilder();
    const { attributes, listeners, setNodeRef, isDragging } = useDraggable({
        id: `palette-${config.type}`,
        data: { type: 'palette-field', fieldType: config.type },
    });

    const handleClick = () => {
        // Add to first section if exists
        const firstSection = state.schema.sections[0];
        if (firstSection) {
            addField(firstSection.id, config.type);
        }
    };

    return (
        <button
            ref={setNodeRef}
            {...listeners}
            {...attributes}
            onClick={handleClick}
            className={clsx(
                'w-full flex items-center gap-2 px-3 py-2 rounded-lg text-sm text-left transition-colors',
                'hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-inset',
                isDragging && 'opacity-50'
            )}
        >
            <span className="text-gray-500">{config.icon}</span>
            <span className="text-gray-700">{config.label}</span>
        </button>
    );
}

// Drag overlay for palette items
export function PaletteFieldOverlay({ type }: { type: FieldType }) {
    const config = FIELD_TYPES.find((f) => f.type === type);
    if (!config) return null;

    return (
        <div className="flex items-center gap-2 px-3 py-2 bg-white rounded-lg shadow-lg border border-blue-200 text-sm">
            <span className="text-blue-500">{config.icon}</span>
            <span className="text-gray-700">{config.label}</span>
        </div>
    );
}
