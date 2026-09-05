import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { createRoot } from 'react-dom/client';

export const SEO_OPERATIONS = {
    execution: 'AIMW-SEO-C48570747C',
    sites: 'AIMW-SEO-126222BD60',
    explorer: 'AIMW-SEO-0B5FC34109',
    external: 'AIMW-SEO-A4307E94C8',
    applyAllSafe: 'AIMW-SEO-C7C22677CB',
    applySelected: 'AIMW-SEO-4F3F2AC874',
    route: 'AIMW-SEO-5F71B89C92',
    previousPage: 'AIMW-SEO-9FE309C9AE',
    resetFilters: 'AIMW-SEO-250C53DAC5',
} as const;

type SeoConfig = {
    tenant: string;
    site: { id: number; name: string; url: string };
    urls: {
        audits: string;
        findings: string;
        prepare_bulk: string;
        ai_proposal: string;
        proposals: string;
        presentation: string;
        execution: string;
        sites: string;
        explorer: string;
        approvals: string;
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

type ProposalState = Record<number, Record<string, unknown>>;
const WRITABLE = new Set(['title', 'slug', 'seo_title', 'seo_description', 'seo_canonical', 'seo_robots']);

function csrfToken() {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
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
    const [proposalOverrides, setProposalOverrides] = useState<ProposalState>({});
    const [selected, setSelected] = useState<Set<number>>(new Set());
    const [query, setQuery] = useState('');
    const [severity, setSeverity] = useState('all');
    const [pageSize, setPageSize] = useState(10);
    const [page, setPage] = useState(1);
    const [busy, setBusy] = useState(false);
    const [loading, setLoading] = useState(true);
    const [feedback, setFeedback] = useState<{ tone: 'success' | 'error' | 'info'; text: string } | null>(null);

    const loadProposals = useCallback(async () => {
        const payload = await requestJson<unknown>(config.urls.proposals);
        setPersistedProposalCount(collectionRows(payload).length);
    }, [config.urls.proposals]);

    const loadAuthoritative = useCallback(async (announce = false) => {
        const auditPayload = await requestJson<unknown>(config.urls.audits);
        const auditRows = collectionRows(auditPayload);
        setAudits(auditRows);
        const latestAuditId = Number(auditRows[0]?.id ?? 0);
        if (!latestAuditId) {
            setFindings([]);
            setLinks({});
            if (announce) setFeedback({ tone: 'info', text: 'No persisted SEO audit exists for this site yet.' });
            return;
        }
        const [findingPayload, presentation] = await Promise.all([
            requestJson<unknown>(config.urls.findings.replace('__AUDIT__', String(latestAuditId))),
            requestJson<{ audit_id: number | null; links: Record<string, string | null> }>(config.urls.presentation),
        ]);
        setFindings(collectionRows(findingPayload).map((row) => row as Finding));
        setLinks(presentation.links ?? {});
        if (announce) setFeedback({ tone: 'info', text: 'Authoritative SEO findings were refreshed from Laravel.' });
    }, [config.urls.audits, config.urls.findings, config.urls.presentation]);

    useEffect(() => {
        let active = true;
        (async () => {
            try {
                await Promise.all([loadAuthoritative(false), loadProposals()]);
            } catch (error) {
                if (active) setFeedback({ tone: 'error', text: error instanceof Error ? error.message : 'SEO data could not be loaded.' });
            } finally {
                if (active) setLoading(false);
            }
        })();
        return () => { active = false; };
    }, [loadAuthoritative, loadProposals]);

    const filtered = useMemo(() => findings.filter((finding) => {
        const matchesSeverity = severity === 'all' || String(finding.severity ?? '') === severity;
        const needle = query.trim().toLowerCase();
        const haystack = [finding.code, finding.field, finding.recommendation].map((value) => String(value ?? '')).join(' ').toLowerCase();
        return matchesSeverity && (!needle || haystack.includes(needle));
    }), [findings, query, severity]);

    const lastPage = Math.max(1, Math.ceil(filtered.length / pageSize));
    const safePage = Math.min(page, lastPage);
    const visible = filtered.slice((safePage - 1) * pageSize, safePage * pageSize);

    const proposalFor = useCallback((finding: Finding) => proposalOverrides[finding.id] ?? deterministicProposal(finding), [proposalOverrides]);

    const resetFilters = async () => {
        setQuery('');
        setSeverity('all');
        setPageSize(10);
        setPage(1);
        try {
            await loadAuthoritative(true);
        } catch (error) {
            setFeedback({ tone: 'error', text: error instanceof Error ? error.message : 'Filters reset, but the authoritative refresh failed.' });
        }
    };

    const previousPage = async () => {
        if (safePage <= 1) return;
        setPage(safePage - 1);
        try {
            await loadAuthoritative(false);
            setFeedback({ tone: 'info', text: `Showing page ${safePage - 1} after an authoritative findings refresh.` });
        } catch (error) {
            setFeedback({ tone: 'error', text: error instanceof Error ? error.message : 'Previous page could not be reconciled.' });
        }
    };

    const nextPage = async () => {
        if (safePage >= lastPage) return;
        setPage(safePage + 1);
        try {
            await loadAuthoritative(false);
        } catch (error) {
            setFeedback({ tone: 'error', text: error instanceof Error ? error.message : 'Next page could not be reconciled.' });
        }
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
        return runBulk(items, 'Safe remediation batch');
    };

    const generateAiProposal = async (finding: Finding) => {
        setBusy(true);
        try {
            const payload = await requestJson<{ proposal?: Record<string, unknown>; requires_approval?: boolean }>(
                config.urls.ai_proposal.replace('__FINDING__', String(finding.id)),
                { method: 'POST', body: JSON.stringify({}) },
            );
            if (!payload.proposal || payload.requires_approval !== true) throw new Error('The AI proposal contract did not return an approval-gated proposal.');
            setProposalOverrides((current) => ({ ...current, [finding.id]: payload.proposal! }));
            setFeedback({ tone: 'info', text: `AI proposal generated for finding ${finding.id}; it still requires explicit approval.` });
        } catch (error) {
            setFeedback({ tone: 'error', text: error instanceof Error ? error.message : 'AI proposal generation failed.' });
        } finally {
            setBusy(false);
        }
    };

    if (loading) return <main className="workspace-stack"><section className="panel"><p>Loading authoritative SEO state…</p></section></main>;

    const safeCount = findings.filter((finding) => Boolean(proposalFor(finding))).length;

    return (
        <main className="workspace-stack" data-canonical-operation={SEO_OPERATIONS.route}>
            <section className="hero-panel">
                <div><span className="workspace-kicker">SEO MANAGER</span><h1>{config.site.name}</h1><p>Review real Laravel SEO findings and prepare governed remediations for approval.</p></div>
                <div className="toolbar-actions">
                    <a className="btn" data-canonical-operation={SEO_OPERATIONS.execution} href={config.urls.execution}>Execution Center</a>
                    <a className="btn" data-canonical-operation={SEO_OPERATIONS.sites} href={config.urls.sites}>Back to Sites</a>
                    <a className="btn" data-canonical-operation={SEO_OPERATIONS.explorer} href={config.urls.explorer}>Back to Explorer</a>
                </div>
            </section>

            {feedback ? <section className={`panel ${feedback.tone === 'error' ? 'state-danger' : ''}`} role={feedback.tone === 'error' ? 'alert' : 'status'}><p>{feedback.text}</p></section> : null}

            <section className="toolbar-panel" aria-label="SEO filters">
                <label>Search <input aria-label="Search SEO findings" value={query} onChange={(event) => { setQuery(event.target.value); setPage(1); }} /></label>
                <label>Severity <select aria-label="SEO severity" value={severity} onChange={(event) => { setSeverity(event.target.value); setPage(1); }}><option value="all">All</option><option value="critical">Critical</option><option value="high">High</option><option value="medium">Medium</option><option value="low">Low</option></select></label>
                <label>Rows <select aria-label="SEO page size" value={pageSize} onChange={(event) => { setPageSize(Number(event.target.value)); setPage(1); }}><option value={10}>10</option><option value={25}>25</option><option value={50}>50</option></select></label>
                <button type="button" className="btn" data-canonical-operation={SEO_OPERATIONS.resetFilters} onClick={resetFilters}>Reset filters</button>
            </section>

            <section className="panel data-panel">
                <header className="panel-header"><div><span className="workspace-kicker">LATEST PERSISTED AUDIT</span><h2>SEO findings</h2></div><span className="count-badge">{filtered.length}</span></header>
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
                                <td><button type="button" className="btn" disabled={busy} onClick={() => generateAiProposal(finding)}>Generate AI proposal</button></td>
                            </tr>;
                        })}
                    </tbody></table></div>
                ) : audits.length ? <div className="empty-state"><strong>No findings match the current filters.</strong></div> : null}

                <footer className="toolbar-panel">
                    <button type="button" className="btn" data-canonical-operation={SEO_OPERATIONS.previousPage} disabled={safePage <= 1 || busy} onClick={previousPage}>Previous</button>
                    <span aria-live="polite">Page {safePage} of {lastPage}</span>
                    <button type="button" className="btn" disabled={safePage >= lastPage || busy} onClick={nextPage}>Next</button>
                </footer>
            </section>

            <section className="panel">
                <header className="panel-header"><div><span className="workspace-kicker">GOVERNED REMEDIATION</span><h2>Prepare for approval</h2></div><span className="count-badge">{persistedProposalCount} persisted</span></header>
                <p>{safeCount} finding(s) currently have a deterministic or AI proposal. Preparing a remediation creates a pending Approval; it does not mutate WordPress.</p>
                <div className="toolbar-actions">
                    <button type="button" className="btn primary" data-canonical-operation={SEO_OPERATIONS.applySelected} disabled={busy || selected.size === 0} onClick={applySelected}>Apply selected</button>
                    <button type="button" className="btn" data-canonical-operation={SEO_OPERATIONS.applyAllSafe} disabled={busy || safeCount === 0} onClick={applyAllSafe}>Apply all safe</button>
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
