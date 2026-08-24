import {
    closestCenter,
    DndContext,
    DragEndEvent,
    DragOverEvent,
    DragOverlay,
    DragStartEvent,
    KeyboardSensor,
    PointerSensor,
    useSensor,
    useSensors,
} from '@dnd-kit/core';
import { sortableKeyboardCoordinates } from '@dnd-kit/sortable';
import { useCallback, useState } from 'react';
import type { FieldType, Form, FormField, FormSchema } from '../../types/form-schema';
import { useAutosave } from '../../hooks/useAutosave';
import { usePublishForm } from '../../hooks/useForms';
import { ErrorState } from '../ui';
import { BuilderProvider, useBuilder } from './BuilderContext';
import { BuilderToolbar } from './BuilderToolbar';
import { ConfigPanel } from './ConfigPanel';
import { FieldDragOverlay, FormCanvas } from './FormCanvas';
import { FieldPalette, PaletteFieldOverlay } from './FieldPalette';
import { JsonEditor } from './JsonEditor';

interface FormBuilderProps {
    form: Form;
    onBack: () => void;
    onSaved?: () => void;
}

export function FormBuilder({ form, onBack, onSaved }: FormBuilderProps) {
    const initialSchema = form.current_version?.schema;

    if (!initialSchema) {
        return <ErrorState message="Form has no schema" />;
    }

    return (
        <BuilderProvider initialSchema={initialSchema}>
            <FormBuilderInner form={form} onBack={onBack} onSaved={onSaved} />
        </BuilderProvider>
    );
}

interface FormBuilderInnerProps {
    form: Form;
    onBack: () => void;
    onSaved?: () => void;
}

