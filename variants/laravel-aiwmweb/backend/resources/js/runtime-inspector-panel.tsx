import React, { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { apiRequest, tenantUrl, type FrontendContext } from './core';
import { useLocale } from './i18n';
import { WorkspaceCapabilityCensusPanel } from './workspace-capability-census-panel';
import { DashboardConnectorDownloadControl } from './dashboard-connector-download-control';
import { RuntimeWorkspaceAuditPanel, type LiveAuditResult } from './runtime-workspace-audit';
import { workspaceCapabilityCensus } from './workspace-capability-census';
import {
    clearRuntimeInspectorEvents,
    diagnosticRouteShape,
    runtimeInspectorEvents,
    type RuntimeObservation,
} from './runtime-inspector-capture';

export const RUNTIME_INSPECTOR_OPERATION_ID = 'AIMW-RUNTIME-1464';

export type InspectorSite = {
    site_id: number;
    connection_state: string;
    health_state: string;
    connector: {
        present: boolean;
        revoked: boolean;
        verified: boolean;
        protocol: string;
        enabled_scopes: string[];
        installed_version?: string;
    };
    last_diagnostic: string;
    remote_probe?: { attempted: boolean; state: string };
    last_sync: { present: boolean; state: string; finished: boolean };
    record_counts: { posts: number; pages: number; media: number; legacy_synced_contents: number };
    finding: string;
};

export type InspectorServer = {
    schema: string;
    generated_at: string;
    safety: { redacted: boolean; read_only: boolean };
    release: { version: string; sha: string };
    runtime: {
        php_version: string;
        laravel_version: string;
        database: string;
        database_driver: string;
        queue_driver: string;
        cache_driver: string;
        extensions: Record<string, boolean>;
    };
    wordpress_plugin?: {
        name: string;
        bundle_version: string;
        download_path: string;
        download_available: boolean;
        install_target: string;
    };
    site_count: number;
    sites_truncated: boolean;
    sites: InspectorSite[];
};

const findings: Record<string, { en: string; ar: string }> = {
    CONNECTOR_NOT_PAIRED: { en: 'Connector is not paired', ar: 'البلجن غير مقترن' },
    CONNECTOR_REVOKED: { en: 'Connector pairing revoked', ar: 'اقتران البلجن ملغى' },
    CONNECTOR_NOT_VERIFIED: { en: 'Connector pairing has not been verified', ar: 'لم يتم التحقق من اتصال البلجن بعد' },
    CONTENT_READ_UNSUPPORTED: { en: 'Connector does not advertise content.read', ar: 'البلجن لا يعلن دعم صلاحية content.read' },
    CONTENT_READ_SCOPE_DISABLED: { en: 'content.read permission missing', ar: 'صلاحية content.read غير مفعلة' },
    SITE_CONNECTION_NOT_READY: { en: 'Site connection not ready', ar: 'اتصال الموقع غير جاهز' },
    CANONICAL_SYNC_NOT_STARTED: { en: 'Canonical content sync not started', ar: 'مزامنة المحتوى لم تبدأ' },
    LAST_SYNC_NOT_COMPLETED: { en: 'Last sync failed or incomplete', ar: 'آخر مزامنة فشلت أو لم تكتمل' },
    SYNC_RUNNING_OR_QUEUED: { en: 'Sync is running or queued', ar: 'المزامنة قيد التشغيل أو الانتظار' },
    LEGACY_ROWS_NOT_CANONICAL: { en: 'Legacy rows present but canonical content missing', ar: 'بيانات قديمة موجودة ولم تنتقل إلى المحتوى المعتمد' },
    NO_CANONICAL_ROWS: { en: 'Sync reports complete but content is still empty', ar: 'المزامنة مكتملة لكن المحتوى لا يزال فارغًا' },
    CANONICAL_ROWS_PRESENT: { en: 'Canonical content records exist', ar: 'المحتوى الفعلي موجود' },
    REMOTE_AUTH_FAILED: { en: 'Remote connector authentication failed', ar: 'فشل توثيق الاتصال بالموصل' },
    REMOTE_PROTOCOL_UNSUPPORTED: { en: 'Remote connector protocol is unsupported', ar: 'بروتوكول الموصل البعيد غير مدعوم' },
    REMOTE_NOT_READY: { en: 'Remote connector is not responding reliably', ar: 'الموصل البعيد غير جاهز أو لا يستجيب' },
};

function boundedText(input: unknown, fallback = 'unknown'): string {
    return typeof input === 'string' && /^[a-zA-Z0-9._-]{1,80}$/.test(input)
        ? input : fallback;
}

function count(input: unknown): number {
    return typeof input === 'number' && Number.isInteger(input) && input >= 0
        ? Math.min(input, 1000000000) : 0;
}

/** Explicit allowlist; future backend response additions cannot enter the user export. */
export function safeInspectorBundle(server: InspectorServer, events: RuntimeObservation[], context?: FrontendContext, liveAudit?: LiveAuditResult | null) {
    return {
        schema: 'aiwm.support-export.v1',
        exported_at: new Date().toISOString(),
        privacy: 'Read-only. No credentials, raw URLs, request or response bodies, content, stack traces, cookies or server logs.',
        server: {
            schema: boundedText(server.schema),
            generated_at: typeof server.generated_at === 'string' && /^\d{4}-\d\d-\d\dT/.test(server.generated_at)
                ? server.generated_at.slice(0, 32) : 'unknown',
            release: {
                version: boundedText(server.release.version),
                sha: /^[a-f0-9]{40}$/i.test(server.release.sha) ? server.release.sha : 'unknown',
            },
            runtime: {
                php_version: boundedText(server.runtime.php_version),
                laravel_version: boundedText(server.runtime.laravel_version),
                database: boundedText(server.runtime.database),
                database_driver: boundedText(server.runtime.database_driver),
                queue_driver: boundedText(server.runtime.queue_driver),
                cache_driver: boundedText(server.runtime.cache_driver),
                extensions: Object.fromEntries(Object.entries(server.runtime.extensions)
                    .filter(([name, value]) =>
                        ['pdo_mysql', 'openssl', 'sodium', 'mbstring', 'zip'].includes(name)
                        && typeof value === 'boolean')),
            },
            wordpress_plugin: {
                name: 'AIMW Connector',
                bundle_version: boundedText(server.wordpress_plugin?.bundle_version),
                download_available: server.wordpress_plugin?.download_available === true,
                install_target: 'wordpress_plugin',
            },
            site_count: count(server.site_count),
            sites_truncated: server.sites_truncated === true,
            sites: server.sites.slice(0, 50).map((site) => ({
                site_id: count(site.site_id),
                connection_state: boundedText(site.connection_state),
                health_state: boundedText(site.health_state),
                connector: {
                    present: site.connector.present === true,
                    revoked: site.connector.revoked === true,
                    verified: site.connector.verified === true,
                    protocol: boundedText(site.connector.protocol),
                    installed_version: boundedText(site.connector.installed_version),
                    enabled_scopes: site.connector.enabled_scopes
                        .filter((scope) => ['health', 'content.read', 'content.update', 'seo.read', 'diagnostics.read', 'connector.manage'].includes(scope)),
                },
                last_diagnostic: boundedText(site.last_diagnostic),
                remote_probe: {
                    attempted: site.remote_probe?.attempted === true,
                    state: boundedText(site.remote_probe?.state ?? 'not_run'),
                },
                last_sync: {
                    present: site.last_sync.present === true,
                    state: boundedText(site.last_sync.state),
                    finished: site.last_sync.finished === true,
                },
                record_counts: {
                    posts: count(site.record_counts.posts),
                    pages: count(site.record_counts.pages),
                    media: count(site.record_counts.media),
                    legacy_synced_contents: count(site.record_counts.legacy_synced_contents),
                },
                finding: boundedText(site.finding),
            })),
        },
        ...(context ? {
            workspace_census: (() => {
                const census = workspaceCapabilityCensus(context);
                return {
                    schema: census.schema,
                    safety: census.safety,
                    totals: census.totals,
                    workspaces: census.workspaces.map(entry => ({
                        key: entry.key,
                        required_permission: entry.required_permission,
                        permission_present: entry.permission_present,
                        connector_read: entry.connector_read,
                        view_state: entry.view_state,
                        backend_capability: entry.backend_capability,
                        api_contract: entry.api_contract,
                        write_controls_declared: entry.write_controls_declared,
                        write_controls_advertised: entry.write_controls_advertised,
                    })),
                };
            })(),
        } : {}),
        ...(liveAudit?.schema === 'aiwm.workspace-live-audit.v1' ? {
            workspace_live_audit: {
                schema: 'aiwm.workspace-live-audit.v1',
                safety: { read_only: true, request_methods: ['GET'], write_actions_executed: false },
                totals: {
                    routes: count(liveAudit.totals.routes),
                    passed_read: count(liveAudit.totals.passed_read),
                    failed_read: count(liveAudit.totals.failed_read),
                    skipped: count(liveAudit.totals.skipped),
                    not_tested: count(liveAudit.totals.not_tested),
                    write_actions_untested: count(liveAudit.totals.write_actions_untested),
                },
                workspaces: liveAudit.workspaces.slice(0, 80)
                    .filter(entry => /^[a-z0-9-]{1,50}$/.test(entry.key))
                    .map(entry => ({
                        key: entry.key,
                        state: ['passed_read', 'failed_read', 'skipped', 'not_tested'].includes(entry.state) ? entry.state : 'not_tested',
                        reason: ['read_ok', 'http_error', 'invalid_response', 'network_error',
                            'missing_permission', 'restricted', 'missing_contract', 'unsafe_endpoint',
                            'navigation_only', 'parameterized_endpoint'].includes(entry.reason) ? entry.reason : 'unsafe_endpoint',
                        http_status: entry.http_status === null || !Number.isInteger(entry.http_status)
                            ? null : Math.min(599, Math.max(100, entry.http_status)),
                        duration_ms: entry.duration_ms === null ? null : Math.min(60000, count(entry.duration_ms)),
                        declared_write_actions: count(entry.declared_write_actions),
                        advertised_write_actions: count(entry.advertised_write_actions),
                        tested_write_actions: 0,
                    })),
            },
        } : {}),
        browser: {
            family: typeof navigator !== 'undefined' && /Firefox\//.test(navigator.userAgent)
                ? 'Firefox' : typeof navigator !== 'undefined' && /Edg\//.test(navigator.userAgent)
                    ? 'Edge' : typeof navigator !== 'undefined' && /Chrome\//.test(navigator.userAgent)
                        ? 'Chrome' : typeof navigator !== 'undefined' && /Safari\//.test(navigator.userAgent)
                            ? 'Safari' : 'other',
            route_shape: typeof window !== 'undefined' ? diagnosticRouteShape(window.location.pathname) : '/:unknown',
            captured_events: events.slice(-120).map((event) => ({
                at: /^\d{4}-\d\d-\d\dT/.test(event.at) ? event.at.slice(0, 32) : 'unknown',
                kind: event.kind,
                ...(event.route ? { route: diagnosticRouteShape(event.route) } : {}),
                ...(event.method ? { method: event.method } : {}),
                ...(typeof event.status === 'number' ? { status: count(event.status) } : {}),
                ...(typeof event.duration_ms === 'number' ? { duration_ms: count(event.duration_ms) } : {}),
            })),
        },
    };
}

export function RuntimeInspectorPanel({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const [message, setMessage] = useState('');
    const [localCount, setLocalCount] = useState(() => runtimeInspectorEvents().length);
    const [liveAudit, setLiveAudit] = useState<LiveAuditResult | null>(null);
    const allowed = context.permissions.includes('*') || context.permissions.includes('diagnostics.view');
    const endpoint = `/tenants/${encodeURIComponent(context.tenant.slug)}/runtime-inspector/report`;
    const report = useQuery({
        queryKey: ['runtime-inspector', context.tenant.slug],
        queryFn: () => apiRequest<InspectorServer>(endpoint + '?probe=1'),
        enabled: false,
        retry: false,
        staleTime: 0,
    });

    if (!allowed) return <section className="panel" role="alert">{locale === 'ar' ? 'يلزم تصريح diagnostics.view لتشغيل الفحص.' : 'diagnostics.view permission is required.'}</section>;

    const isArabic = locale === 'ar';
    const run = async () => {
        setMessage('');
        await report.refetch();
        setLocalCount(runtimeInspectorEvents().length);
    };

    const exportReport = () => {
        if (!report.data || report.data.schema !== 'aiwm.runtime-inspector.v1') return;
        const bundle = safeInspectorBundle(report.data, runtimeInspectorEvents(), context, liveAudit);
        const blob = new Blob([JSON.stringify(bundle, null, 2)], { type: 'application/json' });
        const url = URL.createObjectURL(blob);
        const anchor = document.createElement('a');
        anchor.href = url;
        const version = boundedText(report.data.release.version).replace(/[^0-9.]/g, '');
        anchor.download = `AIWMWeb-Runtime-Inspector-v${version || 'unknown'}-${new Date().toISOString().slice(0, 10)}.json`;
        document.body.appendChild(anchor);
        anchor.click();
        anchor.remove();
        URL.revokeObjectURL(url);
        setMessage(isArabic ? 'تم إنشاء تقرير محلي مُنقّح. ارفعه داخل المحادثة لتحليل النتائج.' : 'A redacted local report has been exported. Attach it to the conversation for analysis.');
    };

    return (
        <div className="workspace-stack" data-canonical-operation={RUNTIME_INSPECTOR_OPERATION_ID}>
            <section className="hero-panel">
                <div>
                    <span className="workspace-kicker">SAFE SUPPORT DIAGNOSTICS</span>
                    <h2>{isArabic ? 'مفتش التشغيل الشامل' : 'Runtime Inspector'}</h2>
                    <p>{isArabic ? 'تشخيص فعلي للإصدار والاتصال وصلاحيات البلجن والمزامنة وأخطاء API. لا يجمع كلمات المرور أو محتوى المواقع.' : 'Real runtime, connector, sync and API status. No passwords or site content collected.'}</p>
                </div>
            </section>
            <section className="panel">
                <div className="toolbar-actions">
                    <button className="btn primary" type="button" onClick={() => void run()} disabled={report.isFetching}>{report.isFetching ? (isArabic ? 'جارٍ الفحص…' : 'Inspecting…') : (isArabic ? 'تشغيل الفحص' : 'Run Inspector')}</button>
                    <button className="btn" type="button" onClick={exportReport} disabled={!report.data || report.isFetching}>{isArabic ? 'تصدير التقرير JSON' : 'Export sanitized JSON'}</button>
                    <button className="btn" type="button" onClick={() => { clearRuntimeInspectorEvents(); setLocalCount(0); setMessage(isArabic ? 'تم مسح السجل المحلي.' : 'Local observations cleared.'); }}>{isArabic ? 'مسح السجل المحلي' : 'Clear local events'}</button>
                    <a className="btn" href={tenantUrl(context.tenant.slug, '/sites')}>{isArabic ? 'المواقع' : 'Sites'}</a>
                </div>
                <p>{isArabic ? 'الفحص للقراءة فقط. افتح الصفحات التي بها الخطأ ثم ارجع هنا وصدّر التقرير؛ يسجل المتصفح آخر 120 ملاحظة فقط أثناء هذه الجلسة.' : 'Read-only. Reproduce the issue in this tab, return here, and export. Only the last 120 local observations are retained in memory.'}</p>
                <p>{isArabic ? 'ملاحظات المتصفح الحالية:' : 'Captured browser events:'} {localCount}</p>
                {message ? <p role="status">{message}</p> : null}
                {report.error ? <p role="alert">{isArabic ? 'فشل جمع التشخيص:' : 'Diagnostic failed:'} {report.error instanceof Error ? report.error.message : 'Request failed'}</p> : null}
            </section>
            <WorkspaceCapabilityCensusPanel context={context} />
            <RuntimeWorkspaceAuditPanel context={context} onComplete={setLiveAudit} />
            {report.data ? (
                <section className="panel">
                    <h3>{isArabic ? 'نتيجة الخادم' : 'Server snapshot'}</h3>
                    <p>{isArabic ? 'الإصدار' : 'Release'}: <strong>{boundedText(report.data.release.version)}</strong> · SHA: <code>{boundedText(report.data.release.sha)}</code></p>
                    <p>PHP {boundedText(report.data.runtime.php_version)} · Laravel {boundedText(report.data.runtime.laravel_version)} · DB {boundedText(report.data.runtime.database)} · Queue {boundedText(report.data.runtime.queue_driver)}</p>
                    <h4>{isArabic ? 'إضافة WordPress المعتمدة' : 'WordPress plugin to install'}</h4>
                    <p><strong>AIMW Connector</strong> · {isArabic ? 'نسخة الحزمة' : 'Bundled version'}: {boundedText(report.data.wordpress_plugin?.bundle_version)}
                        · {isArabic ? 'مكان التثبيت: لوحة إضافات WordPress' : 'Install target: WordPress Plugins dashboard'}</p>
                    <p>{isArabic
                        ? 'نسخة الحزمة لا تثبت أن نفس الإصدار مثبت على الموقع. يظهر إصدار الإضافة الفعلي فقط عندما ينجح فحص الإمكانات الموقّع.'
                        : 'Bundled version is not evidence of the version installed on WordPress. The installed version is shown only after a successful signed capabilities probe.'}</p>
                    {report.data.wordpress_plugin?.download_available ? <DashboardConnectorDownloadControl /> :
                        <p role="status">{isArabic ? 'حزمة ZIP غير موجودة في هذا النشر؛ لا يمكن عرض رابط تنزيل فعال.' : 'WordPress ZIP is unavailable in this deployment; download is not offered.'}</p>}
                    <p>{isArabic ? 'إجمالي المواقع' : 'Sites'}: {count(report.data.site_count)} {report.data.sites_truncated ? (isArabic ? '(أول 50 فقط)' : '(first 50 only)') : ''}</p>
                    {report.data.sites.map((site) => (
                        <article className="panel" key={site.site_id}>
                            <h4>{isArabic ? 'الموقع' : 'Site'} #{site.site_id} — {boundedText(site.connection_state)}</h4>
                            <p>Connector: {site.connector.present ? (site.connector.revoked ? 'revoked' : 'paired') : 'not paired'} · verified: {String(site.connector.verified)} · protocol: {boundedText(site.connector.protocol)} · {site.connector.enabled_scopes.join(', ') || 'no scopes'}</p>
                            <p>{isArabic ? 'إصدار الإضافة المثبتة' : 'Installed plugin version'}: <strong>{boundedText(site.connector.installed_version, isArabic ? 'غير متحقق منه' : 'not verified')}</strong>
                                {' · '}{isArabic ? 'الإصدار المتوفر' : 'Available bundle'}: {boundedText(report.data.wordpress_plugin?.bundle_version)}
                                {' · '}{site.connector.installed_version && site.connector.installed_version !== 'unknown' && report.data.wordpress_plugin?.bundle_version !== 'unknown'
                                    ? site.connector.installed_version === report.data.wordpress_plugin?.bundle_version
                                        ? (isArabic ? 'الإصداران متطابقان' : 'Versions match')
                                        : (isArabic ? 'الإصداران مختلفان؛ راجع التحديث والتوافق' : 'Version differs; review update compatibility')
                                    : (isArabic ? 'التوافق غير مؤكد' : 'Compatibility unverified')}</p>
                            <p>Sync: {site.last_sync.state} · Posts: {site.record_counts.posts} · Pages: {site.record_counts.pages} · Media: {site.record_counts.media} · Legacy: {site.record_counts.legacy_synced_contents}</p>
                            <p>Remote Connector: {site.remote_probe?.state ?? 'not_run'} {site.remote_probe?.attempted ? '(signed GET)' : ''}</p>
                            <p><strong>{findings[site.finding]?.[locale] ?? boundedText(site.finding)}</strong></p>
                        </article>
                    ))}
                </section>
            ) : null}
        </div>
    );
}
