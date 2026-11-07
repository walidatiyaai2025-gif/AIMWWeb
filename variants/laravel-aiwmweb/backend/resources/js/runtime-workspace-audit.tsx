import React, { useState } from 'react';
import { workspaceRoutes, type FrontendContext } from './core';
import { useLocale } from './i18n';
import { workspaceCapabilityCensus } from './workspace-capability-census';

export type LiveAuditState = 'passed_read' | 'failed_read' | 'skipped' | 'not_tested';
export type LiveAuditEntry = {
    key: string;
    state: LiveAuditState;
    reason: 'read_ok' | 'http_error' | 'invalid_response' | 'network_error'
        | 'missing_permission' | 'restricted' | 'missing_contract' | 'unsafe_endpoint'
        | 'navigation_only' | 'parameterized_endpoint';
    http_status: number | null;
    duration_ms: number | null;
    declared_write_actions: number;
    advertised_write_actions: number;
    tested_write_actions: 0;
};
export type LiveAuditResult = {
    schema: 'aiwm.workspace-live-audit.v1';
    completed_at: string;
    safety: { read_only: true; request_methods: ['GET']; write_actions_executed: false };
    totals: { routes: number; passed_read: number; failed_read: number; skipped: number; not_tested: number; write_actions_untested: number };
    workspaces: LiveAuditEntry[];
};

/** Fail closed: only exact same-tenant relative GET APIs, never arbitrary URLs. */
export function safeAuditEndpoint(endpoint: unknown, slug: string): endpoint is string {
    if (typeof endpoint !== 'string' || endpoint.length > 500 ||
        !/^\/[a-zA-Z0-9/_%.-]+$/.test(endpoint) ||
        endpoint.includes('//') || /%2f|%5c/i.test(endpoint)) return false;
    let path: string;
    try { path = decodeURIComponent(endpoint); } catch { return false; }
    if (path.split('/').some(segment => segment === '..' || segment === '.')) return false;
    const tenant = encodeURIComponent(slug);
    return endpoint === '/api/dashboard'
        || endpoint.startsWith(`/api/tenants/${tenant}/`)
        || endpoint.startsWith(`/api/v1/tenants/${tenant}/`);
}

export function liveAuditPlan(context: FrontendContext): LiveAuditEntry[] {
    const census = workspaceCapabilityCensus(context);
    return census.workspaces.map(entry => {
        const route = workspaceRoutes.find(item => item.key === entry.key)!;
        const endpoint = route.apiKey ? context.api[route.apiKey] : undefined;
        const denied = !entry.permission_present ? 'missing_permission'
            : entry.backend_capability !== 'not_reported' && entry.backend_capability !== 'enabled'
                ? 'restricted' : null;
        const reason: LiveAuditEntry['reason'] = denied ?? (!route.apiKey
            ? 'navigation_only'
            : !endpoint ? 'missing_contract'
                : /(?:\/:|[{}])/.test(endpoint) ? 'parameterized_endpoint'
                    : !safeAuditEndpoint(endpoint, context.tenant.slug)
                        ? 'unsafe_endpoint' : 'read_ok');
        return {
            key: entry.key,
            state: reason === 'read_ok' ? 'not_tested' : (reason === 'navigation_only' ? 'not_tested' : 'skipped'),
            reason,
            http_status: null,
            duration_ms: null,
            declared_write_actions: entry.write_controls_declared,
            advertised_write_actions: entry.write_controls_advertised,
            tested_write_actions: 0,
        };
    });
}

/**
 * Verify the response, not just its Content-Type. Keep bodies in memory only for
 * this bounded check; never attach them to audit observations or support exports.
 */
const MAX_AUDIT_JSON_BYTES = 1024 * 1024;

