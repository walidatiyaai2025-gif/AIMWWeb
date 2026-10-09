import React from 'react';
import { useQuery } from '@tanstack/react-query';
import { apiRequest, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const GLOBAL_SYNCHRONIZATION_REVIEW_CONFLICTS_OPERATION_ID = 'AIMW-BILL-5887A977D7';

type ActiveSite = { id: number; name: string };
type ContextWithActiveSite = FrontendContext & { active_site?: ActiveSite | null };

type ConflictVersion = {
    title: string;
    slug: string;
    status: string;
    link: string;
    rendered_content: string;
    rendered_excerpt: string;
    modified_at?: string | null;
};

type Conflict = {
    content_type: 'post' | 'page';
    wordpress_id: number;
    kind: 'RemoteUpdated' | 'RemoteDeleted';
    local: ConflictVersion;
    remote: ConflictVersion | null;
};

type ConflictReview = {
    operation_id: string;
    has_baseline: boolean;
    local_synchronized_at?: string | null;
    remote_additions: number;
    remote_updates: number;
    remote_deletions: number;
    has_conflicts: boolean;
    conflicts: Conflict[];
};

export function globalSynchronizationConflictReviewEndpoint(tenantSlug: string, siteId: number | string): string | null {
    const tenant = tenantSlug.trim();
    const site = String(siteId).trim();
    if (!tenant || /[\\/]/.test(tenant)) return null;
    if (!/^[1-9]\d*$/.test(site)) return null;

    return `/api/v1/tenants/${encodeURIComponent(tenant)}/sites/${encodeURIComponent(site)}/sync/review-conflicts`;
}

export function GlobalSynchronizationReviewConflictsControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const activeSite = (context as ContextWithActiveSite).active_site;
    const endpoint = globalSynchronizationConflictReviewEndpoint(context.tenant.slug, activeSite?.id ?? 0);
    const canView = context.permissions.includes('*') || context.permissions.includes('content.view');

    const query = useQuery({
        queryKey: ['global-sync-conflict-review', context.tenant.slug, activeSite?.id],
        queryFn: () => apiRequest<ConflictReview>(endpoint as string),
        enabled: false,
        retry: false,
    });

    if (!activeSite || !endpoint || !canView) return null;

    const review = query.data;

    return (
        <section
            className="panel data-panel"
            data-canonical-operation={GLOBAL_SYNCHRONIZATION_REVIEW_CONFLICTS_OPERATION_ID}
            aria-label={locale === 'ar' ? 'مراجعة تغييرات WordPress' : 'Review WordPress changes'}
        >
            <header className="panel-header">
                <div>
                    <span className="workspace-kicker">SYNC & CONFLICTS</span>
                    <h2>{locale === 'ar' ? 'مراجعة التغييرات البعيدة' : 'Review remote changes'}</h2>
                    <p>
                        {locale === 'ar'
                            ? `الموقع: ${activeSite.name}. تتم المقارنة مباشرة مع WordPress بدون تغيير الكاش المحلي.`
                            : `Site: ${activeSite.name}. Compares the local mirror with live WordPress without mutating local data.`}
                    </p>
                </div>
                <button type="button" className="btn" data-review-conflicts onClick={() => query.refetch()} disabled={query.isFetching}>
                    {query.isFetching
                        ? (locale === 'ar' ? 'جارٍ المقارنة…' : 'Comparing…')
                        : (locale === 'ar' ? 'مراجعة التغييرات' : 'Review remote changes')}
                </button>
            </header>

            {query.error ? (
                <div className="alert error">
                    {query.error instanceof Error ? query.error.message : (locale === 'ar' ? 'تعذر تنفيذ المراجعة.' : 'Conflict review failed.')}
                </div>
            ) : null}

            {review ? (
                review.operation_id !== GLOBAL_SYNCHRONIZATION_REVIEW_CONFLICTS_OPERATION_ID ? (
                    <div className="alert error">{locale === 'ar' ? 'هوية عملية المراجعة غير متوقعة.' : 'Unexpected conflict review operation identity.'}</div>
                ) : (
                    <div className="workspace-stack" data-conflict-count={review.conflicts.length}>
                        <div className="panel">
                            <p>
                                <strong>{review.remote_updates}</strong> {locale === 'ar' ? 'تحديثات بعيدة' : 'remote updates'} ·{' '}
                                <strong>{review.remote_deletions}</strong> {locale === 'ar' ? 'محذوفة بعيدًا' : 'remote deletions'} ·{' '}
                                <strong>{review.remote_additions}</strong> {locale === 'ar' ? 'عناصر جديدة' : 'remote additions'}
                            </p>
                        </div>

                        {!review.has_baseline ? (
                            <div className="panel">
                                <strong>{locale === 'ar' ? 'لا توجد نسخة محلية سابقة.' : 'No local baseline yet.'}</strong>
                            </div>
                        ) : review.conflicts.length === 0 ? (
                            <div className="panel">
                                <strong>{locale === 'ar' ? 'لا توجد تعارضات محتوى.' : 'No content conflicts detected.'}</strong>
                                <p>
                                    {review.remote_additions > 0
                                        ? (locale === 'ar' ? 'توجد عناصر جديدة فقط ويمكن جلبها بالمزامنة.' : 'Only new remote items were found; synchronization can import them.')
                                        : (locale === 'ar' ? 'النسخة المحلية مطابقة للنسخة الحية.' : 'The local mirror matches live WordPress.')}
                                </p>
                            </div>
                        ) : (
                            review.conflicts.map((conflict) => (
                                <article className="panel" key={`${conflict.content_type}:${conflict.wordpress_id}:${conflict.kind}`}>
                                    <header className="panel-header">
                                        <div>
                                            <span className="workspace-kicker">{conflict.content_type} #{conflict.wordpress_id}</span>
                                            <h3>{conflict.remote?.title || conflict.local.title || (locale === 'ar' ? 'عنصر متعارض' : 'Conflicting item')}</h3>
                                        </div>
                                        <span className="badge warning">
                                            {conflict.kind === 'RemoteDeleted'
                                                ? (locale === 'ar' ? 'محذوف على WordPress' : 'Deleted in WordPress')
                                                : (locale === 'ar' ? 'معدّل على WordPress' : 'Updated in WordPress')}
                                        </span>
                                    </header>
                                    <p>
                                        {conflict.kind === 'RemoteDeleted'
                                            ? (locale === 'ar'
                                                ? 'العنصر لم يعد موجودًا على WordPress. المراجعة لا تغيّر الكاش.'
                                                : 'This item no longer exists in WordPress. Review does not mutate the cache.')
                                            : (locale === 'ar'
                                                ? 'تختلف النسخة الحية عن النسخة المحلية. المراجعة للقراءة فقط.'
                                                : 'The live WordPress version differs from the local mirror. Review is read-only.')}
                                    </p>
                                </article>
                            ))
                        )}
                    </div>
                )
            ) : null}
        </section>
    );
}
