import React, { useCallback, useEffect, useState } from 'react';
import { apiRequest, tenantUrl, type FrontendContext, type WorkspaceRoute } from './core';
import { useLocale } from './i18n';

export const MODULE_WORKSPACE_LOAD_SITES_OPERATION_ID = 'AIMW-BILL-39472863C8';
export const MODULE_WORKSPACE_MANAGE_SITES_OPERATION_ID = 'AIMW-CONT-56873F3DD6';
export const MODULE_WORKSPACE_DYNAMIC_SITE_LINKS_OPERATION_ID = 'AIMW-CONT-E4D7C73D4E';

type SiteRow = {
    id: number;
    name: string;
    url?: string;
    status?: string;
};

type LoadState = 'loading' | 'ready' | 'error';

function normalizeSites(payload: unknown): SiteRow[] {
    // The canonical site API returns a JSON array. Also accept a genuine
    // paginated { data: [...] } envelope, but never interpret an unexpected
    // 200 response (such as { message: 'error' }) as "no sites".
    let raw: unknown[];
    if (Array.isArray(payload)) {
        raw = payload;
    } else if (payload && typeof payload === 'object' && Array.isArray((payload as { data?: unknown }).data)) {
        raw = (payload as { data: unknown[] }).data;
    } else {
        throw new Error('Invalid site collection response');
    }

    return raw.map((candidate) => {
        if (!candidate || typeof candidate !== 'object' || Array.isArray(candidate)) {
            throw new Error('Invalid site record');
        }
        const row = candidate as Record<string, unknown>;
        const id = typeof row.id === 'number'
            ? row.id
            : typeof row.id === 'string' && /^[1-9][0-9]*$/.test(row.id)
                ? Number(row.id)
                : NaN;
        if (!Number.isSafeInteger(id) || id < 1 || typeof row.name !== 'string' || !row.name.trim()) {
            throw new Error('Invalid site record');
        }

        return {
            id,
            name: row.name.trim(),
            url: typeof row.url === 'string' ? row.url : undefined,
            status: typeof row.status === 'string' ? row.status : undefined,
        };
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
    const isSeoGateway = route.key === 'seo-audit' || route.key === 'seo-findings' || route.key === 'seo-suggestions';
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
                            : route.key === 'seo-findings'
                                ? (locale === 'ar' ? 'نتائج SEO' : 'SEO Findings')
                                : (locale === 'ar' ? 'اقتراحات SEO' : 'SEO Suggestions')}</h1>
                        <p>{locale === 'ar'
                            ? (route.key === 'seo-findings' ? 'اختر موقعًا لمراجعة نتائج تدقيق SEO المحفوظة.' : 'اختر موقعًا للوصول إلى بيانات SEO الحقيقية الخاصة به.')
                            : (route.key === 'seo-findings' ? 'Choose a site to review its persisted SEO audit findings.' : 'Choose a site to access its real SEO workspace and persisted data.')}</p>
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
                            <a
                                className="btn primary"
                                href={tenantUrl(context.tenant.slug, '/sites')}
                                data-testid="seo-manage-sites-link"
                                data-canonical-operation={MODULE_WORKSPACE_MANAGE_SITES_OPERATION_ID}
                            >
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
                                            data-canonical-operation={MODULE_WORKSPACE_DYNAMIC_SITE_LINKS_OPERATION_ID}
                                            href={tenantUrl(context.tenant.slug, `/sites/${site.id}/seo`)}
                                        >
                                            {route.key === 'seo-findings'
                                                ? (locale === 'ar' ? 'فتح نتائج SEO' : 'Open SEO findings')
                                                : (locale === 'ar' ? 'فتح تدقيق SEO' : 'Open SEO audit')}
                                        </a>
                                        <a
                                            className="btn"
                                            data-testid="seo-site-explorer-link"
                                            data-canonical-operation={MODULE_WORKSPACE_DYNAMIC_SITE_LINKS_OPERATION_ID}
                                            href={tenantUrl(context.tenant.slug, `/explorer?site=${site.id}`)}
                                        >
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
