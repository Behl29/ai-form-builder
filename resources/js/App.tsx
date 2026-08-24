import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { FormsDashboard } from './pages/FormsDashboard';
import { FormEditorPage } from './pages/FormEditorPage';
import { PublicFormPage } from './pages/PublicFormPage';
import { SubmissionsDashboard } from './pages/SubmissionsDashboard';
import { useForm } from './hooks/useForms';
import { LoadingSpinner } from './components/ui';

const queryClient = new QueryClient({
    defaultOptions: {
        queries: {
            staleTime: 1000 * 60,
            retry: 1,
        },
    },
});

type View =
    | { type: 'dashboard' }
    | { type: 'editor'; formId: number }
    | { type: 'submissions'; formId: number }
    | { type: 'public-form'; slug: string };

export default function App() {
    const [view, setView] = useState<View>({ type: 'dashboard' });

    // Parse initial route
    useEffect(() => {
        const parseRoute = () => {
            const path = window.location.pathname;

            // Public form: /forms/{slug}
            const publicMatch = path.match(/^\/forms\/([a-z0-9-]+)$/);
            if (publicMatch && !path.includes('/edit') && !path.includes('/submissions')) {
                setView({ type: 'public-form', slug: publicMatch[1] });
                return;
            }

            // Editor: /forms/{id}/edit
            const editMatch = path.match(/^\/forms\/(\d+)\/edit$/);
            if (editMatch) {
                setView({ type: 'editor', formId: parseInt(editMatch[1]) });
                return;
            }

            // Submissions: /forms/{id}/submissions
            const submissionsMatch = path.match(/^\/forms\/(\d+)\/submissions$/);
            if (submissionsMatch) {
                setView({ type: 'submissions', formId: parseInt(submissionsMatch[1]) });
                return;
            }

            // Default: dashboard
            setView({ type: 'dashboard' });
        };

        parseRoute();

        const handlePopState = () => parseRoute();
        window.addEventListener('popstate', handlePopState);
        return () => window.removeEventListener('popstate', handlePopState);
    }, []);

    const navigateTo = (newView: View) => {
        let path = '/';
        switch (newView.type) {
            case 'editor':
                path = `/forms/${newView.formId}/edit`;
                break;
            case 'submissions':
                path = `/forms/${newView.formId}/submissions`;
                break;
            case 'public-form':
                path = `/forms/${newView.slug}`;
                break;
        }
        window.history.pushState({}, '', path);
        setView(newView);
    };

    return (
        <QueryClientProvider client={queryClient}>
            {view.type === 'dashboard' && (
                <FormsDashboard
                    onEditForm={(formId) => navigateTo({ type: 'editor', formId })}
                    onViewSubmissions={(formId) => navigateTo({ type: 'submissions', formId })}
                />
            )}
            {view.type === 'editor' && (
                <FormEditorPage
                    formId={view.formId}
                    onBack={() => navigateTo({ type: 'dashboard' })}
                />
            )}
            {view.type === 'submissions' && (
                <SubmissionsWrapper
                    formId={view.formId}
                    onBack={() => navigateTo({ type: 'dashboard' })}
                />
            )}
            {view.type === 'public-form' && (
                <PublicFormPage slug={view.slug} />
            )}
        </QueryClientProvider>
    );
}

// Wrapper to load form data for submissions
function SubmissionsWrapper({ formId, onBack }: { formId: number; onBack: () => void }) {
    const { data: form, isLoading } = useForm(formId);

    if (isLoading || !form) {
        return (
            <div className="min-h-screen bg-gray-50 flex items-center justify-center">
                <LoadingSpinner />
            </div>
        );
    }

    return <SubmissionsDashboard form={form} onBack={onBack} />;
}
