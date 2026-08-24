import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { FormsDashboard } from './pages/FormsDashboard';

const queryClient = new QueryClient({
    defaultOptions: {
        queries: {
            staleTime: 1000 * 60, // 1 minute
            retry: 1,
        },
    },
});

export default function App() {
    return (
        <QueryClientProvider client={queryClient}>
            <FormsDashboard />
        </QueryClientProvider>
    );
}
