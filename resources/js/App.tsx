import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { useState, useEffect } from 'react';
import { AuthProvider, useAuth } from './contexts/AuthContext';
import { LoginPage } from './pages/LoginPage';
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

function AppContent() {
    const { isAuthenticated, isLoading, logout } = useAuth();
    const [view, setView] = useState<View>({ type: 'dashboard' });

    useEffect(() => {
        const parseRoute = () => {
            const path = window.location.pathname;

            const publicMatch = path.match(/^\/forms\/([a-z0-9-]+)$/);
            if (publicMatch && !path.includes('/edit') && !path.includes('/submissions')) {
                setView({ type: 'public-form', slug: publicMatch[1] });
                return;
            }

            const editMatch = path.match(/^\/forms\/(\d+)\/edit$/);
            if (editMatch) {
                setView({ type: 'editor', formId: parseInt(editMatch[1]) });
                return;
            }

            const submissionsMatch = path.match(/^\/forms\/(\d+)\/submissions$/);
            if (submissionsMatch) {
                setView({ type: 'submissions', formId: parseInt(submissionsMatch[1]) });
                return;
            }

            setView({ type: 'dashboard' });
        };

        parseRoute();
        window.addEventListener('popstate', parseRoute);
        return () => window.removeEventListener('popstate', parseRoute);
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

    if (isLoading) {
        return (
            <div className="min-h-screen bg-gray-50 flex items-center justify-center">
                <LoadingSpinner />
            </div>
        );
    }

    // Public form - no auth required
    if (view.type === 'public-form') {
        return <PublicFormPage slug={view.slug} />;
    }

    // Auth required for other views
    if (!isAuthenticated) {
        return <LoginPage />;
    }

    return (
        <>
            {view.type === 'dashboard' && (
                <FormsDashboard
                    onEditForm={(formId) => navigateTo({ type: 'editor', formId })}
                    onViewSubmissions={(formId) => navigateTo({ type: 'submissions', formId })}
                    onLogout={logout}
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
        </>
    );
}

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

export default function App() {
    return (
        <QueryClientProvider client={queryClient}>
            <AuthProvider>
                <AppContent />
            </AuthProvider>
        </QueryClientProvider>
    );
}
