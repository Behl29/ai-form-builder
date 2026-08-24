import { act, renderHook } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import type { ReactNode } from 'react';
import { BuilderProvider, useBuilder } from '../components/builder/BuilderContext';
import type { FormSchema } from '../types/form-schema';
import { SCHEMA_VERSION } from '../types/form-schema';

const createTestSchema = (): FormSchema => ({
    schemaVersion: SCHEMA_VERSION,
    metadata: { title: 'Test Form' },
    settings: { submitButtonText: 'Submit' },
    sections: [
        {
            id: 'section_1',
            title: 'Section 1',
            fields: [
                { id: 'field_1', key: 'name', type: 'text', label: 'Name' },
                { id: 'field_2', key: 'email', type: 'email', label: 'Email' },
            ],
        },
    ],
});

const wrapper = ({ children }: { children: ReactNode }) => (
    <BuilderProvider initialSchema={createTestSchema()}>{children}</BuilderProvider>
);

describe('BuilderContext', () => {
    describe('Field Operations', () => {
        it('should add a field to a section', () => {
            const { result } = renderHook(() => useBuilder(), { wrapper });

            act(() => {
                result.current.addField('section_1', 'text');
            });

            const section = result.current.state.schema.sections[0];
            expect(section.fields).toHaveLength(3);
            expect(section.fields[2].type).toBe('text');
        });

        it('should add a field at specific index', () => {
            const { result } = renderHook(() => useBuilder(), { wrapper });

            act(() => {
                result.current.addField('section_1', 'number', 1);
            });

            const section = result.current.state.schema.sections[0];
            expect(section.fields).toHaveLength(3);
            expect(section.fields[1].type).toBe('number');
        });

        it('should delete a field', () => {
            const { result } = renderHook(() => useBuilder(), { wrapper });

            act(() => {
                result.current.deleteField('field_1');
            });

            const section = result.current.state.schema.sections[0];
            expect(section.fields).toHaveLength(1);
            expect(section.fields[0].id).toBe('field_2');
        });

        it('should duplicate a field', () => {
            const { result } = renderHook(() => useBuilder(), { wrapper });

            act(() => {
                result.current.duplicateField('field_1');
            });

            const section = result.current.state.schema.sections[0];
            expect(section.fields).toHaveLength(3);
            expect(section.fields[1].key).toBe('name_copy');
            expect(section.fields[1].label).toBe('Name (Copy)');
        });

        it('should update a field', () => {
            const { result } = renderHook(() => useBuilder(), { wrapper });

            act(() => {
                result.current.updateField('field_1', { label: 'Full Name', required: true });
            });

            const field = result.current.state.schema.sections[0].fields[0];
            expect(field.label).toBe('Full Name');
            expect(field.required).toBe(true);
        });

        it('should reorder fields within a section', () => {
            const { result } = renderHook(() => useBuilder(), { wrapper });

            act(() => {
                result.current.reorderFields('section_1', 0, 1);
            });

            const section = result.current.state.schema.sections[0];
            expect(section.fields[0].id).toBe('field_2');
            expect(section.fields[1].id).toBe('field_1');
        });

        it('should move field to another section', () => {
            const { result } = renderHook(() => useBuilder(), { wrapper });

            // First add another section
            act(() => {
                result.current.addSection();
            });

            const newSectionId = result.current.state.schema.sections[1].id;

            act(() => {
                result.current.moveField('field_1', newSectionId, 0);
            });

            expect(result.current.state.schema.sections[0].fields).toHaveLength(1);
            expect(result.current.state.schema.sections[1].fields).toHaveLength(1);
            expect(result.current.state.schema.sections[1].fields[0].id).toBe('field_1');
        });
    });

    describe('Section Operations', () => {
        it('should add a section', () => {
            const { result } = renderHook(() => useBuilder(), { wrapper });

            act(() => {
                result.current.addSection();
            });

            expect(result.current.state.schema.sections).toHaveLength(2);
            expect(result.current.state.schema.sections[1].title).toBe('Section 2');
        });

        it('should delete a section', () => {
            const { result } = renderHook(() => useBuilder(), { wrapper });

            act(() => {
                result.current.deleteSection('section_1');
            });

            expect(result.current.state.schema.sections).toHaveLength(0);
        });

        it('should update a section', () => {
            const { result } = renderHook(() => useBuilder(), { wrapper });

            act(() => {
                result.current.updateSection('section_1', { title: 'Personal Info', description: 'Enter your details' });
            });

            const section = result.current.state.schema.sections[0];
            expect(section.title).toBe('Personal Info');
            expect(section.description).toBe('Enter your details');
        });

        it('should reorder sections', () => {
            const { result } = renderHook(() => useBuilder(), { wrapper });

            act(() => {
                result.current.addSection();
            });

            const section2Id = result.current.state.schema.sections[1].id;

            act(() => {
                result.current.reorderSections(0, 1);
            });

            expect(result.current.state.schema.sections[0].id).toBe(section2Id);
            expect(result.current.state.schema.sections[1].id).toBe('section_1');
        });
    });

    describe('Key Uniqueness', () => {
        it('should detect duplicate keys', () => {
            const { result } = renderHook(() => useBuilder(), { wrapper });

            expect(result.current.isKeyUnique('name')).toBe(false);
            expect(result.current.isKeyUnique('email')).toBe(false);
            expect(result.current.isKeyUnique('phone')).toBe(true);
        });

        it('should exclude current field when checking uniqueness', () => {
            const { result } = renderHook(() => useBuilder(), { wrapper });

            expect(result.current.isKeyUnique('name', 'field_1')).toBe(true);
            expect(result.current.isKeyUnique('name', 'field_2')).toBe(false);
        });

        it('should return all field keys', () => {
            const { result } = renderHook(() => useBuilder(), { wrapper });

            const keys = result.current.getAllFieldKeys();
            expect(keys).toContain('name');
            expect(keys).toContain('email');
            expect(keys).toHaveLength(2);
        });
    });

    describe('Schema Updates', () => {
        it('should mark schema as dirty after changes', () => {
            const { result } = renderHook(() => useBuilder(), { wrapper });

            expect(result.current.state.isDirty).toBe(false);

            act(() => {
                result.current.addField('section_1', 'text');
            });

            expect(result.current.state.isDirty).toBe(true);
        });

        it('should mark schema as saved', () => {
            const { result } = renderHook(() => useBuilder(), { wrapper });

            act(() => {
                result.current.addField('section_1', 'text');
            });

            expect(result.current.state.isDirty).toBe(true);

            act(() => {
                result.current.markSaved(result.current.state.schema);
            });

            expect(result.current.state.isDirty).toBe(false);
        });

        it('should update metadata', () => {
            const { result } = renderHook(() => useBuilder(), { wrapper });

            act(() => {
                result.current.updateMetadata({ title: 'New Title', description: 'New Description' });
            });

            expect(result.current.state.schema.metadata.title).toBe('New Title');
            expect(result.current.state.schema.metadata.description).toBe('New Description');
        });

        it('should update settings', () => {
            const { result } = renderHook(() => useBuilder(), { wrapper });

            act(() => {
                result.current.updateSettings({ submitButtonText: 'Send', showProgressBar: true });
            });

            expect(result.current.state.schema.settings?.submitButtonText).toBe('Send');
            expect(result.current.state.schema.settings?.showProgressBar).toBe(true);
        });
    });

    describe('Selection', () => {
        it('should select a field', () => {
            const { result } = renderHook(() => useBuilder(), { wrapper });

            act(() => {
                result.current.selectField('field_1');
            });

            expect(result.current.state.selectedFieldId).toBe('field_1');
            expect(result.current.getSelectedField()?.id).toBe('field_1');
        });

        it('should select a section', () => {
            const { result } = renderHook(() => useBuilder(), { wrapper });

            act(() => {
                result.current.selectSection('section_1');
            });

            expect(result.current.state.selectedSectionId).toBe('section_1');
            expect(result.current.getSelectedSection()?.id).toBe('section_1');
        });

        it('should clear field selection when selecting section', () => {
            const { result } = renderHook(() => useBuilder(), { wrapper });

            act(() => {
                result.current.selectField('field_1');
            });

            act(() => {
                result.current.selectSection('section_1');
            });

            expect(result.current.state.selectedFieldId).toBe(null);
            expect(result.current.state.selectedSectionId).toBe('section_1');
        });

        it('should clear selection when deleting selected field', () => {
            const { result } = renderHook(() => useBuilder(), { wrapper });

            act(() => {
                result.current.selectField('field_1');
            });

            act(() => {
                result.current.deleteField('field_1');
            });

            expect(result.current.state.selectedFieldId).toBe(null);
        });
    });

    describe('Preview Mode', () => {
        it('should toggle preview mode', () => {
            const { result } = renderHook(() => useBuilder(), { wrapper });

            expect(result.current.state.previewMode).toBe('desktop');

            act(() => {
                result.current.setPreviewMode('mobile');
            });

            expect(result.current.state.previewMode).toBe('mobile');
        });
    });

    describe('Options Fields', () => {
        it('should add field with default options for select type', () => {
            const { result } = renderHook(() => useBuilder(), { wrapper });

            act(() => {
                result.current.addField('section_1', 'select');
            });

            const field = result.current.state.schema.sections[0].fields[2];
            expect(field.type).toBe('select');
            expect((field as any).options).toBeDefined();
            expect((field as any).options).toHaveLength(1);
        });

        it('should add field with default options for radio type', () => {
            const { result } = renderHook(() => useBuilder(), { wrapper });

            act(() => {
                result.current.addField('section_1', 'radio');
            });

            const field = result.current.state.schema.sections[0].fields[2];
            expect(field.type).toBe('radio');
            expect((field as any).options).toBeDefined();
        });

        it('should add field with default options for checkbox_group type', () => {
            const { result } = renderHook(() => useBuilder(), { wrapper });

            act(() => {
                result.current.addField('section_1', 'checkbox_group');
            });

            const field = result.current.state.schema.sections[0].fields[2];
            expect(field.type).toBe('checkbox_group');
            expect((field as any).options).toBeDefined();
        });
    });
});
