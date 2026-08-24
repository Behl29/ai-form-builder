import {
    SortableContext,
    useSortable,
    verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { useDroppable } from '@dnd-kit/core';
import { clsx } from 'clsx';
import { Copy, GripVertical, Plus, Settings, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { FormField, FormSection } from '../../types/form-schema';
import { Button, ConfirmDialog } from '../ui';
import { useBuilder } from './BuilderContext';
import { FieldRenderer } from './FieldRenderer';

export function FormCanvas() {
    const { state, addSection, selectSection, selectField } = useBuilder();
    const { schema, previewMode } = state;

    const handleCanvasClick = (e: React.MouseEvent) => {
        if (e.target === e.currentTarget) {
            selectField(null);
            selectSection(null);
        }
    };

    return (
        <div
            className={clsx(
                'flex-1 bg-gray-100 overflow-auto p-6',
                previewMode === 'mobile' && 'flex justify-center'
            )}
            onClick={handleCanvasClick}
        >
            <div
                className={clsx(
                    'mx-auto transition-all duration-300',
                    previewMode === 'desktop' ? 'max-w-3xl' : 'max-w-sm'
                )}
            >
                {/* Form Header */}
                <div className="bg-white rounded-t-xl border border-gray-200 p-6">
                    <h1 className="text-2xl font-bold text-gray-900">
                        {schema.metadata.title || 'Untitled Form'}
                    </h1>
                    {schema.metadata.description && (
                        <p className="text-gray-600 mt-2">{schema.metadata.description}</p>
                    )}
                </div>

                {/* Sections */}
                <SortableContext
                    items={schema.sections.map((s) => s.id)}
                    strategy={verticalListSortingStrategy}
                >
                    {schema.sections.map((section, index) => (
                        <SortableSection
                            key={section.id}
                            section={section}
                            index={index}
                            isLast={index === schema.sections.length - 1}
                        />
                    ))}
                </SortableContext>

                {/* Add Section Button */}
                <div className="mt-4 flex justify-center">
                    <Button variant="secondary" onClick={() => addSection()}>
                        <Plus className="w-4 h-4 mr-2" />
                        Add Section
                    </Button>
                </div>

                {/* Empty State */}
                {schema.sections.length === 0 && (
                    <div className="bg-white rounded-xl border-2 border-dashed border-gray-300 p-12 text-center">
                        <p className="text-gray-500">No sections yet</p>
                        <Button variant="primary" className="mt-4" onClick={() => addSection()}>
                            <Plus className="w-4 h-4 mr-2" />
                            Add First Section
                        </Button>
                    </div>
                )}
            </div>
        </div>
    );
}

interface SortableSectionProps {
    section: FormSection;
    index: number;
    isLast: boolean;
}

function SortableSection({ section, isLast }: SortableSectionProps) {
    const { state, selectSection, updateSection, deleteSection } = useBuilder();
    const [showDeleteConfirm, setShowDeleteConfirm] = useState(false);
    const [isEditingTitle, setIsEditingTitle] = useState(false);

    const {
        attributes,
        listeners,
        setNodeRef,
        transform,
        transition,
        isDragging,
    } = useSortable({
        id: section.id,
        data: { type: 'section', section },
    });

    const style = {
        transform: CSS.Transform.toString(transform),
        transition,
    };

    const isSelected = state.selectedSectionId === section.id;

    const handleTitleDoubleClick = () => {
        setIsEditingTitle(true);
    };

    const handleTitleBlur = (e: React.FocusEvent<HTMLInputElement>) => {
        setIsEditingTitle(false);
        updateSection(section.id, { title: e.target.value || 'Untitled Section' });
    };

    const handleTitleKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
        if (e.key === 'Enter') {
            e.currentTarget.blur();
        }
    };

    return (
        <>
            <div
                ref={setNodeRef}
                style={style}
                className={clsx(
                    'bg-white border border-gray-200 transition-all',
                    isDragging && 'opacity-50 shadow-lg',
                    isSelected && 'ring-2 ring-blue-500',
                    !isLast && 'border-b-0'
                )}
                onClick={(e) => {
                    e.stopPropagation();
                    selectSection(section.id);
                }}
            >
                {/* Section Header */}
                <div className="flex items-center gap-2 px-4 py-3 bg-gray-50 border-b border-gray-200">
                    <button
                        {...attributes}
                        {...listeners}
                        className="p-1 text-gray-400 hover:text-gray-600 cursor-grab active:cursor-grabbing focus:outline-none focus:ring-2 focus:ring-blue-500 rounded"
                    >
                        <GripVertical className="w-4 h-4" />
                    </button>

                    {isEditingTitle ? (
                        <input
                            type="text"
                            defaultValue={section.title}
                            onBlur={handleTitleBlur}
                            onKeyDown={handleTitleKeyDown}
                            autoFocus
                            className="flex-1 px-2 py-1 text-sm font-medium border border-blue-500 rounded focus:outline-none"
                        />
                    ) : (
                        <span
                            className="flex-1 text-sm font-medium text-gray-700 cursor-text"
                            onDoubleClick={handleTitleDoubleClick}
                        >
                            {section.title || 'Untitled Section'}
                        </span>
                    )}

                    <button
                        onClick={(e) => {
                            e.stopPropagation();
                            selectSection(section.id);
                        }}
                        className="p-1 text-gray-400 hover:text-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500 rounded"
                        title="Section settings"
                    >
                        <Settings className="w-4 h-4" />
                    </button>

                    <button
                        onClick={(e) => {
                            e.stopPropagation();
                            setShowDeleteConfirm(true);
                        }}
                        className="p-1 text-gray-400 hover:text-red-600 focus:outline-none focus:ring-2 focus:ring-red-500 rounded"
                        title="Delete section"
                    >
                        <Trash2 className="w-4 h-4" />
                    </button>
                </div>

                {/* Section Fields */}
                <SectionDropZone section={section} />
            </div>

            <ConfirmDialog
                open={showDeleteConfirm}
                onClose={() => setShowDeleteConfirm(false)}
                onConfirm={() => {
                    deleteSection(section.id);
                    setShowDeleteConfirm(false);
                }}
                title="Delete Section"
                message={`Are you sure you want to delete "${section.title || 'this section'}"? All fields in this section will be deleted.`}
                confirmText="Delete"
                variant="danger"
            />
        </>
    );
}

interface SectionDropZoneProps {
    section: FormSection;
}

function SectionDropZone({ section }: SectionDropZoneProps) {
    const { addField } = useBuilder();
    const { setNodeRef, isOver } = useDroppable({
        id: `section-${section.id}`,
        data: { type: 'section-drop', sectionId: section.id },
    });

    return (
        <div
            ref={setNodeRef}
            className={clsx(
                'min-h-[100px] p-4 transition-colors',
                isOver && 'bg-blue-50'
            )}
        >
            {section.fields.length > 0 ? (
                <SortableContext
                    items={section.fields.map((f) => f.id)}
                    strategy={verticalListSortingStrategy}
                >
                    <div className="space-y-3">
                        {section.fields.map((field) => (
                            <SortableField key={field.id} field={field} sectionId={section.id} />
                        ))}
                    </div>
                </SortableContext>
            ) : (
                <div
                    className={clsx(
                        'border-2 border-dashed rounded-lg p-8 text-center transition-colors',
                        isOver ? 'border-blue-400 bg-blue-50' : 'border-gray-300'
                    )}
                >
                    <p className="text-gray-500 text-sm">
                        Drag fields here or click a field type to add
                    </p>
                    <Button
                        variant="ghost"
                        size="sm"
                        className="mt-2"
                        onClick={() => addField(section.id, 'text')}
                    >
                        <Plus className="w-4 h-4 mr-1" />
                        Add Field
                    </Button>
                </div>
            )}
        </div>
    );
}

interface SortableFieldProps {
    field: FormField;
    sectionId: string;
}

function SortableField({ field, sectionId }: SortableFieldProps) {
    const { state, selectField, deleteField, duplicateField } = useBuilder();
    const [showDeleteConfirm, setShowDeleteConfirm] = useState(false);

    const {
        attributes,
        listeners,
        setNodeRef,
        transform,
        transition,
        isDragging,
    } = useSortable({
        id: field.id,
        data: { type: 'field', field, sectionId },
    });

    const style = {
        transform: CSS.Transform.toString(transform),
        transition,
    };

    const isSelected = state.selectedFieldId === field.id;

    return (
        <>
            <div
                ref={setNodeRef}
                style={style}
                className={clsx(
                    'group relative bg-white border rounded-lg transition-all',
                    isDragging && 'opacity-50 shadow-lg z-50',
                    isSelected ? 'border-blue-500 ring-2 ring-blue-200' : 'border-gray-200 hover:border-gray-300'
                )}
                onClick={(e) => {
                    e.stopPropagation();
                    selectField(field.id);
                }}
            >
                {/* Field Actions */}
                <div className={clsx(
                    'absolute -top-3 right-2 flex items-center gap-1 bg-white border border-gray-200 rounded-md shadow-sm px-1 py-0.5 transition-opacity',
                    isSelected ? 'opacity-100' : 'opacity-0 group-hover:opacity-100'
                )}>
                    <button
                        {...attributes}
                        {...listeners}
                        className="p-1 text-gray-400 hover:text-gray-600 cursor-grab active:cursor-grabbing focus:outline-none focus:ring-2 focus:ring-blue-500 rounded"
                        title="Drag to reorder"
                    >
                        <GripVertical className="w-3.5 h-3.5" />
                    </button>
                    <button
                        onClick={(e) => {
                            e.stopPropagation();
                            duplicateField(field.id);
                        }}
                        className="p-1 text-gray-400 hover:text-gray-600 focus:outline-none focus:ring-2 focus:ring-blue-500 rounded"
                        title="Duplicate"
                    >
                        <Copy className="w-3.5 h-3.5" />
                    </button>
                    <button
                        onClick={(e) => {
                            e.stopPropagation();
                            setShowDeleteConfirm(true);
                        }}
                        className="p-1 text-gray-400 hover:text-red-600 focus:outline-none focus:ring-2 focus:ring-red-500 rounded"
                        title="Delete"
                    >
                        <Trash2 className="w-3.5 h-3.5" />
                    </button>
                </div>

                {/* Field Content */}
                <div className="p-4">
                    <FieldRenderer field={field} isBuilder />
                </div>
            </div>

            <ConfirmDialog
                open={showDeleteConfirm}
                onClose={() => setShowDeleteConfirm(false)}
                onConfirm={() => {
                    deleteField(field.id);
                    setShowDeleteConfirm(false);
                }}
                title="Delete Field"
                message={`Are you sure you want to delete "${field.label || 'this field'}"?`}
                confirmText="Delete"
                variant="danger"
            />
        </>
    );
}

// Drag overlay for fields
export function FieldDragOverlay({ field }: { field: FormField }) {
    return (
        <div className="bg-white border border-blue-300 rounded-lg shadow-xl p-4 opacity-90">
            <FieldRenderer field={field} isBuilder />
        </div>
    );
}