function FormBuilderInner({ form, onBack, onSaved }: FormBuilderInnerProps) {
    const { state, setSchema, addField, moveField, reorderFields, reorderSections, markSaved } = useBuilder();
    const { schema, isDirty } = state;

    const [activeId, setActiveId] = useState<string | null>(null);
    const [activeType, setActiveType] = useState<'field' | 'palette' | 'section' | null>(null);
    const [activeData, setActiveData] = useState<FormField | FieldType | null>(null);
    const [showJsonEditor, setShowJsonEditor] = useState(false);

    const publishForm = usePublishForm();

    // Autosave
    const { status: saveStatus, lastSaved, save, isSaving } = useAutosave({
        formId: form.id,
        schema,
        isDirty,
        debounceMs: 2000,
        onSaveSuccess: (savedSchema) => {
            markSaved(savedSchema);
            onSaved?.();
        },
    });

    // DnD sensors
    const sensors = useSensors(
        useSensor(PointerSensor, {
            activationConstraint: { distance: 8 },
        }),
        useSensor(KeyboardSensor, {
            coordinateGetter: sortableKeyboardCoordinates,
        })
    );

    // Handle drag start
    const handleDragStart = useCallback((event: DragStartEvent) => {
        const { active } = event;
        const data = active.data.current;

        setActiveId(String(active.id));

        if (data?.type === 'palette-field') {
            setActiveType('palette');
            setActiveData(data.fieldType as FieldType);
        } else if (data?.type === 'field') {
            setActiveType('field');
            setActiveData(data.field as FormField);
        } else if (data?.type === 'section') {
            setActiveType('section');
            setActiveData(null);
        }
    }, []);

    // Handle drag over
    const handleDragOver = useCallback((_event: DragOverEvent) => {
        // Visual feedback handled by drop zones
    }, []);

    // Handle drag end
    const handleDragEnd = useCallback((event: DragEndEvent) => {
        const { active, over } = event;

        setActiveId(null);
        setActiveType(null);
        setActiveData(null);

        if (!over) return;

        const activeData = active.data.current;
        const overData = over.data.current;

        // Palette field dropped on section
        if (activeData?.type === 'palette-field' && overData?.type === 'section-drop') {
            const fieldType = activeData.fieldType as FieldType;
            const sectionId = overData.sectionId as string;
            addField(sectionId, fieldType);
            return;
        }

        // Palette field dropped on existing field
        if (activeData?.type === 'palette-field' && overData?.type === 'field') {
            const fieldType = activeData.fieldType as FieldType;
            const targetSectionId = overData.sectionId as string;
            const targetField = overData.field as FormField;

            const section = state.schema.sections.find((s) => s.id === targetSectionId);
            if (section) {
                const targetIndex = section.fields.findIndex((f) => f.id === targetField.id);
                addField(targetSectionId, fieldType, targetIndex);
            }
            return;
        }

        // Field reordering
        if (activeData?.type === 'field' && overData?.type === 'field') {
            const activeField = activeData.field as FormField;
            const overField = overData.field as FormField;
            const activeSectionId = activeData.sectionId as string;
            const overSectionId = overData.sectionId as string;

            if (activeSectionId === overSectionId) {
                const section = state.schema.sections.find((s) => s.id === activeSectionId);
                if (section) {
                    const oldIndex = section.fields.findIndex((f) => f.id === activeField.id);
                    const newIndex = section.fields.findIndex((f) => f.id === overField.id);
                    if (oldIndex !== newIndex) {
                        reorderFields(activeSectionId, oldIndex, newIndex);
                    }
                }
            } else {
                const targetSection = state.schema.sections.find((s) => s.id === overSectionId);
                if (targetSection) {
                    const targetIndex = targetSection.fields.findIndex((f) => f.id === overField.id);
                    moveField(activeField.id, overSectionId, targetIndex);
                }
            }
            return;
        }

        // Field dropped on empty section
        if (activeData?.type === 'field' && overData?.type === 'section-drop') {
            const activeField = activeData.field as FormField;
            const targetSectionId = overData.sectionId as string;
            const activeSectionId = activeData.sectionId as string;

            if (activeSectionId !== targetSectionId) {
                const targetSection = state.schema.sections.find((s) => s.id === targetSectionId);
                if (targetSection) {
                    moveField(activeField.id, targetSectionId, targetSection.fields.length);
                }
            }
            return;
        }

        // Section reordering
        if (activeData?.type === 'section' && overData?.type === 'section') {
            const oldIndex = state.schema.sections.findIndex((s) => s.id === active.id);
            const newIndex = state.schema.sections.findIndex((s) => s.id === over.id);
            if (oldIndex !== newIndex) {
                reorderSections(oldIndex, newIndex);
            }
            return;
        }
    }, [state.schema.sections, addField, moveField, reorderFields, reorderSections]);

    // Handle manual save
    const handleSave = useCallback(() => {
        save();
    }, [save]);

    // Handle publish
    const handlePublish = useCallback(async () => {
        if (isDirty) {
            await save();
        }
        await publishForm.mutateAsync(form.id);
    }, [isDirty, save, publishForm, form.id]);

    // Handle preview
    const handlePreview = useCallback(() => {
        window.open(`/forms/${form.slug}`, '_blank');
    }, [form.slug]);

    // Handle JSON editor update
    const handleJsonUpdate = useCallback((newSchema: FormSchema) => {
        setSchema(newSchema);
    }, [setSchema]);

    // Render drag overlay
    const renderDragOverlay = () => {
        if (!activeId) return null;

        if (activeType === 'palette' && activeData) {
            return <PaletteFieldOverlay type={activeData as FieldType} />;
        }

        if (activeType === 'field' && activeData) {
            return <FieldDragOverlay field={activeData as FormField} />;
        }

        return null;
    };

    return (
        <div className="h-screen flex flex-col bg-gray-50">
            <BuilderToolbar
                formTitle={schema.metadata.title}
                formStatus={form.status}
                saveStatus={saveStatus}
                lastSaved={lastSaved ?? undefined}
                onBack={onBack}
                onSave={handleSave}
                onPublish={handlePublish}
                onPreview={handlePreview}
                onToggleJson={() => setShowJsonEditor(true)}
                isSaving={isSaving}
                isPublishing={publishForm.isPending}
            />

            <DndContext
                sensors={sensors}
                collisionDetection={closestCenter}
                onDragStart={handleDragStart}
                onDragOver={handleDragOver}
                onDragEnd={handleDragEnd}
            >
                <div className="flex-1 flex overflow-hidden">
                    <FieldPalette />
                    <FormCanvas />
                    <ConfigPanel />
                </div>

                <DragOverlay dropAnimation={null}>
                    {renderDragOverlay()}
                </DragOverlay>
            </DndContext>

            {/* JSON Editor Modal */}
            {showJsonEditor && (
                <JsonEditor
                    schema={schema}
                    onUpdate={handleJsonUpdate}
                    onClose={() => setShowJsonEditor(false)}
                />
            )}
        </div>
    );
}
