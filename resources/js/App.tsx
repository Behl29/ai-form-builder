import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { useState } from 'react';
import { FormsDashboard } from './pages/FormsDashboard';
import { FormEditorPage } from './pages/FormEditorPage';

const queryClient = new QueryClient({
    defaultOptions: {
        queries: {
            staleTime: 1000 * 60, // 1 minute
            retry: 1,
        },
    },
});

type View = { type: 'dashboard' } | { type: 'editor'; formId: number };

export default function App() {
    const [view, setView] = useState<View>({ type: 'dashboard' });

    // Simple client-side routing based on URL
    useState(() => {
        const path = window.location.pathname;
        const match = path.match(/^\/forms\/(\d+)\/edit$/);
        if (match) {
            setView({ type: 'editor', formId: parseInt(match[1]) });
        }
    });

    const navigateToDashboard = () => {
        window.history.pushState({}, '', '/');
        setView({ type: 'dashboard' });
    };

    const navigateToEditor = (formId: number) => {
        window.history.pushState({}, '', `/forms/${formId}/edit`);
        setView({ type: 'editor', formId });
    };

    // Handle browser back/forward
    useState(() => {
        const handlePopState = () => {
            const path = window.location.pathname;
            const match = path.match(/^\/forms\/(\d+)\/edit$/);
            if (match) {
                setView({ type: 'editor', formId: parseInt(match[1]) });
            } else {
                setView({ type: 'dashboard' });
            }
        };

        window.addEventListener('popstate', handlePopState);
        return () => window.removeEventListener('popstate', handlePopState);
    });

    return (
        <QueryClientProvider client={queryClient}>
            {view.type === 'dashboard' ? (
                <FormsDashboard onEditForm={navigateToEditor} />
            ) : (
                <FormEditorPage formId={view.formId} onBack={navigateToDashboard} />
            )}
        </QueryClientProvider>
    );
}
