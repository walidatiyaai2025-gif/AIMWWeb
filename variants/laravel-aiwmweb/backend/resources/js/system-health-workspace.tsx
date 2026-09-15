import React from 'react';
import { useQuery } from '@tanstack/react-query';
import { ApiError, apiRequest, resolveCapability, type FrontendContext, type WorkspaceRoute } from './core';
import { LoadingState, StatePanel } from './components';
import { useLocale } from './i18n';
import { SystemHealthOpenLogsControl } from './system-health-open-logs-control';

type SystemHealthSnapshot = {
    tenant: string;
    status: string;
    checks: Record<string, { status: string }>;
};

export function SystemHealthWorkspace({ context, route }: { context: FrontendContext; route: WorkspaceRoute }) {
    const { locale } = useLocale();
    const activeTenantIsAuthoritative = Boolean(
        context.tenant.slug
        && context.tenants.some((tenant) => tenant.slug === context.tenant.slug)
        && Number.isSafeInteger(context.user.id)
        && context.user.id > 0,
    );
    const capability = resolveCapability(context, { ...route, apiKey: undefined });
    const endpoint = `/api/tenants/${encodeURIComponent(context.tenant.slug)}/system-health`;
    const query = useQuery({
        queryKey: ['system-health-snapshot', context.tenant.slug],
        queryFn: () => apiRequest<SystemHealthSnapshot>(endpoint),
        enabled: activeTenantIsAuthoritative && capability.state === 'enabled',
    });

    if (!activeTenantIsAuthoritative || capability.state !== 'enabled') {
        return (
            <StatePanel tone="warning" title={locale === 'ar' ? 'صحة النظام غير متاحة' : 'System health unavailable'}>
                {locale === 'ar' ? 'لا يسمح سياق الحساب الحالي بقراءة تشخيصات النظام.' : 'The current tenant context does not allow System Health diagnostics.'}
            </StatePanel>
        );
    }

    if (query.isLoading) return <LoadingState />;

    if (query.error) {
        const error = query.error instanceof ApiError ? query.error : null;
        return (
            <StatePanel tone="danger" title={locale === 'ar' ? 'تعذر تحميل صحة النظام' : 'System health could not be loaded'}>
                {error?.message ?? (locale === 'ar' ? 'فشل طلب التشخيصات الحقيقي.' : 'The real diagnostics request failed.')}
            </StatePanel>
        );
    }

    const snapshot = query.data;
    if (!snapshot || snapshot.tenant !== context.tenant.slug) {
        return (
            <StatePanel tone="danger" title={locale === 'ar' ? 'بيانات صحة النظام غير صالحة' : 'Invalid System Health snapshot'}>
                {locale === 'ar' ? 'لم يرجع الخادم لقطة موثوقة للحساب الحالي.' : 'The server did not return an authoritative snapshot for the active tenant.'}
            </StatePanel>
        );
    }

    const checks = Object.entries(snapshot.checks ?? {});

    return (
        <div className="workspace-stack system-health-workspace">
            <section className="hero-panel">
                <div>
                    <span className="workspace-kicker">SYSTEM HEALTH</span>
                    <h2>{locale === 'ar' ? 'صحة النظام' : 'System Health'}</h2>
                    <p>{locale === 'ar' ? 'لقطة حقيقية لحالة خدمات تشغيل Laravel.' : 'A real snapshot of the Laravel runtime services.'}</p>
                </div>
                <span className="tenant-badge">{snapshot.status}</span>
            </section>

            <section className="workspace-card-grid" aria-label={locale === 'ar' ? 'فحوصات صحة النظام' : 'System health checks'}>
                {checks.map(([name, check]) => (
                    <article className="workspace-card" key={name}>
                        <div>
                            <strong>{name}</strong>
                            <p>{check.status}</p>
                        </div>
                    </article>
                ))}
            </section>

            <section className="workspace-card">
                <strong>{locale === 'ar' ? 'إجراءات' : 'Actions'}</strong>
                <p>{locale === 'ar' ? 'افتح السجلات فقط بعد نجاح تحميل لقطة صحة النظام.' : 'Open logs only after the System Health snapshot has loaded successfully.'}</p>
                <SystemHealthOpenLogsControl context={context} snapshotReady={true} />
            </section>
        </div>
    );
}
