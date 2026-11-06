import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { apiRequest, tenantUrl, type FrontendContext } from './core';
import { useLocale } from './i18n';

type SiteRow = { id: number; name: string; url?: string; status?: string };
type TransferState = 'queued' | 'running' | 'completed' | 'failed';
type TransferRow = {
    id: number;
    site_id: number;
    kind: 'import' | 'export';
    state: TransferState;
    progress: number;
    result?: Record<string, unknown> | null;
    failure_present?: boolean;
};

function normalizeSites(payload: unknown): SiteRow[] {
    const rows = Array.isArray(payload)
        ? payload
        : payload && typeof payload === 'object' && Array.isArray((payload as { data?: unknown }).data)
            ? (payload as { data: unknown[] }).data
            : [];

    return rows.flatMap((candidate) => {
        if (!candidate || typeof candidate !== 'object') return [];
        const row = candidate as Record<string, unknown>;
        const id = Number(row.id);
        if (!Number.isInteger(id) || id < 1) return [];
        return [{
            id,
            name: typeof row.name === 'string' && row.name.trim() ? row.name.trim() : `Site #${id}`,
            url: typeof row.url === 'string' ? row.url : undefined,
            status: typeof row.status === 'string' ? row.status : undefined,
        }];
    });
}

export function ImportExportWorkspace({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const [sites, setSites] = useState<SiteRow[]>([]);
    const [siteId, setSiteId] = useState('');
    const [loadState, setLoadState] = useState<'loading' | 'ready' | 'error'>('loading');
    const [file, setFile] = useState<File | null>(null);
    const [transfer, setTransfer] = useState<TransferRow | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const fileInput = useRef<HTMLInputElement>(null);

    const tenant = context.tenant.slug;
    const expectedSitesEndpoint = `/api/tenants/${encodeURIComponent(tenant)}/sites`;
    const sitesTrusted = context.api.sites === expectedSitesEndpoint
        && (context.permissions.includes('tenant.view') || context.permissions.includes('*'));
    const canExport = context.permissions.includes('content.view') || context.permissions.includes('*');
    const canImport = context.permissions.includes('content.edit') || context.permissions.includes('*');
    const selectedSite = useMemo(() => sites.find((site) => String(site.id) === siteId) ?? null, [sites, siteId]);

    const loadSites = useCallback(async (): Promise<void> => {
        if (!sitesTrusted) {
            setSites([]);
            setSiteId('');
            setLoadState('error');
            setError(locale === 'ar' ? 'تعذر التحقق من صلاحية قراءة مواقع هذا الحساب.' : 'The active tenant site-read authority could not be verified.');
            return;
        }

        setLoadState('loading');
        setError(null);
        try {
            const rows = normalizeSites(await apiRequest<unknown>(expectedSitesEndpoint));
            setSites(rows);
            setSiteId((current) => rows.some((site) => String(site.id) === current) ? current : (rows[0] ? String(rows[0].id) : ''));
            setLoadState('ready');
        } catch {
            setSites([]);
            setSiteId('');
            setLoadState('error');
            setError(locale === 'ar' ? 'تعذر تحميل المواقع الفعلية. تحقق من الاتصال والصلاحيات ثم أعد المحاولة.' : 'Real sites could not be loaded. Check connectivity and permissions, then retry.');
        }
    }, [expectedSitesEndpoint, locale, sitesTrusted]);

    useEffect(() => {
        void loadSites();
    }, [loadSites]);

    const transferBase = selectedSite
        ? `/api/v1/tenants/${encodeURIComponent(tenant)}/sites/${selectedSite.id}/transfers`
        : null;
    const transferStatusUrl = transferBase && transfer ? `${transferBase}/${transfer.id}` : null;
    const downloadUrl = transferBase && transfer?.kind === 'export' && transfer.state === 'completed'
        ? `${transferBase}/${transfer.id}/download`
        : null;

    useEffect(() => {
        if (!transferStatusUrl || !transfer || !['queued', 'running'].includes(transfer.state)) return undefined;
        let active = true;
        let timer: number | undefined;
        const poll = async (): Promise<void> => {
            try {
                const next = await apiRequest<TransferRow>(transferStatusUrl);
                if (!active) return;
                setTransfer(next);
                if (next.state === 'queued' || next.state === 'running') {
                    timer = window.setTimeout(() => { void poll(); }, 2500);
                }
            } catch {
                if (active) setError(locale === 'ar' ? 'تعذر تحديث حالة مهمة النقل. أعد تحميل الحالة قبل تكرار العملية.' : 'Transfer status could not be refreshed. Reload its status before retrying.');
            }
        };
        timer = window.setTimeout(() => { void poll(); }, 1200);
        return () => {
            active = false;
            if (timer !== undefined) window.clearTimeout(timer);
        };
    }, [locale, transfer?.id, transfer?.state, transferStatusUrl]);

    const startExport = async (): Promise<void> => {
        if (!transferBase || !selectedSite || !canExport || busy) return;
        setBusy(true);
        setError(null);
        setTransfer(null);
        try {
            const created = await apiRequest<TransferRow>(`${transferBase}/export`, { method: 'POST' });
            setTransfer(created);
        } catch (failure) {
            setError(failure instanceof Error ? failure.message : (locale === 'ar' ? 'تعذر بدء التصدير.' : 'Export could not be started.'));
        } finally {
            setBusy(false);
        }
    };

    const startImport = async (): Promise<void> => {
        if (!transferBase || !selectedSite || !canImport || !file || busy) return;
        setBusy(true);
        setError(null);
        setTransfer(null);
        try {
            if (file.size > 100 * 1024 * 1024) throw new Error(locale === 'ar' ? 'الحد الأقصى للملف 100 ميجابايت.' : 'The maximum file size is 100 MB.');
            const parsed = JSON.parse(await file.text()) as { version?: unknown; content?: unknown };
            if (parsed.version !== 1 || !Array.isArray(parsed.content)) {
                throw new Error(locale === 'ar' ? 'صيغة الاستيراد غير مدعومة. استخدم ملف JSON من إصدار التصدير 1.' : 'Unsupported import format. Use a version 1 JSON export file.');
            }
            const body = new FormData();
            body.append('file', file);
            const created = await apiRequest<TransferRow>(`${transferBase}/import`, { method: 'POST', body });
            setTransfer(created);
            setFile(null);
            if (fileInput.current) fileInput.current.value = '';
        } catch (failure) {
            setError(failure instanceof Error ? failure.message : (locale === 'ar' ? 'تعذر بدء الاستيراد.' : 'Import could not be started.'));
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="workspace-stack" data-testid="import-export-workspace">
            <section className="panel">
                <header className="logs-toolbar">
                    <div>
                        <span className="workspace-kicker">CONTENT TRANSFERS</span>
                        <h1>{locale === 'ar' ? 'استيراد وتصدير المحتوى' : 'Content Import / Export'}</h1>
                        <p>{locale === 'ar'
                            ? 'تنفيذ مهام نقل فعلية للموقع المحدد. يتم التحقق من الصلاحيات على الخادم قبل إنشاء المهمة.'
                            : 'Run real transfer jobs for the selected site. The server rechecks authorization before accepting each job.'}</p>
                    </div>
                    <button type="button" className="btn" disabled={loadState === 'loading' || busy} onClick={() => void loadSites()}>
                        ↻ {locale === 'ar' ? 'تحديث المواقع' : 'Refresh sites'}
                    </button>
                </header>
                {loadState === 'loading' ? <p aria-busy="true">{locale === 'ar' ? 'جارٍ تحميل المواقع...' : 'Loading sites...'}</p> : null}
                {loadState === 'error' ? (
                    <div className="empty-state" role="alert">
                        <p>{error}</p>
                        <button type="button" className="btn primary" onClick={() => void loadSites()}>{locale === 'ar' ? 'إعادة المحاولة' : 'Retry'}</button>
                    </div>
                ) : null}
                {loadState === 'ready' && sites.length === 0 ? (
                    <div className="empty-state">
                        <h2>{locale === 'ar' ? 'لا توجد مواقع' : 'No sites available'}</h2>
                        <p>{locale === 'ar' ? 'أضف موقع WordPress قبل إنشاء مهمة نقل.' : 'Add a WordPress site before creating a transfer.'}</p>
                        <a className="btn primary" href={tenantUrl(tenant, '/sites')}>{locale === 'ar' ? 'إدارة المواقع' : 'Manage sites'}</a>
                    </div>
                ) : null}
                {loadState === 'ready' && sites.length > 0 ? (
                    <label className="form-field">
                        <span>{locale === 'ar' ? 'الموقع' : 'Site'}</span>
                        <select aria-label={locale === 'ar' ? 'الموقع' : 'Site'} value={siteId} disabled={busy} onChange={(event) => {
                            setSiteId(event.target.value);
                            setTransfer(null);
                            setError(null);
                        }}>
                            {sites.map((site) => <option key={site.id} value={site.id}>{site.name} — {site.url ?? site.status ?? ''}</option>)}
                        </select>
                    </label>
                ) : null}
            </section>

            {selectedSite ? (
                <section className="workspace-grid">
                    <article className="panel workspace-stack">
                        <h2>{locale === 'ar' ? 'تصدير الموقع' : 'Export site content'}</h2>
                        <p>{locale === 'ar' ? 'ينشئ ملف JSON من البيانات المحلية المحفوظة للمقالات والصفحات والوسائط والتصنيفات.' : 'Creates a JSON file from locally persisted posts, pages, media and taxonomy for this site.'}</p>
                        <button type="button" className="btn primary" disabled={!canExport || busy} onClick={() => void startExport()}>
                            {busy ? (locale === 'ar' ? 'جارٍ الإرسال...' : 'Submitting...') : (locale === 'ar' ? 'بدء التصدير' : 'Start export')}
                        </button>
                    </article>
                    <article className="panel workspace-stack">
                        <h2>{locale === 'ar' ? 'استيراد المقالات والصفحات' : 'Import posts and pages'}</h2>
                        <p>{locale === 'ar' ? 'ارفع ملف JSON بصيغة الإصدار 1. الاستيراد ينشئ مقالات وصفحات فقط؛ لا يستورد ملفات الوسائط أو التصنيفات حاليًا.' : 'Upload a version 1 JSON export. Import currently creates posts and pages only; media files and taxonomy are not imported.'}</p>
                        <input
                            ref={fileInput}
                            type="file"
                            accept=".json,application/json,.txt,text/plain"
                            aria-label={locale === 'ar' ? 'ملف الاستيراد JSON' : 'JSON import file'}
                            disabled={!canImport || busy}
                            onChange={(event) => setFile(event.target.files?.[0] ?? null)}
                        />
                        <button type="button" className="btn primary" disabled={!canImport || !file || busy} onClick={() => void startImport()}>
                            {locale === 'ar' ? 'بدء الاستيراد' : 'Start import'}
                        </button>
                        {!canImport ? <p role="note">{locale === 'ar' ? 'تتطلب عملية الاستيراد صلاحية content.edit.' : 'Import requires the content.edit permission.'}</p> : null}
                    </article>
                </section>
            ) : null}

            {error && loadState !== 'error' ? <p className="form-error" role="alert">{error}</p> : null}
            {transfer ? (
                <section className="panel workspace-stack" aria-live="polite" data-testid="content-transfer-status">
                    <h2>{transfer.kind === 'export' ? (locale === 'ar' ? 'حالة التصدير' : 'Export status') : (locale === 'ar' ? 'حالة الاستيراد' : 'Import status')}</h2>
                    <p>{locale === 'ar' ? 'المهمة' : 'Transfer'} #{transfer.id} — {transfer.state}</p>
                    <progress max="100" value={Math.max(0, Math.min(100, transfer.progress))} />
                    <p>{transfer.progress}%</p>
                    {transfer.state === 'failed' ? <p role="alert">{locale === 'ar' ? 'فشلت مهمة النقل. راجع سجل التشغيل قبل إعادة المحاولة.' : 'The transfer failed. Review operational logs before retrying.'}</p> : null}
                    {transfer.state === 'completed' && transfer.kind === 'export' && downloadUrl ? (
                        <a className="btn primary" href={downloadUrl} download>{locale === 'ar' ? 'تنزيل ملف التصدير' : 'Download export JSON'}</a>
                    ) : null}
                    {transfer.state === 'completed' && transfer.kind === 'import' ? (
                        <p role="status">{locale === 'ar' ? `اكتمل الاستيراد. عدد العناصر: ${Number(transfer.result?.imported ?? 0)}` : `Import completed. Items created: ${Number(transfer.result?.imported ?? 0)}`}</p>
                    ) : null}
                    {transfer.state === 'completed' && transfer.kind === 'export' ? (
                        <p role="status">{locale === 'ar' ? 'اكتمل التصدير وأصبح الملف جاهزًا للتنزيل.' : 'Export completed and the file is ready to download.'}</p>
                    ) : null}
                </section>
            ) : null}
        </div>
    );
}
