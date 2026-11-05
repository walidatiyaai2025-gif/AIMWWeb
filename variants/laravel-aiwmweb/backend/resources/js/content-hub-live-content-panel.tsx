import React, { useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useLocation } from 'react-router-dom';
import { apiRequest, tenantUrl, type FrontendContext } from './core';
import { useLocale } from './i18n';
import { authoritativeContentHubSitesEndpoint, normalizeContentHubSiteRows } from './content-hub-site-links-control';

export const CONTENT_HUB_LIVE_DATA_OPERATION_ID = 'AIMW-CONT-LIVE-1458';

type ContentRow = { id: number; remote_id?: number | null; title?: string | null; type?: string | null };
type ContentPage = { data?: ContentRow[]; total?: number };
type SyncRun = { id: number; state: string };
type SyncDetails = { run?: { state?: string; created_count?: number; updated_count?: number; failed_count?: number } };

function safeSiteId(value: string | null): string | null {
    return value && /^[1-9]\d*$/.test(value) ? value : null;
}

export function ContentHubLiveContentPanel({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const location = useLocation();
    const queryClient = useQueryClient();
    const [chosenSite, setChosenSite] = useState<string | null>(null);
    const [running, setRunning] = useState(false);
    const [feedback, setFeedback] = useState('');
    const canView = context.permissions.includes('*') || context.permissions.includes('content.view');
    const canEdit = context.permissions.includes('*') || context.permissions.includes('content.edit');
    const canSeeSites = context.permissions.includes('*') || context.permissions.includes('sites.view');
    const sitesEndpoint = authoritativeContentHubSitesEndpoint(context);
    const sitesQuery = useQuery({
        queryKey: ['content-hub-live-sites', context.tenant.slug, sitesEndpoint],
        queryFn: () => apiRequest<unknown>(sitesEndpoint!),
        enabled: canView && canSeeSites && Boolean(sitesEndpoint),
        staleTime: 20000,
    });
    const sites = normalizeContentHubSiteRows(sitesQuery.data);
    const selectedQuerySite = safeSiteId(new URLSearchParams(location.search).get('site'));
    const site = chosenSite && sites.some((row) => row.id === chosenSite)
        ? chosenSite
        : selectedQuerySite && sites.some((row) => row.id === selectedQuerySite)
            ? selectedQuerySite
            : sites.length === 1 ? sites[0].id : null;
    const tenant = encodeURIComponent(context.tenant.slug);
    const base = site ? `/api/v1/tenants/${tenant}/sites/${site}` : '';
    const records = useQuery({
        queryKey: ['content-hub-live-records', context.tenant.slug, site],
        queryFn: async () => Promise.all([
            apiRequest<ContentPage>(`${base}/content/post?per_page=5`),
            apiRequest<ContentPage>(`${base}/content/page?per_page=5`),
        ]),
        enabled: canView && Boolean(site),
        staleTime: 10000,
    });

    if (!canView || !canSeeSites || !sitesEndpoint) return null;

    const isArabic = locale === 'ar';
    const posts = records.data?.[0];
    const pages = records.data?.[1];
    const totalPosts = typeof posts?.total === 'number' ? posts.total : null;
    const totalPages = typeof pages?.total === 'number' ? pages.total : null;
    const items: ContentRow[] = [...(posts?.data ?? []), ...(pages?.data ?? [])];

    const runFullSync = async () => {
        if (!site || !canEdit || running) return;
        setRunning(true);
        setFeedback('');
        try {
            const run = await apiRequest<SyncRun>(`${base}/sync`, {
                method: 'POST',
                body: JSON.stringify({ full: true, resources: ['posts', 'pages', 'media', 'categories', 'tags', 'comments'] }),
            });
            if (!Number.isSafeInteger(run.id) || run.id < 1) throw new Error('No authoritative sync run was returned.');
            let current = run.state;
            for (let attempt = 0; attempt < 60 && ['queued', 'running'].includes(current); attempt++) {
                await new Promise<void>((resolve) => window.setTimeout(resolve, 1000));
                const result = await apiRequest<SyncDetails>(`${base}/sync/runs/${run.id}`);
                current = result.run?.state ?? 'unknown';
            }
            await queryClient.invalidateQueries({ queryKey: ['content-hub-live-records', context.tenant.slug, site] });
            if (current === 'completed') {
                setFeedback(isArabic ? 'اكتملت مزامنة المحتوى. النتائج المعروضة قُرئت من قاعدة بيانات Laravel.' : 'Content sync completed. These records were reread from Laravel.');
            } else if (['failed', 'partial', 'cancelled'].includes(current)) {
                setFeedback(isArabic ? `المزامنة لم تكتمل (${current}). راجع سجل المزامنة لمعرفة الخطأ.` : `Sync did not complete (${current}). Inspect the sync run for details.`);
            } else {
                setFeedback(isArabic ? 'المزامنة ما زالت قيد التنفيذ. افتح صفحة المزامنة لمتابعتها.' : 'Sync is still pending; open the sync workspace to monitor progress.');
            }
        } catch (error) {
            setFeedback(error instanceof Error ? error.message : 'Content sync failed.');
        } finally {
            setRunning(false);
        }
    };

    return (
        <section className="panel" data-canonical-operation={CONTENT_HUB_LIVE_DATA_OPERATION_ID} aria-label={isArabic ? 'المحتوى الفعلي من WordPress' : 'Real WordPress content'}>
            <div className="panel-header">
                <div>
                    <span className="workspace-kicker">LIVE CONTENT READ MODEL</span>
                    <h3>{isArabic ? 'المحتوى الذي وصل من WordPress' : 'WordPress content received'}</h3>
                    <p>{isArabic ? 'أرقام ومواد حقيقية محفوظة في Laravel، وليست حالة صلاحيات الموصل.' : 'Real records saved in Laravel, independent of Connector capability badges.'}</p>
                </div>
            </div>
            {sitesQuery.isLoading ? <p>{isArabic ? 'جارٍ تحميل المواقع…' : 'Loading sites…'}</p> : null}
            {sitesQuery.error ? <p role="alert">{sitesQuery.error instanceof Error ? sitesQuery.error.message : 'Site lookup failed.'}</p> : null}
            {sites.length > 1 ? (
                <label>{isArabic ? 'الموقع' : 'Site'}{' '}
                    <select aria-label={isArabic ? 'اختر الموقع' : 'Select site'} value={site ?? ''} onChange={(event) => { setChosenSite(event.target.value || null); setFeedback(''); }}>
                        <option value="">{isArabic ? 'اختر موقعًا' : 'Select a site'}</option>
                        {sites.map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}
                    </select>
                </label>
            ) : null}
            {!sitesQuery.isLoading && !site ? <p>{isArabic ? 'اختر موقعًا متصلًا لعرض بياناته أو اربط موقعًا أولًا.' : 'Choose or connect a site to load its content.'}</p> : null}
            {site && records.isLoading ? <p>{isArabic ? 'جارٍ قراءة المقالات والصفحات الفعلية…' : 'Reading actual posts and pages…'}</p> : null}
            {site && records.error ? <p role="alert">{records.error instanceof Error ? records.error.message : 'Could not read content.'}</p> : null}
            {site && records.data ? (
                <>
                    <p><strong>{isArabic ? 'المقالات' : 'Posts'}: {totalPosts ?? '—'}</strong> {' · '} <strong>{isArabic ? 'الصفحات' : 'Pages'}: {totalPages ?? '—'}</strong></p>
                    {items.length ? (
                        <ul>{items.map((item) => (
                            <li key={`${item.type ?? 'content'}-${item.id}`}>{item.title || (isArabic ? 'بدون عنوان' : 'Untitled')}</li>
                        ))}</ul>
                    ) : <p role="status">{isArabic ? 'لا توجد مقالات أو صفحات متزامنة في Laravel لهذا الموقع بعد.' : 'No synchronized posts or pages exist in Laravel for this site yet.'}</p>}
                </>
            ) : null}
            {site ? (
                <div className="toolbar-actions">
                    {canEdit ? <button type="button" className="btn primary" disabled={running} onClick={() => void runFullSync()}>{running ? (isArabic ? 'جارٍ جلب المحتوى…' : 'Synchronizing…') : (isArabic ? 'جلب محتوى WordPress الآن' : 'Sync WordPress content now')}</button> : null}
                    <Link className="btn" to={tenantUrl(context.tenant.slug, `/module/sync?site=${site}`)}>{isArabic ? 'تفاصيل المزامنة' : 'Sync details'}</Link>
                    <Link className="btn" to={tenantUrl(context.tenant.slug, `/module/posts?site=${site}`)}>{isArabic ? 'عرض جميع المقالات' : 'All posts'}</Link>
                </div>
            ) : null}
            {feedback ? <p role="status">{feedback}</p> : null}
        </section>
    );
}
