import React, { useEffect, useRef, useState } from 'react';
import { apiRequest, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const WP_SMOKE_RESOURCES = ['posts', 'pages', 'categories', 'tags', 'comments', 'media'] as const;
export type WpSmokeResource = typeof WP_SMOKE_RESOURCES[number];
export type WpSmokeState = 'passed' | 'failed' | 'needs_reconciliation' | 'not_tested';
export type WpSmokeEntry = {
    schema: 'aiwm.wordpress-write-smoke.v1';
    transport: 'native_wordpress_rest';
    resource: WpSmokeResource;
    state: WpSmokeState;
    create_verified: boolean;
    save_verified: boolean;
    delete_verified: boolean;
    cleanup_required: boolean;
    reason: string;
    recovery?: { marker: string; known_objects: Array<{ resource: string; remote_id: number }> } | null;
};
export type WpSmokeRun = {
    site_id: number;
    completed: boolean;
    results: WpSmokeEntry[];
};

const unknownResult = (resource: WpSmokeResource): WpSmokeEntry => ({
    schema: 'aiwm.wordpress-write-smoke.v1',
    transport: 'native_wordpress_rest',
    resource,
    state: 'not_tested',
    create_verified: false,
    save_verified: false,
    delete_verified: false,
    cleanup_required: false,
    reason: 'request_not_confirmed',
});

export function WordPressWriteAuditPanel({ context, siteIds, onComplete }: {
    context: FrontendContext;
    siteIds: number[];
    onComplete: (result: WpSmokeRun | null) => void;
}) {
    const { locale } = useLocale();
    const ar = locale === 'ar';
    const [chosen, setChosen] = useState(0);
    // Consent authorizes exactly one run against one tenant-owned site.
    const [consentedSiteId, setConsentedSiteId] = useState<number | null>(null);
    const [working, setWorking] = useState(false);
    const [results, setResults] = useState<WpSmokeEntry[]>([]);
    const [progress, setProgress] = useState('');
    const ids = [...new Set(siteIds.filter(id => Number.isSafeInteger(id) && id > 0))].slice(0, 50);
    const siteId = ids.includes(chosen) ? chosen : ids.length === 1 ? ids[0] : 0;
    const consent = siteId > 0 && consentedSiteId === siteId;
    // The parent may replace the authorized site list without firing <select> onChange.
    // In-flight operations must not silently proceed to the next remote write.
    const activeSiteId = useRef(siteId);
    activeSiteId.current = siteId;
    const previousSiteId = useRef(siteId);
    useEffect(() => {
        if (previousSiteId.current === siteId) return;
        previousSiteId.current = siteId;
        setConsentedSiteId(null);
        setResults([]);
        setProgress('');
        onComplete(null);
    }, [siteId, onComplete]);
    const permitted = ['diagnostics.view', 'content.edit', 'content.manage'].every(permission =>
        context.permissions.includes('*') || context.permissions.includes(permission));
    const validTenant = /^[a-zA-Z0-9_-]{1,100}$/.test(context.tenant.slug);

    const start = async () => {
        if (working || !permitted || !siteId || !validTenant || !consent) return;
        const approvedSiteId = siteId;
        setWorking(true);
        setResults([]);
        onComplete(null);
        const next: WpSmokeEntry[] = [];
        try {
            for (const resource of WP_SMOKE_RESOURCES) {
                if (activeSiteId.current !== approvedSiteId) break;
                setProgress((ar ? 'اختبار ' : 'Testing ') + resource);
                let entry: WpSmokeEntry;
                try {
                    const response = await apiRequest<WpSmokeEntry>(
                        '/tenants/' + encodeURIComponent(context.tenant.slug) + '/runtime-inspector/wordpress-write-check',
                        { method: 'POST', body: JSON.stringify({
                            site_id: approvedSiteId,
                            resource,
                            confirmation: 'RUN_DISPOSABLE_WORDPRESS_TEST',
                        }) },
                    );
                    entry = response.schema === 'aiwm.wordpress-write-smoke.v1' &&
                        response.resource === resource && response.transport === 'native_wordpress_rest' &&
                        ['passed', 'failed', 'needs_reconciliation', 'not_tested'].includes(response.state)
                        ? response : unknownResult(resource);
                } catch {
                    entry = unknownResult(resource);
                }
                // A site switch invalidates the receipt for this screen; never
                // attribute old-site results to a newly selected WordPress site.
                if (activeSiteId.current !== approvedSiteId) break;
                if (entry.cleanup_required) {
                    entry = { ...entry, state: 'needs_reconciliation', reason: 'cleanup_not_verified' };
                } else if (entry.state === 'passed' &&
                    !(entry.create_verified && entry.save_verified && entry.delete_verified)) {
                    entry = { ...entry, state: 'not_tested', reason: 'request_not_confirmed' };
                }
                next.push(entry);
                setResults([...next]);
                onComplete({ site_id: approvedSiteId, completed: false, results: [...next] });
                // A failed cleanup or ambiguous create must be reconciled by the owner
                // before issuing any more remote WordPress write probes.
                if (entry.cleanup_required || entry.state === 'needs_reconciliation' || entry.reason === 'request_not_confirmed') break;
            }
        } finally {
            setWorking(false);
            setProgress('');
            setConsentedSiteId(null);
            if (activeSiteId.current === approvedSiteId) {
                const verified = next.length === WP_SMOKE_RESOURCES.length &&
                    next.every(entry => entry.state === 'passed' && entry.create_verified &&
                        entry.save_verified && entry.delete_verified && !entry.cleanup_required);
                onComplete({ site_id: approvedSiteId, completed: verified, results: [...next] });
            }
        }
    };

    return <section className="panel workspace-stack" data-testid="wordpress-write-audit">
        <h3>{ar ? 'فحص حفظ وتعديل وحذف WordPress الفعلي' : 'Live WordPress save/update/delete verification'}</h3>
        <p>{ar
            ? 'فحص منفصل وخطِر نسبيًا: ينشئ عناصر مؤقتة في موقع WordPress المحدد، يتحقق من حفظها وتعديلها، ثم يحذفها ويتحقق من اختفائها. لا يغيّر المقالات أو الصور الموجودة مسبقًا.'
            : 'Separate opt-in mutation test. Creates temporary items on the selected WordPress site, reads and updates them, then permanently deletes and verifies absence. Existing posts and uploads are never chosen as targets.'}</p>
        <p>{ar
            ? 'هذا يختبر WordPress REST باستخدام Application Password المخزنة على الخادم، وليس كل أزرار واجهة Laravel أو عمليات الكتابة الموقعة عبر البلجن. لا يعمل الفحص تلقائيًا ولا ضمن فحص القراءة.'
            : 'This tests native WordPress REST with a server-held Application Password, not every Laravel UI button or signed Connector mutation. It never runs as part of the read-only scan.'}</p>
        {!permitted ? <p role="alert">{ar ? 'تحتاج صلاحيات diagnostics.view وcontent.edit وcontent.manage.' : 'Requires diagnostics.view, content.edit and content.manage.'}</p> : null}
        <label>
            {ar ? 'اختر الموقع المراد اختباره' : 'Select the WordPress site to test'}
            <select aria-label={ar ? 'موقع فحص الكتابة' : 'Write-test site'} value={siteId || ''} disabled={working}
                onChange={event => {
                    setChosen(Number(event.target.value) || 0);
                    setConsentedSiteId(null);
                    setResults([]);
                    onComplete(null);
                }}>
                <option value="">{ar ? 'اختر الموقع' : 'Choose a site'}</option>
                {ids.map(id => <option key={id} value={id}>{ar ? 'موقع اختبار الكتابة' : 'Write probe site'} #{id}</option>)}
            </select>
        </label>
        <label>
            <input type="checkbox" checked={consent} disabled={working || !permitted || !siteId}
                onChange={event => setConsentedSiteId(event.target.checked ? siteId : null)} />
            {' '}{ar ? 'أوافق على إنشاء وتعديل وحذف عناصر تجريبية مؤقتة في هذا الموقع، وأفهم أن فشل الحذف قد يتطلب تنظيفًا يدويًا.' :
                'I approve creating, updating and deleting disposable items on this site and understand failed cleanup may require manual recovery.'}
        </label>
        <div className="toolbar-actions">
            <button className="btn primary" type="button" disabled={!consent || !siteId || !permitted || !validTenant || working}
                onClick={() => void start()}>
                {working ? (ar ? 'جارٍ تنفيذ الفحوصات...' : 'Testing...') : (ar ? 'اختبر كل عمليات WordPress التجريبية' : 'Run all disposable WordPress tests')}
            </button>
        </div>
        {progress ? <p role="status">{progress} — {results.length}/{WP_SMOKE_RESOURCES.length}</p> : null}
        <div className="data-table-scroll">
            <table>
                <thead><tr><th>{ar ? 'المورد' : 'Resource'}</th><th>{ar ? 'النتيجة' : 'Result'}</th>
                    <th>{ar ? 'تحقق الحفظ' : 'Saved'}</th><th>{ar ? 'تحقق الحذف' : 'Deleted'}</th>
                    <th>{ar ? 'السبب' : 'Reason'}</th></tr></thead>
                <tbody>{WP_SMOKE_RESOURCES.map(resource => {
                    const entry = results.find(item => item.resource === resource);
                    return <tr key={resource}><td>{resource}</td>
                        <td>{entry?.state ?? 'not_tested'}</td>
                        <td>{entry?.save_verified === true ? '✓' : '—'}</td>
                        <td>{entry?.delete_verified === true ? '✓' : '—'}</td>
                        <td><code>{entry?.reason ?? 'not_tested'}</code>
                            {entry?.cleanup_required && <p role="alert">
                                {ar ? 'تنظيف يدوي مطلوب؛ ابحث عن العلامة:' : 'Manual cleanup may be needed; search for marker:'}
                                {' '}<code>{entry.recovery?.marker ?? 'unknown'}</code>
                                {entry.recovery?.known_objects.map(object =>
                                    <span key={object.resource + object.remote_id}> {object.resource} #{object.remote_id}</span>)}
                            </p>}</td>
                    </tr>;
                })}</tbody>
            </table>
        </div>
        <p>{ar
            ? 'النجاح يتطلب إثبات القراءة والحفظ والحذف النهائي. يُوقف الفحص فور وجود نتيجة غير مؤكدة أو بقايا تحتاج إلى مراجعة. التقرير المصدّر لا يحتوي على معرفات العناصر أو علامة الاختبار.'
            : 'Pass requires verified reads, save and permanent deletion. The run stops on ambiguous results or uncleaned canaries. Export excludes remote IDs and recovery markers.'}</p>
    </section>;
}