async function containsBoundedJsonDocument(response: Response): Promise<boolean> {
    const contentType = (response.headers.get('content-type') ?? '').toLowerCase();
    if (!/^application\/(?:json|[a-z0-9.+-]+\+json)(?:\s*;|$)/.test(contentType)) return false;

    const declaredLength = response.headers.get('content-length');
    if (declaredLength !== null &&
        (!/^\d+$/.test(declaredLength) || Number(declaredLength) > MAX_AUDIT_JSON_BYTES)) return false;

    if (!response.body) return false;
    const reader = response.body.getReader();
    const decoder = new TextDecoder('utf-8', { fatal: true });
    let bytes = 0;
    let body = '';
    try {
        while (true) {
            const chunk = await reader.read();
            if (chunk.done) break;
            bytes += chunk.value.byteLength;
            if (bytes > MAX_AUDIT_JSON_BYTES) return false;
            body += decoder.decode(chunk.value, { stream: true });
        }
        body += decoder.decode();
        const document: unknown = JSON.parse(body);
        return document !== null && typeof document === 'object';
    } catch {
        return false;
    } finally {
        // Cancel any unread stream without preserving or exporting its contents.
        void reader.cancel().catch(() => {});
        reader.releaseLock();
    }
}

/** A 2xx JSON response verifies the GET contract only, not navigation or write workflows. */
export async function runReadOnlyWorkspaceAudit(
    context: FrontendContext,
    onProgress?: (done: number, total: number) => void,
    requester: typeof fetch = fetch,
): Promise<LiveAuditResult> {
    const entries = liveAuditPlan(context);
    const targets = entries.map((entry, index) => ({ entry, index }))
        .filter(({ entry }) => entry.reason === 'read_ok');
    const results = [...entries];
    const cached = new Map<string, Promise<{ state: LiveAuditState; reason: LiveAuditEntry['reason']; http_status: number | null; duration_ms: number | null }>>();
    let completed = 0;
    const get = async (endpoint: string) => {
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 6000);
        const started = Date.now();
        try {
            const response = await requester(endpoint, {
                method: 'GET',
                credentials: 'same-origin',
                redirect: 'error',
                cache: 'no-store',
                signal: controller.signal,
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const duration_ms = Math.min(60000, Math.max(0, Date.now() - started));
            const json = response.ok && await containsBoundedJsonDocument(response);
            const ok = response.ok && json;
            return {
                state: ok ? 'passed_read' as const : 'failed_read' as const,
                reason: ok ? 'read_ok' as const : response.ok ? 'invalid_response' as const : 'http_error' as const,
                http_status: response.status,
                duration_ms,
            };
        } catch {
            return { state: 'failed_read' as const, reason: 'network_error' as const, http_status: null, duration_ms: Math.min(60000, Date.now() - started) };
        } finally {
            clearTimeout(timeout);
        }
    };
    // Small batches: no WordPress writes, no AI submissions, no concurrency spikes.
    for (let i = 0; i < targets.length; i += 3) {
        await Promise.all(targets.slice(i, i + 3).map(async ({ index, entry }) => {
            const route = workspaceRoutes.find(route => route.key === entry.key)!;
            const endpoint = context.api[route.apiKey!];
            if (!cached.has(endpoint)) cached.set(endpoint, get(endpoint));
            const outcome = await cached.get(endpoint)!;
            results[index] = { ...entry, ...outcome };
            completed++;
            onProgress?.(completed, targets.length);
        }));
    }
    return {
        schema: 'aiwm.workspace-live-audit.v1',
        completed_at: new Date().toISOString(),
        safety: { read_only: true, request_methods: ['GET'], write_actions_executed: false },
        totals: {
            routes: results.length,
            passed_read: results.filter(entry => entry.state === 'passed_read').length,
            failed_read: results.filter(entry => entry.state === 'failed_read').length,
            skipped: results.filter(entry => entry.state === 'skipped').length,
            not_tested: results.filter(entry => entry.state === 'not_tested').length,
            write_actions_untested: results.reduce((sum, entry) => sum + entry.declared_write_actions, 0),
        },
        workspaces: results,
    };
}

const stateLabels = {
    passed_read: { en: 'GET passed (functions unverified)', ar: 'نجحت قراءة API (الوظائف لم تُثبت)' },
    failed_read: { en: 'GET failed', ar: 'فشل اختبار API' },
    skipped: { en: 'Skipped: missing/unsafe contract or permission', ar: 'لم يُنفذ: عقد أو صلاحية ناقصة/غير آمنة' },
    not_tested: { en: 'Not tested / navigation only', ar: 'لم يُختبر / صفحة تنقل' },
} as const;

export function RuntimeWorkspaceAuditPanel({ context, onComplete }: {
    context: FrontendContext;
    onComplete: (result: LiveAuditResult | null) => void;
}) {
    const { locale } = useLocale();
    const ar = locale === 'ar';
    const [working, setWorking] = useState(false);
    const [progress, setProgress] = useState('');
    const [result, setResult] = useState<LiveAuditResult | null>(null);
    const [error, setError] = useState(false);
    const start = async () => {
        if (working) return;
        setWorking(true);
        setError(false);
        setResult(null);
        onComplete(null);
        try {
            const next = await runReadOnlyWorkspaceAudit(context, (done, total) => setProgress(`${done}/${total}`));
            setResult(next);
            onComplete(next);
        } catch {
            setError(true);
        } finally {
            setWorking(false);
        }
    };
    return <section className="panel workspace-stack" data-testid="runtime-workspace-audit">
        <h3>{ar ? 'فحص تشغيل جميع الصفحات ووظائف القراءة' : 'All-workspace read-only live audit'}</h3>
        <p>{ar
            ? 'يمر على جميع المساحات المسجلة، ويفحص واجهات GET الآمنة فقط (بحد أقصى 3 بالتوازي، 6 ثوانٍ لكل طلب). لا يفتح صفحات المخزون أو ينفذ نشرًا أو حذفًا أو توليد AI. نجاح GET لا يعني أن كل أزرار الصفحة تعمل.'
            : 'Covers every declared workspace. Only safe same-tenant GET APIs are probed (max 3 concurrent, 6s/request). Never publishes, deletes, runs AI or executes write controls. Passing GET does not prove every button works.'}</p>
        <button className="btn primary" type="button" onClick={() => void start()} disabled={working}>
            {working ? (ar ? `جارٍ الفحص ${progress}` : `Scanning ${progress}`) : (ar ? 'فحص جميع الوظائف الآمنة' : 'Scan safe functions across all pages')}
        </button>
        {error && <p role="alert">{ar ? 'تعذر إكمال الفحص؛ لا تعتبر الوظائف ناجحة.' : 'Audit failed; no success is inferred.'}</p>}
        {result && <>
            <div role="status" className="status-grid">
                <span>{ar ? 'الصفحات' : 'Pages'}: {result.totals.routes}</span>
                <span>{ar ? 'GET ناجح' : 'GET passed'}: {result.totals.passed_read}</span>
                <span>{ar ? 'GET فاشل' : 'GET failed'}: {result.totals.failed_read}</span>
                <span>{ar ? 'تم تخطيه' : 'Skipped'}: {result.totals.skipped}</span>
                <span>{ar ? 'غير مختبر' : 'Not tested'}: {result.totals.not_tested}</span>
                <span>{ar ? 'أوامر الكتابة غير المختبرة' : 'Write actions not tested'}: {result.totals.write_actions_untested}</span>
            </div>
            <details>
                <summary>{ar ? 'النتائج لكل صفحة' : 'Per-page results'}</summary>
                <div className="data-table-scroll"><table>
                    <thead><tr><th>{ar ? 'الصفحة' : 'Page'}</th><th>{ar ? 'النتيجة' : 'Result'}</th><th>HTTP</th><th>{ar ? 'الزمن ms' : 'ms'}</th><th>{ar ? 'أوامر الكتابة غير المختبرة' : 'Untested writes'}</th></tr></thead>
                    <tbody>{result.workspaces.map(entry => <tr key={entry.key}>
                        <td>{entry.key}</td><td>{stateLabels[entry.state][locale]} ({entry.reason})</td>
                        <td>{entry.http_status ?? '—'}</td><td>{entry.duration_ms ?? '—'}</td>
                        <td>{entry.declared_write_actions}</td>
                    </tr>)}</tbody>
                </table></div>
            </details>
        </>}
    </section>;
}
