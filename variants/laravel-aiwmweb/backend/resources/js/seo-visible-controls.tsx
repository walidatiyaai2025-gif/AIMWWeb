import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
    APP_DATA_GRID_SELECT_ALL_FILTERED_OPERATION_ID,
    areAllAppDataGridFilteredSelected,
    canSelectAllAppDataGridFiltered,
    selectAllAppDataGridFiltered,
} from './app-data-grid-select-all-filtered-control';
import { createRoot } from 'react-dom/client';
import { acceptedSeoBatch, completedSeoProposal, parseSeoBatchStatus, seoAiJson, seoBatchStorageKey, validSavedSeoBatch } from './seo-ai-durable';
import type { SeoBatch, SeoProposalResult } from './seo-ai-durable';

export const SEO_OPERATIONS = {
    execution: 'AIMW-SEO-C48570747C',
    sites: 'AIMW-SEO-126222BD60',
    explorer: 'AIMW-SEO-0B5FC34109',
    external: 'AIMW-SEO-A4307E94C8',
    applyAllSafe: 'AIMW-SEO-C7C22677CB',
    applySelected: 'AIMW-SEO-4F3F2AC874',
    retryFailed: 'AIMW-AI-49E68B3816',
    route: 'AIMW-SEO-5F71B89C92',
    billing: 'AIMW-BILL-1EA01528A9',
    previousPage: 'AIMW-SEO-9FE309C9AE',
    resetFilters: 'AIMW-SEO-250C53DAC5',
    applyFilters: 'AIMW-BILL-B9C3030764',
    generateSuggestions: 'AIMW-BILL-E3C47C563B',
    runFullAudit: 'AIMW-BILL-3C55B3C299',
} as const;

export type SeoConfig = {
    tenant: string;
    can_view_billing: boolean;
    site: { id: number; name: string; url: string };
    urls: {
        audits: string;
        findings: string;
        prepare_bulk: string;
        ai_proposal: string;
        proposals: string;
        retry_failed: string;
        presentation: string;
        execution: string;
        sites: string;
        explorer: string;
        approvals: string;
        billing: string;
    };
};

type Finding = {
    id: number;
    code?: string | null;
    severity?: string | null;
    field?: string | null;
    recommendation?: string | null;
    before_value?: unknown;
    suggested_value?: unknown;
    status?: string | null;
};

type BulkResult = {
    prepared?: Array<{ finding_id: number; suggestion_id: number; approval_id: number; status: string }>;
    failed?: Array<{ finding_id: number; error: string }>;
};

type RetryResult = {
    queued?: number;
    execution_ids?: number[];
    mutated?: boolean;
};

export function seoBillingHref(config: SeoConfig): string | null {
    if (!config.can_view_billing) return null;
    const expected = `/tenants/${encodeURIComponent(config.tenant)}/account/billing`;
    return config.urls.billing === expected ? config.urls.billing : null;
}

type ProposalState = Record<number, Record<string, unknown>>;
const WRITABLE = new Set(['title', 'slug', 'seo_title', 'seo_description', 'seo_canonical', 'seo_robots']);

function csrfToken() {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

// A completed durable SEO result must be replayed through the original
// proposal endpoint with X-AIMW-AI-BATCH. Laravel verifies request fingerprint,
// membership, source context and approval semantics without a new AI call.
async function fetchSeoProposal(config: SeoConfig, findingId: number, replayId?: string, signal?: AbortSignal): Promise<SeoProposalResult> {
    const url = config.urls.ai_proposal.replace('__FINDING__', String(findingId));
    const result = await seoAiJson(url, {
        method: 'POST',
        body: JSON.stringify({}),
        ...(replayId ? { headers: { 'X-AIMW-AI-BATCH': replayId } } : {}),
        signal,
    });
    if (result.status === 202) {
        if (replayId) throw new Error('Completed AI replay was not available. Check saved batch status before retry.');
        return { kind: 'queued', batch: acceptedSeoBatch(result.payload, config.tenant, config.site.id, findingId) };
    }
    if (result.status !== 200) throw new Error('Unexpected AI proposal response status. Review AI diagnostics.');
    return { kind: 'ready', proposal: completedSeoProposal(result.payload) };
}


async function requestJson<T>(url: string, init: RequestInit = {}): Promise<T> {
    const method = (init.method ?? 'GET').toUpperCase();
    const headers = new Headers(init.headers);
    headers.set('Accept', 'application/json');
    if (method !== 'GET' && method !== 'HEAD') {
        headers.set('Content-Type', 'application/json');
        headers.set('X-CSRF-TOKEN', csrfToken());
    }
    const response = await fetch(url, { credentials: 'same-origin', ...init, headers });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) {
        const message = typeof payload?.message === 'string' ? payload.message : `Request failed with HTTP ${response.status}.`;
        throw new Error(message);
    }
    return payload as T;
}

