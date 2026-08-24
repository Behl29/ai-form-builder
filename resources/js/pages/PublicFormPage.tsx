import { useEffect, useState } from 'react';
import { PublicFormRenderer } from '../components/PublicFormRenderer';
import { ErrorState, LoadingSpinner } from '../components/ui';
import api from '../lib/api';
import type { FormSchema } from '../types/form-schema';

interface PublicFormData {
    id: number;
    title: string;
    description: string | null;
    slug: string;
    success_message: string;
    schema: FormSchema;
}

interface PublicFormPageProps {
    slug: string;
}

export function PublicFormPage({ slug }: PublicFormPageProps) {
    const [form, setForm] = useState<PublicFormData | null>(null);
    const [isLoading, setIsLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        const fetchForm = async () => {
            try {
                const { data } = await api.get(`/public/forms/${slug}`);
                setForm(data.data);
            } catch (err: any) {
                if (err.response?.status === 404) {
                    setError('Form not found or not published.');
                } else {
                    setError('Failed to load form. Please try again.');
                }
            } finally {
                setIsLoading(false);
            }
        };

        fetchForm();
    }, [slug]);

    if (isLoading) {
        return (
            <div className="min-h-screen bg-gray-50 flex items-center justify-center">
                <LoadingSpinner />
            </div>
        );
    }

    if (error || !form) {
        return (
            <div className="min-h-screen bg-gray-50 flex items-center justify-center">
                <ErrorState message={error || 'Form not found'} />
            </div>
        );
    }

    return (
        <div className="min-h-screen bg-gray-50 py-8">
            <PublicFormRenderer
                schema={form.schema}
                slug={form.slug}
                successMessage={form.success_message}
            />
        </div>
    );
}
