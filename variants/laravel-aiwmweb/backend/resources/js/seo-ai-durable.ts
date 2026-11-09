// Browser contract for tenant-owned durable SEO AI batches.
// Only opaque batch identity is persisted. Never store prompts, tokens or model output.
export type SeoBatch = { findingId: number; id: string; statusUrl: string; state: string };
export type SeoProposalResult =
    | { kind: 'ready'; proposal: Record<string, unknown> }
    | { kind: 'queued'; batch: SeoBatch };

type JsonObject = Record<string, unknown>;

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const STATES = new Set(['pending', 'queued', 'running', 'paused', 'failed', 'needs_reconciliation', 'completed', 'cancelled']);
const WRITABLE = new Set(['title', 'slug', 'seo_title', 'seo_description', 'seo_canonical', 'seo_robots']);

function object(value: unknown): JsonObject | null {
    return value !== null && typeof value === 'object' && !Array.isArray(value) ? value as JsonObject : null;
}

export function seoBatchStorageKey(tenant: string, siteId: number): string {
    return 'aimw:seo-ai-batch:' + encodeURIComponent(tenant) + ':' + siteId;
}

export function seoBatchPath(tenant: string, id: string): string {
    return '/api/tenants/' + encodeURIComponent(tenant) + '/ai-batches/' + id;
}

export function validSavedSeoBatch(value: unknown, tenant: string, siteId: number): SeoBatch | null {
    const row = object(value);
    if (!row || !Number.isSafeInteger(row.findingId) || Number(row.findingId) < 1
        || typeof row.id !== 'string' || !UUID.test(row.id)
        || row.statusUrl !== seoBatchPath(tenant, row.id)
        || typeof row.state !== 'string' || !STATES.has(row.state)
        || !Number.isSafeInteger(siteId) || siteId < 1) return null;
    return { findingId: Number(row.findingId), id: row.id, statusUrl: row.statusUrl as string, state: row.state };
}

export function acceptedSeoBatch(payload: unknown, tenant: string, siteId: number, findingId: number): SeoBatch {
    const envelope = object(payload);
    const batch = object(envelope?.data);
    const candidate = {
        findingId,
        id: batch?.id,
        statusUrl: envelope?.status_url,
        state: batch?.state,
    };
    const parsed = validSavedSeoBatch(candidate, tenant, siteId);
    // Never allow a status URL returned from a server/provider to control the
    // browser's destination. Require the canonical same-tenant batch route.
    if (!parsed || batch?.status_url !== parsed.statusUrl || Number(batch?.site_id) !== siteId) {
        throw new Error('AI batch acceptance contract is invalid. No new generation was submitted.');
    }
    return parsed;
}

export function completedSeoProposal(payload: unknown): Record<string, unknown> {
    const data = object(payload);
    const proposal = object(data?.proposal);
    if (data?.requires_approval !== true || !proposal || Object.keys(proposal).length === 0
        || Object.keys(proposal).some(key => !WRITABLE.has(key))) {
        throw new Error('AI proposal is missing valid approval-gated changes.');
    }
    return proposal;
}

export function safeSeoAiError(status: number): string {
    switch (status) {
        case 401: return 'AI request requires signing in again (HTTP 401).';
        case 403: return 'AI request is not authorized (HTTP 403).';
        case 404: return 'AI task is unavailable for this tenant or site (HTTP 404).';
        case 409: return 'Saved AI result is not ready or its source context changed (HTTP 409).';
        case 422: return 'AI output or request validation failed (HTTP 422).';
        case 429: return 'AI capacity or quota reached (HTTP 429).';
        case 503: return 'AI provider or background worker is unavailable (HTTP 503).';
        case 504: return 'AI provider timed out (HTTP 504).';
        default: return 'AI request failed (HTTP ' + (Number.isSafeInteger(status) ? status : 'unknown') + '). Check AI diagnostics.';
    }
}

export async function seoAiJson(url: string, init: RequestInit = {}): Promise<{ status: number; payload: unknown }> {
    const method = (init.method ?? 'GET').toUpperCase();
    const headers = new Headers(init.headers);
    headers.set('Accept', 'application/json');
    if (method !== 'GET' && method !== 'HEAD') {
        headers.set('Content-Type', 'application/json');
        const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
        headers.set('X-CSRF-TOKEN', csrf);
    }
    const response = await fetch(url, { credentials: 'same-origin', ...init, headers });
    const payload: unknown = await response.json().catch(() => null);
    if (!response.ok) throw new Error(safeSeoAiError(response.status));
    return { status: response.status, payload };
}

export function parseSeoBatchStatus(payload: unknown, pending: SeoBatch, siteId: number): { state: string; code: string | null } {
    const data = object(object(payload)?.data);
    const items = data?.items;
    if (!data || data.id !== pending.id || Number(data.site_id) !== siteId
        || !Array.isArray(items) || items.length !== 1 || !object(items[0])) {
        throw new Error('Saved AI task response failed ownership/shape verification.');
    }
    const item = object(items[0])!;
    const state = String(item.state ?? data.state ?? '');
    if (!STATES.has(state)) throw new Error('Saved AI task returned an unknown state.');
    const code = typeof item.error_code === 'string' && /^[a-z][a-z0-9_]{0,59}$/.test(item.error_code)
        ? item.error_code : null;
    return { state, code };
}