function collectionRows(payload: unknown): Array<Record<string, unknown>> {
    if (Array.isArray(payload)) return payload as Array<Record<string, unknown>>;
    if (payload && typeof payload === 'object') {
        const candidate = payload as { data?: unknown; items?: unknown };
        if (Array.isArray(candidate.data)) return candidate.data as Array<Record<string, unknown>>;
        if (Array.isArray(candidate.items)) return candidate.items as Array<Record<string, unknown>>;
    }
    return [];
}

function isRetryableFailedProposal(row: Record<string, unknown>): boolean {
    const execution = row.execution;
    const proposedState = row.proposed_state;
    if (!execution || typeof execution !== 'object' || String((execution as Record<string, unknown>).status ?? '').toLowerCase() !== 'failed') return false;
    return Boolean(proposedState && typeof proposedState === 'object' && Object.keys(proposedState as Record<string, unknown>).length > 0);
}

function deterministicProposal(finding: Finding): Record<string, unknown> | null {
    const field = String(finding.field ?? '');
    if (!WRITABLE.has(field) || finding.suggested_value === null || finding.suggested_value === undefined || finding.suggested_value === '') return null;
    if (field === 'seo_robots' && typeof finding.suggested_value === 'string') {
        return { [field]: finding.suggested_value.split(/[\s,]+/).filter(Boolean) };
    }
    return { [field]: finding.suggested_value };
}

