import React, { useCallback, useEffect, useState } from 'react';
import { apiRequest, tenantUrl, type FrontendContext, type WorkspaceRoute } from './core';
import { useLocale } from './i18n';

export const MODULE_WORKSPACE_LOAD_SITES_OPERATION_ID = 'AIMW-BILL-39472863C8';

type SiteRow = {
    id: number;
    name: string;
    url?: string;
    status?: string;
};

type LoadState = 'loading' | 'ready' | 'error';

function normalizeSites(payload: unknown): SiteRow[] {
    const raw = Array.isArray(payload)
        ? payload
        : payload && typeof payload === 'object' && Array.isArray((payload as { data?: unknown }).data)
            ? (payload as { data: unknown[] }).data
            : [];

    return raw.flatMap((candidate) => {
        if (!candidate || typeof candidate !== 'object') return [];
        const row = candidate as Record<string, unknown>;
        const id = Number(row.id);
        if (!Number.isInteger(id) || id < 1) return [];
        const name = typeof row.name === 'string' && row.name.trim() ? row.name.trim() : `Site #${id}`;
        return [{
            id,
            name,
            url: typeof row.url === 'string' ? row.url : undefined,
            status: typeof row.status === 'string' ? row.status : undefined,
        }];
    });
}

export function ModuleWorkspaceLoadSitesControl({
    context,
    route,
}: {
    context: FrontendContext;
    route: WorkspaceRoute;
}) {
    const { locale } = useLocale();
    const [sites, setSites] = useState<SiteRow[]>([]);
    const [state, setState] = useState<LoadState>('loading');
    const [error, setError] = useState<string | null>(null);

    const endpoint = context.api.sites;
    const expectedEndpoint = `/api/tenants/${context.tenant.slug}/sites`;
    const isSeoGateway = route.key === 'seo-audit' || route.key === 'seo-suggestions';
    const canRead = (context.permissions.includes('tenant.view') || context.permissions.includes('*'))
        && (context.permissions.includes('seo.view') || context.permissions.includes('*'));
    const trusted = isSeoGateway && canRead && endpoint === expectedEndpoint;

    const loadSites = useCallback(async (): Promise<void> => {
        if (!trusted) {
            setSites([]);
            setError(locale === 'ar' ? 'مسار المواقع أو الصلاحيات غير متاحة.' : 'The tenant site authority is unavailable.');
            setState('error');
            return;
        }

        setState('loading');
        setError(null);
        try {
            const payload = await apiRequest<unknown>(expectedEndpoint);
            setSites(normalizeSites(payload));
            setState('ready');
        } catch {
            setSites([]);
            setError(
                locale === 'ar'
                    ? 'حدث خطأ أثناء تحميل المواقع المملوكة لهذا الحساب. حاول مرة أخرى أو راجع الاتصال والصلاحيات.'
                    : 'An error occurred while loading sites owned by this account. Retry or review connectivity and permissions.',
            );
            setState('error');
        }
    }, [expectedEndpoint, locale, trusted]);

    useEffect(() => {
        void loadSites();
    }, [loadSites]);

    if (!isSeoGateway) return null;

    return (
        <div className="workspace-stack" data-canonical-operation={MODULE_WORKSPACE_LOAD_SITES_OPERATION_ID}>
            <section className="panel">
                <header className="logs-toolbar">
                    <div>
                        <span className="workspace-kicker">SEO</span>
                        <h1>{route.key === 'seo-audit'
                            ? (locale === 'ar' ? 'تدقيق SEO' : 'SEO Audit')
                            : (locale === 'ar' ? 'اقتراحات SEO' : 'SEO Suggestions')}</h1>
                        <p>{locale === 'ar'
                            ? 'اختر موقعًا للوصول إلى بيانات SEO الحقيقية الخاصة به.'
                            : 'Choose a site to access its real SEO workspace and persisted data.'}</p>
                    </div>
                    <button
                        type="button"
                        className="btn"
                        disabled={state === 'loading' || !trusted}
                        onClick={() => void loadSites()}
                    >
                        ↻ {locale === 'ar' ? 'تحديث المواقع' : 'Refresh sites'}
                    </button>
                </header>
            </section>

            {state === 'loading' ? (
                <section className="panel" aria-busy="true" data-testid="seo-site-picker-loading">
                    {locale === 'ar' ? 'جارٍ تحميل مواقعك الحقيقية...' : 'Loading your real sites...'}
                </section>
            ) : null}

            {state === 'error' ? (
                <section className="panel empty-state" role="alert" data-testid="seo-site-picker-error">
                    <h2>{locale === 'ar' ? 'تعذر تحميل المواقع' : 'Unable to load sites'}</h2>
                    <p>{error}</p>
                    <button type="button" className="btn primary" disabled={!trusted} onClick={() => void loadSites()}>
                        {locale === 'ar' ? 'إعادة المحاولة' : 'Retry'}
                    </button>
                </section>
            ) : null}

            {state === 'ready' ? (
                <section className="panel" data-testid="seo-site-picker">
                    {sites.length === 0 ? (
                        <div className="empty-state" data-testid="seo-no-sites">
                            <h3>{locale === 'ar' ? 'لا توجد مواقع متاحة' : 'No sites available'}</h3>
                            <p>{locale === 'ar'
                                ? 'أضف موقع WordPress أو أكمل ربطه قبل تشغيل تدقيق SEO.'
                                : 'Add or connect a WordPress site before running an SEO audit.'}</p>
                            <a className="btn primary" href={tenantUrl(context.tenant.slug, '/sites')}>
                                {locale === 'ar' ? 'إدارة المواقع' : 'Manage sites'}
                            </a>
                        </div>
                    ) : (
                        <div className="workspace-grid">
                            {sites.map((site) => (
                                <article className="panel" key={site.id} data-testid="seo-site-card">
                                    <small>WordPress</small>
                                    <h3>{site.name}</h3>
                                    <div className="toolbar">
                                        <a
                                            className="btn primary"
                                            data-testid="seo-site-link"
                                            href={tenantUrl(context.tenant.slug, `/sites/${site.id}/seo`)}
                                        >
                                            {locale === 'ar' ? 'فتح تدقيق SEO' : 'Open SEO audit'}
                                        </a>
                                        <a className="btn" href={tenantUrl(context.tenant.slug, '/explorer')}>
                                            {locale === 'ar' ? 'مستكشف المحتوى' : 'Content explorer'}
                                        </a>
                                    </div>
                                </article>
                            ))}
                        </div>
                    )}
                </section>
            ) : null}
        </div>
    );
}
