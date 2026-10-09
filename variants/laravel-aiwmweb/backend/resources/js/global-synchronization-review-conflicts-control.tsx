import React from 'react';
import { useQuery } from '@tanstack/react-query';
import { apiRequest, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const GLOBAL_SYNCHRONIZATION_REVIEW_CONFLICTS_OPERATION_ID = 'AIMW-BILL-5887A977D7';

type ActiveSite = {
    id: number;
    name: string;
};

type ContextWithActiveSite = FrontendContext & {
    active_site?: ActiveSite | null;
};

type Conflict = {
    id: number;
    site_id: number;
    resource: string;
    remote_id: number;
    status: string;
    resolution?: string | null;
    detected_at?: string | null;
    local_snapshot?: Record<string, unknown> | null;
    remote_snapshot?: Record<string, unknown> | null;
};

type ConflictPage = {
    data: Conflict[];
    current_page: number;
    total: number;
};

export function globalSynchronizationConflictReviewEndpoint(tenantSlug: string, siteId: number | string): string | null {
    const tenant = tenantSlug.trim();
    const site = String(siteId).trim();
    if (!tenant || /[\\/]/.test(tenant)) return null;
    if (!/^[1-9]\d*$/.test(site)) return null;

    return `/api/v1/tenants/${encodeURIComponent(tenant)}/sites/${encodeURIComponent(site)}/conflicts`;
}

function snapshotTitle(snapshot: Record<string, unknown> | null | undefined): string {
    const title = snapshot?.title;
    if (typeof title === 'string') return title;
    if (title && typeof title === 'object') {
        const value = title as Record<string, unknown>;
        if (typeof value.rendered === 'string') return value.rendered;
        if (typeof value.raw === 'string') return value.raw;
    }
    return '';
}

export function GlobalSynchronizationReviewConflictsControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const activeSite = (context as ContextWithActiveSite).active_site;
    const endpoint = globalSynchronizationConflictReviewEndpoint(context.tenant.slug, activeSite?.id ?? 0);
    const canView = context.permissions.includes('*') || context.permissions.includes('content.view');

    const query = useQuery({
        queryKey: ['global-sync-conflict-review', context.tenant.slug, activeSite?.id],
        queryFn: () => apiRequest<ConflictPage>(endpoint as string),
        enabled: false,
        retry: false,
    });

    if (!activeSite || !endpoint || !canView) return null;

    const open = (query.data?.data ?? []).filter((conflict) => conflict.status === 'open');

    return (
        <section
            className="panel data-panel"
            data-canonical-operation={GLOBAL_SYNCHRONIZATION_REVIEW_CONFLICTS_OPERATION_ID}
            aria-label={locale === 'ar' ? 'مراجعة تعارضات المزامنة' : 'Review synchronization conflicts'}
        >
            <header className="panel-header">
                <div>
                    <span className="workspace-kicker">SYNC & CONFLICTS</span>
                    <h2>{locale === 'ar' ? 'مراجعة التغييرات والتعارضات' : 'Review remote changes & conflicts'}</h2>
                    <p>
                        {locale === 'ar'
                            ? `الموقع: ${activeSite.name}. تعرض هذه المراجعة أدلة التعارض التي اكتشفها محرك المزامنة بدون تغيير المحتوى المحلي أو WordPress.`
                            : `Site: ${activeSite.name}. This review reads conflict evidence detected by the sync engine without changing local content or WordPress.`}
                    </p>
                </div>
                <button
                    type="button"
                    className="btn"
                    data-review-conflicts
                    onClick={() => query.refetch()}
                    disabled={query.isFetching}
                >
                    {query.isFetching
                        ? (locale === 'ar' ? 'جارٍ المراجعة…' : 'Reviewing…')
                        : (locale === 'ar' ? 'مراجعة التغييرات' : 'Review remote changes')}
                </button>
            </header>

            {query.error ? (
                <div className="alert error">
                    {query.error instanceof Error ? query.error.message : (locale === 'ar' ? 'تعذر تحميل التعارضات.' : 'Conflict review could not be loaded.')}
                </div>
            ) : null}

            {query.data ? (
                open.length === 0 ? (
                    <div className="panel">
                        <strong>{locale === 'ar' ? 'لا توجد تعارضات مفتوحة.' : 'No open synchronization conflicts.'}</strong>
                        <p>
                            {locale === 'ar'
                                ? 'آخر مراجعة لحالة التعارضات لم تجد عناصر مفتوحة تحتاج قرارًا.'
                                : 'The latest conflict-state review found no open items requiring a decision.'}
                        </p>
                    </div>
                ) : (
                    <div className="workspace-stack" data-conflict-count={open.length}>
                        <p>
                            <strong>{open.length}</strong>{' '}
                            {locale === 'ar' ? 'تعارض مفتوح يحتاج مراجعة.' : 'open conflict(s) require review.'}
                        </p>
                        {open.map((conflict) => (
                            <article className="panel" key={conflict.id} data-conflict-id={conflict.id}>
                                <header className="panel-header">
                                    <div>
                                        <span className="workspace-kicker">{conflict.resource} #{conflict.remote_id}</span>
                                        <h3>{snapshotTitle(conflict.remote_snapshot) || snapshotTitle(conflict.local_snapshot) || (locale === 'ar' ? 'عنصر متعارض' : 'Conflicting item')}</h3>
                                    </div>
                                    <span className="badge warning">{locale === 'ar' ? 'مفتوح' : 'Open'}</span>
                                </header>
                                <p>
                                    {locale === 'ar'
                                        ? 'تم اكتشاف اختلاف بين الحالة المحلية وحالة WordPress أثناء المزامنة. لم يتم تنفيذ أي قرار من شاشة المراجعة.'
                                        : 'The sync engine detected a local/WordPress difference. No resolution is executed by this review control.'}
                                </p>
                            </article>
                        ))}
                    </div>
                )
            ) : null}
        </section>
    );
}