export function SeoVisibleControls({ config }: { config: SeoConfig }) {
    const [audits, setAudits] = useState<Array<Record<string, unknown>>>([]);
    const [findings, setFindings] = useState<Finding[]>([]);
    const [links, setLinks] = useState<Record<string, string | null>>({});
    const [persistedProposalCount, setPersistedProposalCount] = useState(0);
    const [failedProposalCount, setFailedProposalCount] = useState(0);
    const [proposalOverrides, setProposalOverrides] = useState<ProposalState>({});
    const [selected, setSelected] = useState<Set<number>>(new Set());
    const [query, setQuery] = useState('');
    const [severity, setSeverity] = useState('all');
    const [pageSize, setPageSize] = useState(10);
    const [page, setPage] = useState(1);
    const [serverTotal, setServerTotal] = useState<number | null>(null);
    const [serverLastPage, setServerLastPage] = useState(1);
    const readSequence = useRef(0);
    const announceNextRefresh = useRef(false);
    const storageKey = seoBatchStorageKey(config.tenant, config.site.id);
    const [pendingAi, setPendingAi] = useState<SeoBatch | null>(() => {
        try {
            const saved = window.sessionStorage.getItem(storageKey);
            return saved ? validSavedSeoBatch(JSON.parse(saved) as unknown, config.tenant, config.site.id) : null;
        } catch { return null; }
    });
    const [busy, setBusy] = useState(false);
    const [auditRunning, setAuditRunning] = useState(false);
    const [loading, setLoading] = useState(true);
    const [feedback, setFeedback] = useState<{ tone: 'success' | 'error' | 'info'; text: string } | null>(null);

    useEffect(() => {
        try {
            if (pendingAi) window.sessionStorage.setItem(storageKey, JSON.stringify(pendingAi));
            else window.sessionStorage.removeItem(storageKey);
        } catch {
            // A disabled browser storage API must not leak data or bypass server ownership.
        }
    }, [pendingAi, storageKey]);

    useEffect(() => {
        if (!pendingAi) return;
        const task = pendingAi;
        const controller = new AbortController();
        let cancelled = false;
        let timer: ReturnType<typeof setTimeout> | undefined;
        let attempts = 0;
        const poll = async () => {
            attempts += 1;
            try {
                const status = await seoAiJson(task.statusUrl, { signal: controller.signal });
                if (status.status !== 200) throw new Error('AI status request did not return a completed read.');
                const result = parseSeoBatchStatus(status.payload, task, config.site.id);
                if (cancelled) return;
                if (result.state === 'completed') {
                    const replay = await fetchSeoProposal(config, task.findingId, task.id, controller.signal);
                    if (replay.kind !== 'ready') throw new Error('Saved AI result is not ready for review.');
                    if (cancelled) return;
                    setProposalOverrides(current => ({ ...current, [task.findingId]: replay.proposal }));
                    setPendingAi(current => current?.id === task.id ? null : current);
                    setFeedback({ tone: 'success', text: 'Saved AI proposal for finding ' + task.findingId + ' is ready for explicit approval. No WordPress mutation occurred.' });
                    return;
                }
                if (result.state === 'failed' || result.state === 'cancelled') {
                    setPendingAi(current => current?.id === task.id ? null : current);
                    setFeedback({ tone: 'error', text: 'Saved AI task ' + result.state + (result.code ? ' (' + result.code + ')' : '') + '. Review AI diagnostics before another submission.' });
                    return;
                }
                setPendingAi(current => current?.id === task.id && current.state !== result.state ? { ...current, state: result.state } : current);
                setFeedback({ tone: 'info', text: 'Saved AI task for finding ' + task.findingId + ': ' + result.state + '. Background status checks do not submit another AI request.' });
            } catch (error) {
                if (cancelled || controller.signal.aborted) return;
                // Do not reflect server error/stack/provider response bodies in the UI.
                const safeMessage = error instanceof Error ? error.message : 'AI status check failed.';
                setFeedback({ tone: 'error', text: safeMessage });
            }
            if (cancelled) return;
            if (attempts >= 20) {
                setFeedback({ tone: 'info', text: 'AI task remains saved. Automatic checks paused; reload to resume without creating a new AI request.' });
                return;
            }
            timer = setTimeout(() => { void poll(); }, Math.min(2000 * (2 ** Math.min(attempts - 1, 3)), 15000));
        };
        void poll();
        return () => { cancelled = true; controller.abort(); if (timer) clearTimeout(timer); };
    }, [pendingAi?.id, pendingAi?.findingId, config.site.id, config.tenant, config.urls.ai_proposal]);

    const loadProposals = useCallback(async () => {
        const payload = await requestJson<unknown>(config.urls.proposals);
        const rows = collectionRows(payload);
        setPersistedProposalCount(rows.length);
        setFailedProposalCount(rows.filter(isRetryableFailedProposal).length);
    }, [config.urls.proposals]);

    const loadAuthoritative = useCallback(async (announce = false) => {
        const sequence = ++readSequence.current;
        const auditPayload = await requestJson<unknown>(config.urls.audits);
        const auditRows = collectionRows(auditPayload);
        if (sequence !== readSequence.current) return;
        setAudits(auditRows);
        const latestAuditId = Number(auditRows[0]?.id ?? 0);
        if (!latestAuditId) {
            setFindings([]);
            setServerTotal(0);
            setServerLastPage(1);
            setSelected(new Set());
            setLinks({});
            if (announce) setFeedback({ tone: 'info', text: 'No persisted SEO audit exists for this site yet.' });
            return;
        }
        const [findingPayload, presentation] = await Promise.all([
            requestJson<unknown>(config.urls.findings.replace('__AUDIT__', String(latestAuditId)) + '?' + new URLSearchParams({
                page: String(page),
                per_page: String(pageSize),
                ...(query.trim() ? { search: query.trim() } : {}),
                ...(severity !== 'all' ? { severity } : {}),
            }).toString()),
            requestJson<{ audit_id: number | null; links: Record<string, string | null> }>(config.urls.presentation),
        ]);
        if (sequence !== readSequence.current) return;
        if (!Array.isArray(findingPayload) && findingPayload && typeof findingPayload === 'object'
            && Array.isArray((findingPayload as { data?: unknown }).data)) {
            const envelope = findingPayload as { total?: unknown; last_page?: unknown; current_page?: unknown };
            if (!Number.isSafeInteger(envelope.total) || (envelope.total as number) < 0
                || !Number.isSafeInteger(envelope.last_page) || (envelope.last_page as number) < 1
                || !Number.isSafeInteger(envelope.current_page) || (envelope.current_page as number) < 1) {
                throw new Error('Invalid authoritative SEO findings pagination metadata.');
            }
            setServerTotal(envelope.total as number);
            setServerLastPage(envelope.last_page as number);
            setSelected(new Set());
        } else {
            // Legacy array contract stays locally pageable for older installed backends.
            setServerTotal(null);
        }
        setFindings(collectionRows(findingPayload).map((row) => row as Finding));
        setLinks(presentation.links ?? {});
        if (announce) setFeedback({ tone: 'info', text: 'Authoritative SEO findings were refreshed from Laravel.' });
    }, [config.urls.audits, config.urls.findings, config.urls.presentation, page, pageSize, query, severity]);

    useEffect(() => {
        let active = true;
        (async () => {
            try {
                const announce = announceNextRefresh.current;
                announceNextRefresh.current = false;
                await Promise.all([loadAuthoritative(announce), loadProposals()]);
            } catch (error) {
                if (active) setFeedback({ tone: 'error', text: error instanceof Error ? error.message : 'SEO data could not be loaded.' });
            } finally {
                if (active) setLoading(false);
            }
        })();
        return () => { active = false; };
    }, [loadAuthoritative, loadProposals]);

    const filtered = useMemo(() => serverTotal === null ? findings.filter((finding) => {
        const matchesSeverity = severity === 'all' || String(finding.severity ?? '') === severity;
        const needle = query.trim().toLowerCase();
        const haystack = [finding.code, finding.field, finding.recommendation].map((value) => String(value ?? '')).join(' ').toLowerCase();
        return matchesSeverity && (!needle || haystack.includes(needle));
    }) : findings, [findings, query, severity, serverTotal]);

    const lastPage = serverTotal === null ? Math.max(1, Math.ceil(filtered.length / pageSize)) : serverLastPage;
    const safePage = Math.min(page, lastPage);
    const visible = serverTotal === null ? filtered.slice((safePage - 1) * pageSize, safePage * pageSize) : filtered;

    const proposalFor = useCallback((finding: Finding) => proposalOverrides[finding.id] ?? deterministicProposal(finding), [proposalOverrides]);

    const selectableFilteredIds = useMemo(
        () => filtered.filter((finding) => Boolean(proposalFor(finding))).map((finding) => finding.id),
        [filtered, proposalFor],
    );
    const selectableVisibleIds = useMemo(
        () => visible.filter((finding) => Boolean(proposalFor(finding))).map((finding) => finding.id),
        [visible, proposalFor],
    );
    const allFilteredSelected = areAllAppDataGridFilteredSelected(selected, selectableFilteredIds);
    // Server pages do not contain the entire filtered set: never claim an all-results selection.
    const showSelectAllFiltered = serverTotal === null && canSelectAllAppDataGridFiltered(
        selectableFilteredIds.length,
        selectableVisibleIds.length,
    );

    const selectAllFiltered = () => {
        if (busy || !showSelectAllFiltered || allFilteredSelected) return;
        setSelected((current) => selectAllAppDataGridFiltered(current, selectableFilteredIds));
    };

    const runFullAudit = async () => {
        if (auditRunning) return;
        setAuditRunning(true);
        setFeedback({ tone: 'info', text: 'Starting the full SEO audit...' });
        try {
            const audit = await requestJson<{ id?: number; status?: string }>(config.urls.audits, { method: 'POST', body: JSON.stringify({}) });
            await loadAuthoritative(false);
            setFeedback({
                tone: 'success',
                text: `SEO audit ${audit.id ?? ''} queued successfully; authoritative results will refresh from Laravel as the job completes.`.replace('audit  queued', 'audit queued'),
            });
        } catch (error) {
            setFeedback({ tone: 'error', text: error instanceof Error ? error.message : 'SEO audit could not be started.' });
        } finally {
            setAuditRunning(false);
        }
    };

    const applyFilters = async () => {
        setPage(1);
        try {
            await loadAuthoritative(false);
            setFeedback({ tone: 'info', text: 'SEO results refreshed from the authoritative Laravel read model.' });
        } catch (error) {
            setFeedback({ tone: 'error', text: error instanceof Error ? error.message : 'SEO results could not be refreshed.' });
        }
    };

    const resetFilters = async () => {
        if (query === '' && severity === 'all' && pageSize === 10 && page === 1) {
            try {
                await loadAuthoritative(true);
            } catch (error) {
                setFeedback({ tone: 'error', text: error instanceof Error ? error.message : 'Filters reset, but the authoritative refresh failed.' });
            }
            return;
        }
        // A single effect-driven read must own the reset notification; avoid a
        // competing stale request clearing the success message.
        announceNextRefresh.current = true;
        setQuery('');
        setSeverity('all');
        setPageSize(10);
        setPage(1);
    };

    const previousPage = async () => {
        if (safePage <= 1) return;
        setPage(safePage - 1);
    };

    const nextPage = async () => {
        if (safePage >= lastPage) return;
        setPage(safePage + 1);
    };

    const runBulk = async (items: Array<{ finding_id: number; changes: Record<string, unknown> }>, label: string) => {
        if (!items.length || busy) return;
        setBusy(true);
        setFeedback(null);
        try {
            const result = await requestJson<BulkResult>(config.urls.prepare_bulk, { method: 'POST', body: JSON.stringify({ items }) });
            const prepared = result.prepared?.length ?? 0;
            const failed = result.failed?.length ?? 0;
            await Promise.all([loadProposals(), loadAuthoritative(false)]);
            setSelected(new Set());
            setFeedback({
                tone: failed ? 'info' : 'success',
                text: `${label}: ${prepared} change(s) prepared for approval${failed ? `, ${failed} failed` : ''}. No WordPress mutation occurs until explicit approval.`,
            });
        } catch (error) {
            setFeedback({ tone: 'error', text: error instanceof Error ? error.message : 'SEO remediation preparation failed.' });
        } finally {
            setBusy(false);
        }
    };

    const applySelected = () => {
        const items = findings
            .filter((finding) => selected.has(finding.id))
            .map((finding) => ({ finding_id: finding.id, changes: proposalFor(finding) }))
            .filter((item): item is { finding_id: number; changes: Record<string, unknown> } => Boolean(item.changes));
        return runBulk(items, 'Selected remediation');
    };

    const applyAllSafe = () => {
        const items = findings
            .map((finding) => ({ finding_id: finding.id, changes: proposalFor(finding) }))
            .filter((item): item is { finding_id: number; changes: Record<string, unknown> } => Boolean(item.changes));
        return runBulk(items, serverTotal === null ? 'Safe remediation batch' : 'Visible-page safe remediation batch');
    };

    const retryFailed = async () => {
        if (busy || failedProposalCount === 0) return;
        setBusy(true);
        setFeedback(null);
        try {
            const result = await requestJson<RetryResult>(config.urls.retry_failed, { method: 'POST', body: JSON.stringify({}) });
            const queued = Number(result.queued ?? 0);
            await Promise.all([loadProposals(), loadAuthoritative(false)]);
            setFeedback({
                tone: queued > 0 ? 'success' : 'info',
                text: `Retry failed: ${queued} failed execution(s) queued. WordPress mutation remains inside the approved execution job and authoritative re-read verification.`,
            });
        } catch (error) {
            setFeedback({ tone: 'error', text: error instanceof Error ? error.message : 'Failed SEO remediations could not be retried.' });
        } finally {
            setBusy(false);
        }
    };

    const requestAiProposal = async (finding: Finding): Promise<SeoProposalResult> => fetchSeoProposal(config, finding.id);

    const generateSuggestions = async () => {
        // One accepted durable AI submission per user action: never fan out 10+
        // provider requests from a single click on CPU/cPanel hosting.
        if (busy || pendingAi || findings.length === 0) return;
        const finding = findings.find(row => !proposalOverrides[row.id]);
        if (!finding) {
            setFeedback({ tone: 'info', text: 'All visible SEO findings already have reviewable AI proposals.' });
            return;
        }
        setBusy(true);
        setFeedback({ tone: 'info', text: 'Requesting one SEO AI proposal; further tasks require another explicit action.' });
        try {
            const outcome = await requestAiProposal(finding);
            if (outcome.kind === 'queued') {
                setPendingAi(outcome.batch);
                setFeedback({ tone: 'info', text: 'SEO AI task queued for finding ' + finding.id + '. It is saved and will be checked without resubmission.' });
            } else {
                setProposalOverrides(current => ({ ...current, [finding.id]: outcome.proposal }));
                setFeedback({ tone: 'success', text: 'Generated 1 reviewable suggestion(s). No WordPress mutation occurred.' });
            }
        } catch (error) {
            setFeedback({ tone: 'error', text: error instanceof Error ? error.message : 'AI proposal generation failed.' });
        } finally {
            setBusy(false);
        }
    };

    const generateAiProposal = async (finding: Finding) => {
        if (busy || pendingAi) return;
        setBusy(true);
        try {
            const outcome = await requestAiProposal(finding);
            if (outcome.kind === 'queued') {
                setPendingAi(outcome.batch);
                setFeedback({ tone: 'info', text: 'AI proposal for finding ' + finding.id + ' queued; status will be restored after reload.' });
            } else {
                setProposalOverrides(current => ({ ...current, [finding.id]: outcome.proposal }));
                setFeedback({ tone: 'info', text: 'AI proposal generated for finding ' + finding.id + '; it still requires explicit approval.' });
            }
        } catch (error) {
            setFeedback({ tone: 'error', text: error instanceof Error ? error.message : 'AI proposal generation failed.' });
        } finally {
            setBusy(false);
        }
    };

    if (loading) return <main className="workspace-stack"><section className="panel"><p>Loading authoritative SEO state…</p></section></main>;

    const safeCount = findings.filter((finding) => Boolean(proposalFor(finding))).length;
    const billingHref = seoBillingHref(config);

    return (
        <main className="workspace-stack" data-canonical-operation={SEO_OPERATIONS.route}>
            <section className="hero-panel">
                <div><span className="workspace-kicker">SEO MANAGER</span><h1>{config.site.name}</h1><p>Review real Laravel SEO findings and prepare governed remediations for approval.</p></div>
                <div className="toolbar-actions">
                    <button type="button" className="btn primary" data-testid="seo-run-full-audit" data-canonical-operation={SEO_OPERATIONS.runFullAudit} disabled={auditRunning} aria-busy={auditRunning} onClick={runFullAudit}>{auditRunning ? 'Running audit...' : 'Run full audit'}</button>
                    <a className="btn" data-canonical-operation={SEO_OPERATIONS.execution} href={config.urls.execution}>Execution Center</a>
                    <a className="btn" data-canonical-operation={SEO_OPERATIONS.sites} href={config.urls.sites}>Back to Sites</a>
                    <a className="btn" data-canonical-operation={SEO_OPERATIONS.explorer} href={config.urls.explorer}>Back to Explorer</a>
                    {billingHref ? <a className="btn primary" data-canonical-operation={SEO_OPERATIONS.billing} href={billingHref}>Review subscription</a> : null}
                </div>
            </section>

            {feedback ? <section className={`panel ${feedback.tone === 'error' ? 'state-danger' : ''}`} role={feedback.tone === 'error' ? 'alert' : 'status'}><p>{feedback.text}</p></section> : null}
            {pendingAi ? <section className="panel" role="status" aria-live="polite"><strong>Saved AI task</strong><p>Finding {pendingAi.findingId}: {pendingAi.state}. No new generation will be submitted while this task is pending.</p><a href={config.urls.execution}>Review in Execution Center</a></section> : null}

            <section className="toolbar-panel" aria-label="SEO filters">
                <label>Search <input aria-label="Search SEO findings" value={query} onChange={(event) => { setQuery(event.target.value); setPage(1); }} /></label>
                <label>Severity <select aria-label="SEO severity" value={severity} onChange={(event) => { setSeverity(event.target.value); setPage(1); }}><option value="all">All</option><option value="critical">Critical</option><option value="high">High</option><option value="medium">Medium</option><option value="low">Low</option></select></label>
                <label>Rows <select aria-label="SEO page size" value={pageSize} onChange={(event) => { setPageSize(Number(event.target.value)); setPage(1); }}><option value={10}>10</option><option value={25}>25</option><option value={50}>50</option><option value={100}>100</option></select></label>
                <button type="button" className="btn primary" data-canonical-operation={SEO_OPERATIONS.applyFilters} onClick={applyFilters}>Analyze</button>
                <button type="button" className="btn" data-canonical-operation={SEO_OPERATIONS.resetFilters} onClick={resetFilters}>Reset filters</button>
            </section>

            <section className="panel data-panel">
                <header className="panel-header"><div><span className="workspace-kicker">LATEST PERSISTED AUDIT</span><h2>SEO findings</h2></div><span className="count-badge">{serverTotal ?? filtered.length}</span></header>
                {audits.length === 0 ? <div className="empty-state"><strong>No persisted audit yet</strong><p>Run an SEO audit from the SEO Audit workspace before preparing remediations.</p></div> : null}
                {visible.length ? (
                    <div className="table-wrap"><table><thead><tr><th>Select</th><th>Severity</th><th>Finding</th><th>Field</th><th>Suggested change</th><th>Content</th><th>AI</th></tr></thead><tbody>
                        {visible.map((finding) => {
                            const proposal = proposalFor(finding);
                            const external = links[String(finding.id)];
                            return <tr key={finding.id}>
                                <td><input aria-label={`Select finding ${finding.id}`} type="checkbox" disabled={!proposal || busy} checked={selected.has(finding.id)} onChange={(event) => setSelected((current) => { const next = new Set(current); if (event.target.checked) next.add(finding.id); else next.delete(finding.id); return next; })} /></td>
                                <td>{finding.severity ?? '—'}</td><td><strong>{finding.code ?? `Finding ${finding.id}`}</strong><p>{finding.recommendation ?? ''}</p></td><td>{finding.field ?? '—'}</td>
                                <td><code>{proposal ? JSON.stringify(proposal) : 'No deterministic safe proposal'}</code></td>
                                <td>{external ? <a data-canonical-operation={SEO_OPERATIONS.external} href={external} target="_blank" rel="noreferrer">Open content ↗</a> : '—'}</td>
                                <td><button type="button" className="btn" disabled={busy || Boolean(pendingAi)} onClick={() => generateAiProposal(finding)}>Generate AI proposal</button></td>
                            </tr>;
                        })}
                    </tbody></table></div>
                ) : audits.length ? <div className="empty-state"><strong>No findings match the current filters.</strong></div> : null}

                {showSelectAllFiltered && !allFilteredSelected ? (
                    <div className="toolbar-panel app-data-grid__selection-scope" role="status" aria-live="polite" aria-atomic="true">
                        <span>Selection currently applies to visible rows unless all filtered rows are selected.</span>
                        <button
                            type="button"
                            className="btn"
                            data-canonical-operation={APP_DATA_GRID_SELECT_ALL_FILTERED_OPERATION_ID}
                            disabled={busy}
                            onClick={selectAllFiltered}
                        >
                            Select all filtered rows
                        </button>
                    </div>
                ) : null}

                <footer className="toolbar-panel">
                    <button type="button" className="btn" data-canonical-operation={SEO_OPERATIONS.previousPage} disabled={safePage <= 1 || busy} onClick={previousPage}>Previous</button>
                    <span aria-live="polite">Page {safePage} of {lastPage}{serverTotal !== null ? ` · ${visible.length} shown of ${serverTotal} matching` : ''}</span>
                    <button type="button" className="btn" disabled={safePage >= lastPage || busy} onClick={nextPage}>Next</button>
                </footer>
            </section>

            <section className="panel">
                <header className="panel-header"><div><span className="workspace-kicker">GOVERNED REMEDIATION</span><h2>Prepare for approval</h2></div><span className="count-badge">{persistedProposalCount} persisted</span></header>
                <p>{safeCount} finding(s) currently have a deterministic or AI proposal. Preparing a remediation creates a pending Approval; it does not mutate WordPress.</p>
                <div className="toolbar-actions">
                    <button type="button" className="btn primary" data-canonical-operation={SEO_OPERATIONS.generateSuggestions} disabled={busy || Boolean(pendingAi) || findings.length === 0} onClick={generateSuggestions}>Generate next suggestion</button>
                    <button type="button" className="btn primary" data-canonical-operation={SEO_OPERATIONS.applySelected} disabled={busy || selected.size === 0} onClick={applySelected}>Apply selected</button>
                    <button type="button" className="btn" data-canonical-operation={SEO_OPERATIONS.applyAllSafe} disabled={busy || safeCount === 0} onClick={applyAllSafe}>Apply all safe</button>
                    <button type="button" className="btn" data-testid="seo-retry-failed" data-canonical-operation={SEO_OPERATIONS.retryFailed} disabled={busy || failedProposalCount === 0} onClick={retryFailed}>Retry failed</button>
                    <a className="btn" href={config.urls.approvals}>Open Approval Queue</a>
                </div>
            </section>
        </main>
    );
}

function mount() {
    const root = document.getElementById('seo-visible-controls');
    const configScript = document.getElementById('seo-visible-controls-config');
    if (!root || !configScript?.textContent) return;
    const config = JSON.parse(configScript.textContent) as SeoConfig;
    createRoot(root).render(<SeoVisibleControls config={config} />);
}

if (typeof document !== 'undefined') mount();
