import React from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider, useQuery } from '@tanstack/react-query';
import { BrowserRouter, Outlet, Route, Routes, useOutletContext, useParams } from 'react-router-dom';
import { ApiError, apiRequest, workspaceRoutes, type FrontendContext, type WorkspaceRoute } from './core';
import { AppShell, LoadingState, StatePanel, ToastProvider } from './components';
import { LocaleProvider, useLocale } from './i18n';
import { NotFoundPage, SiteDetailsRoute, WorkspacePage } from './pages';
import { SiteDetailsBackControl } from './site-details-back-control';
import { SiteDetailsSiteUrlControl } from './site-details-site-url-control';

const queryClient = new QueryClient({
    defaultOptions: {
        queries: {
            staleTime: 20_000,
            retry: (count, error) => error instanceof ApiError ? error.status >= 500 && count < 2 : count < 1,
            refetchOnWindowFocus: false,
        },
        mutations: { retry: false },
    },
});

class ErrorBoundary extends React.Component<{ children: React.ReactNode }, { error: Error | null }> {
    state: { error: Error | null } = { error: null };
    static getDerivedStateFromError(error: Error) { return { error }; }
    componentDidCatch(error: Error, info: React.ErrorInfo) {
        console.error('Laravel AIWMWeb frontend error', error, info.componentStack);
    }
    render() {
        if (this.state.error) return (
            <div className="fatal-error" role="alert">
                <section className="panel"><span className="workspace-kicker">RUNTIME ERROR</span><h1>A runtime error interrupted this screen</h1><p>{this.state.error.message}</p><button type="button" className="btn primary" onClick={() => window.location.reload()}>Hard reload</button></section>
            </div>
        );
        return this.props.children;
    }
}

type OutletState = { context: FrontendContext };

function ContextFailure({ error, retry }: { error: unknown; retry: () => void }) {
    const { locale } = useLocale();
    const apiError = error instanceof ApiError ? error : null;
    const isAuth = apiError?.status === 401;
    const isForbidden = apiError?.status === 403;
    return (
        <div className="bootstrap-state">
            <StatePanel tone={isForbidden ? 'danger' : 'warning'} title={isAuth ? (locale === 'ar' ? 'يلزم تسجيل الدخول' : 'Sign-in required') : isForbidden ? (locale === 'ar' ? 'الوصول مرفوض' : 'Access denied') : (locale === 'ar' ? 'تعذر تحميل سياق الحساب' : 'Tenant context unavailable')}>
                <p>{apiError?.message ?? (locale === 'ar' ? 'لم يكتمل طلب سياق الواجهة.' : 'The frontend context request did not complete.')}</p>
                {isAuth ? <p>{locale === 'ar' ? 'لم يتم اختراع تدفق دخول محلي؛ يلزم دمج مصادقة Laravel من قائد التكامل.' : 'No local fake sign-in is provided; the Laravel authentication flow must be integrated by the backend authority.'}</p> : null}
                <button type="button" className="btn" onClick={retry}>{locale === 'ar' ? 'إعادة المحاولة' : 'Retry'}</button>
            </StatePanel>
        </div>
    );
}

function TenantBootstrap() {
    const { tenantSlug } = useParams();
    const query = useQuery({
        queryKey: ['frontend-context', tenantSlug],
        queryFn: () => apiRequest<FrontendContext>(`/tenants/${encodeURIComponent(tenantSlug ?? '')}/context`),
        enabled: Boolean(tenantSlug),
    });

    if (query.isLoading) return <div className="bootstrap-state"><LoadingState /></div>;
    if (query.error) return <ContextFailure error={query.error} retry={() => query.refetch()} />;
    if (!query.data) return <ContextFailure error={new Error('Tenant context returned no data.')} retry={() => query.refetch()} />;

    return <ToastProvider><AppShell context={query.data}><Outlet context={{ context: query.data } satisfies OutletState} /></AppShell></ToastProvider>;
}

function RouteElement({ route }: { route: WorkspaceRoute }) {
    const { context } = useOutletContext<OutletState>();
    if (route.key === 'site-details') return (
        <>
            <SiteDetailsBackControl context={context} />
            <SiteDetailsSiteUrlControl context={context} />
            <SiteDetailsRoute context={context} route={route} />
        </>
    );
    return <WorkspacePage context={context} route={route} />;
}

function NotFoundElement() {
    const { context } = useOutletContext<OutletState>();
    return <NotFoundPage context={context} />;
}

function OutsideTenantRoute() {
    const { locale } = useLocale();
    return (
        <div className="bootstrap-state">
            <StatePanel tone="warning" title={locale === 'ar' ? 'اختر مساحة حساب' : 'Tenant workspace required'}>
                {locale === 'ar' ? 'واجهة Laravel متعددة الحسابات تتطلب مسارًا من شكل /tenants/{tenant}.' : 'The multi-tenant Laravel frontend requires a /tenants/{tenant} workspace URL.'}
            </StatePanel>
        </div>
    );
}

function AppRoutes() {
    return (
        <Routes>
            <Route path="/tenants/:tenantSlug" element={<TenantBootstrap />}>
                {workspaceRoutes.map((route) => {
                    const relative = route.path === '/' ? undefined : route.path.replace(/^\//, '');
                    return route.path === '/'
                        ? <Route key={route.key} index element={<RouteElement route={route} />} />
                        : <Route key={route.key} path={relative} element={<RouteElement route={route} />} />;
                })}
                <Route path="*" element={<NotFoundElement />} />
            </Route>
            <Route path="*" element={<OutsideTenantRoute />} />
        </Routes>
    );
}

export function App() {
    return (
        <ErrorBoundary>
            <QueryClientProvider client={queryClient}>
                <LocaleProvider>
                    <BrowserRouter><AppRoutes /></BrowserRouter>
                </LocaleProvider>
            </QueryClientProvider>
        </ErrorBoundary>
    );
}

const root = document.getElementById('app');
if (root) createRoot(root).render(<React.StrictMode><App /></React.StrictMode>);
