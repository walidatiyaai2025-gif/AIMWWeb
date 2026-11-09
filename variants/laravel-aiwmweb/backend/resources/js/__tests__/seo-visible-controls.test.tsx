import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { SEO_OPERATIONS, SeoVisibleControls } from '../seo-visible-controls';
import { seoBatchStorageKey } from '../seo-ai-durable';

const config = {
    tenant: 'alpha',
    can_view_billing: true,
    site: { id: 7, name: 'Alpha Site', url: 'https://alpha.test' },
    urls: {
        audits: '/api/tenants/alpha/sites/7/seo/audits',
        findings: '/api/tenants/alpha/sites/7/seo/audits/__AUDIT__/findings',
        prepare_bulk: '/api/tenants/alpha/sites/7/seo/remediations/bulk',
        ai_proposal: '/api/tenants/alpha/sites/7/seo/findings/__FINDING__/ai-proposal',
        proposals: '/api/v1/tenants/alpha/sites/7/seo/remediations/proposals',
        retry_failed: '/api/v1/tenants/alpha/sites/7/seo/remediations/failed/retry',
        presentation: '/tenants/alpha/sites/7/seo/presentation',
        execution: '/tenants/alpha/module/execution',
        sites: '/tenants/alpha/sites',
        explorer: '/tenants/alpha/module/posts?site=7',
        approvals: '/tenants/alpha/approvals',
        billing: '/tenants/alpha/account/billing',
    },
};

const findings = Array.from({ length: 12 }, (_, index) => ({
    id: index + 1,
    code: `finding-${index + 1}`,
    severity: index % 2 ? 'medium' : 'high',
    field: 'seo_title',
    recommendation: `Fix ${index + 1}`,
    suggested_value: `Title ${index + 1}`,
}));

function jsonResponse(payload: unknown, status = 200) {
    return { ok: status >= 200 && status < 300, status, json: async () => payload } as Response;
}

describe('SEO visible-control mass closure', () => {
    let fetchMock: ReturnType<typeof vi.fn>;
    let proposalsPayload: Array<Record<string, unknown>>;

    beforeEach(() => {
        document.head.innerHTML = '<meta name="csrf-token" content="csrf-test">';
        sessionStorage.clear();
        proposalsPayload = [];
        fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            const url = String(input);
            if (url.endsWith('/seo/audits') && init?.method === 'POST') return jsonResponse({ id: 45, status: 'queued' }, 202);
            if (url.endsWith('/seo/audits')) return jsonResponse({ data: [{ id: 44, status: 'succeeded' }] });
            if (url.includes('/seo/audits/44/findings')) return jsonResponse(findings);
            if (url.endsWith('/seo/presentation')) return jsonResponse({ audit_id: 44, links: { '1': 'https://alpha.test/real-content/' } });
            if (url.endsWith('/seo/remediations/proposals')) return jsonResponse({ data: proposalsPayload });
            if (url.endsWith('/seo/remediations/failed/retry') && init?.method === 'POST') {
                proposalsPayload = [];
                return jsonResponse({ queued: 1, execution_ids: [501], mutated: false }, 202);
            }
            if (url.endsWith('/seo/remediations/bulk') && init?.method === 'POST') {
                const body = JSON.parse(String(init.body));
                return jsonResponse({ prepared: body.items.map((item: { finding_id: number }, index: number) => ({ finding_id: item.finding_id, suggestion_id: 100 + index, approval_id: 200 + index, status: 'pending_approval' })), failed: [] }, 201);
            }
            if (url.includes('/ai-proposal')) return jsonResponse({ proposal: { seo_title: 'AI title' }, requires_approval: true });
            throw new Error(`Unexpected fetch: ${url}`);
        });
        vi.stubGlobal('fetch', fetchMock);
    });

    afterEach(() => vi.unstubAllGlobals());

    it('uses authoritative server pages and filters without claiming all-results selection', async () => {
        const serverRows = Array.from({ length: 27 }, (_, index) => ({
            id: index + 1,
            code: `target-${index + 1}`,
            severity: index < 17 ? 'high' : 'low',
            field: 'seo_title',
            suggested_value: `Title ${index + 1}`,
        }));
        fetchMock.mockImplementation(async (input: RequestInfo | URL) => {
            const url = String(input);
            if (url.endsWith('/seo/audits')) return jsonResponse({ data: [{ id: 44, status: 'succeeded' }] });
            if (url.includes('/seo/audits/44/findings')) {
                const params = new URL(url, 'https://example.test').searchParams;
                const page = Number(params.get('page') ?? 1);
                const size = Number(params.get('per_page') ?? 10);
                const rows = serverRows.filter(row =>
                    (!params.get('severity') || row.severity === params.get('severity'))
                    && (!params.get('search') || row.code.includes(params.get('search')!)));
                const lastPage = Math.max(1, Math.ceil(rows.length / size));
                const currentPage = Math.min(page, lastPage);
                return jsonResponse({
                    data: rows.slice((currentPage - 1) * size, currentPage * size),
                    total: rows.length, last_page: lastPage, current_page: currentPage,
                });
            }
            if (url.endsWith('/seo/presentation')) return jsonResponse({ audit_id: 44, links: {} });
            if (url.endsWith('/seo/remediations/proposals')) return jsonResponse({ data: [] });
            throw new Error(`Unexpected fetch: ${url}`);
        });
        const { container } = render(<SeoVisibleControls config={config} />);
        await screen.findByText('target-1');
        expect(screen.getByText('Page 1 of 3 · 10 shown of 27 matching')).toBeInTheDocument();
        expect(container.querySelector(`[data-canonical-operation="${SEO_OPERATIONS.applyAllSafe}"]`)).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Select all filtered rows' })).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Next' }));
        await screen.findByText('target-11');
        expect(screen.queryByText('target-1')).not.toBeInTheDocument();
        expect(fetchMock.mock.calls.some(([url]) => String(url).includes('page=2&per_page=10'))).toBe(true);

        fireEvent.change(screen.getByLabelText('SEO severity'), { target: { value: 'high' } });
        await waitFor(() => expect(screen.getByText('Page 1 of 2 · 10 shown of 17 matching')).toBeInTheDocument());
        expect(fetchMock.mock.calls.some(([url]) => String(url).includes('severity=high'))).toBe(true);
        fireEvent.change(screen.getByLabelText('SEO page size'), { target: { value: '100' } });
        await waitFor(() => expect(screen.getByText('Page 1 of 1 · 17 shown of 17 matching')).toBeInTheDocument());
        expect(fetchMock.mock.calls.some(([url]) => String(url).includes('per_page=100'))).toBe(true);
    });

    it('renders the canonical navigation, external link, reset and previous controls against authoritative reads', async () => {
        const { container } = render(<SeoVisibleControls config={config} />);
        await screen.findByText('finding-1');

        expect(container.querySelector(`[data-canonical-operation="${SEO_OPERATIONS.execution}"]`)).toHaveAttribute('href', config.urls.execution);
        expect(container.querySelector(`[data-canonical-operation="${SEO_OPERATIONS.sites}"]`)).toHaveAttribute('href', config.urls.sites);
        expect(container.querySelector(`[data-canonical-operation="${SEO_OPERATIONS.explorer}"]`)).toHaveAttribute('href', config.urls.explorer);
        expect(container.querySelector(`[data-canonical-operation="${SEO_OPERATIONS.external}"]`)).toHaveAttribute('href', 'https://alpha.test/real-content/');
        expect(container.querySelector(`[data-canonical-operation="${SEO_OPERATIONS.external}"]`)).toHaveAttribute('target', '_blank');

        fireEvent.change(screen.getByLabelText('Search SEO findings'), { target: { value: 'finding-12' } });
        expect(screen.getByText('finding-12')).toBeInTheDocument();
        fireEvent.click(container.querySelector(`[data-canonical-operation="${SEO_OPERATIONS.resetFilters}"]`) as HTMLElement);
        await waitFor(() => expect(screen.getByLabelText('Search SEO findings')).toHaveValue(''));
        await screen.findByText('Authoritative SEO findings were refreshed from Laravel.');

        fireEvent.click(screen.getByRole('button', { name: 'Next' }));
        await waitFor(() => expect(screen.getByText('Page 2 of 2')).toBeInTheDocument());
        fireEvent.click(container.querySelector(`[data-canonical-operation="${SEO_OPERATIONS.previousPage}"]`) as HTMLElement);
        await waitFor(() => expect(screen.getByText('Page 1 of 2')).toBeInTheDocument());
        expect(fetchMock.mock.calls.some(([url]) => String(url).includes('/seo/audits/44/findings'))).toBe(true);
    });

    it('runs the canonical full SEO audit through the real queued audit endpoint', async () => {
        const { container } = render(<SeoVisibleControls config={config} />);
        await screen.findByText('finding-1');

        const button = screen.getByTestId('seo-run-full-audit');
        expect(button).toHaveAttribute('data-canonical-operation', SEO_OPERATIONS.runFullAudit);
        expect(SEO_OPERATIONS.runFullAudit).toBe('AIMW-BILL-3C55B3C299');

        fireEvent.click(button);
        await screen.findByText('SEO audit 45 queued successfully; authoritative results will refresh from Laravel as the job completes.');

        const auditPost = fetchMock.mock.calls.find(([url, init]) => String(url).endsWith('/seo/audits') && init?.method === 'POST');
        expect(auditPost).toBeTruthy();
        expect(JSON.parse(String(auditPost?.[1]?.body))).toEqual({});
        expect(new Headers(auditPost?.[1]?.headers).get('X-CSRF-TOKEN')).toBe('csrf-test');
        expect(container.querySelector(`[data-canonical-operation="${SEO_OPERATIONS.runFullAudit}"]`)).toBeEnabled();
    });

    it('prepares selected and all-safe remediations through the real bulk contract and reports approval-gated feedback', async () => {
        const { container } = render(<SeoVisibleControls config={config} />);
        await screen.findByText('finding-1');

        fireEvent.click(screen.getByLabelText('Select finding 1'));
        fireEvent.click(container.querySelector(`[data-canonical-operation="${SEO_OPERATIONS.applySelected}"]`) as HTMLElement);
        await screen.findByText(/Selected remediation: 1 change\(s\) prepared for approval/);

        const selectedPost = fetchMock.mock.calls.find(([url, init]) => String(url).endsWith('/seo/remediations/bulk') && init?.method === 'POST');
        expect(selectedPost).toBeTruthy();
        expect(JSON.parse(String(selectedPost?.[1]?.body))).toEqual({ items: [{ finding_id: 1, changes: { seo_title: 'Title 1' } }] });
        expect(screen.getByText(/No WordPress mutation occurs until explicit approval/)).toBeInTheDocument();

        fireEvent.click(container.querySelector(`[data-canonical-operation="${SEO_OPERATIONS.applyAllSafe}"]`) as HTMLElement);
        await waitFor(() => expect(fetchMock.mock.calls.filter(([url, init]) => String(url).endsWith('/seo/remediations/bulk') && init?.method === 'POST').length).toBe(2));
        expect(screen.getByText(/Safe remediation batch: 12 change\(s\) prepared for approval/)).toBeInTheDocument();
    });

    it('generates reviewable suggestions for persisted findings through the canonical aggregate control without mutation', async () => {
        const { container } = render(<SeoVisibleControls config={config} />);
        await screen.findByText('finding-1');

        const button = container.querySelector(`[data-canonical-operation="${SEO_OPERATIONS.generateSuggestions}"]`) as HTMLElement;
        expect(button).toBeInTheDocument();
        expect(SEO_OPERATIONS.generateSuggestions).toBe('AIMW-BILL-E3C47C563B');

        fireEvent.click(button);
        await screen.findByText('Generated 1 reviewable suggestion(s). No WordPress mutation occurred.');

        const aiCalls = fetchMock.mock.calls.filter(([url, init]) => String(url).includes('/ai-proposal') && init?.method === 'POST');
        expect(aiCalls).toHaveLength(1);
        expect(aiCalls.every(([, init]) => JSON.parse(String(init?.body)) && true)).toBe(true);
        expect(screen.getAllByText(/AI title/).length).toBeGreaterThan(0);
        fireEvent.click(button);
        await waitFor(() => expect(fetchMock.mock.calls.filter(([url, init]) => String(url).includes('/ai-proposal') && init?.method === 'POST').length).toBe(2));
    });

    it('accepts 202 as durable queued work, GETs owned status and replays the finished proposal without a second generation', async () => {
        const batchId = '123e4567-e89b-42d3-a456-426614174000';
        const statusUrl = '/api/tenants/alpha/ai-batches/' + batchId;
        const previous = fetchMock.getMockImplementation() as (input: RequestInfo | URL, init?: RequestInit) => Promise<Response>;
        fetchMock.mockImplementation(async (input: RequestInfo | URL, init?: RequestInit) => {
            const url = String(input);
            if (url === statusUrl) return jsonResponse({
                data: { id: batchId, site_id: 7, state: 'completed', items: [{ state: 'completed', result: null }] },
            });
            if (url.includes('/ai-proposal') && init?.method === 'POST') {
                if (new Headers(init.headers).get('X-AIMW-AI-BATCH') === batchId) {
                    return jsonResponse({ proposal: { seo_title: 'Saved title' }, requires_approval: true });
                }
                return jsonResponse({
                    data: { id: batchId, site_id: 7, state: 'pending', status_url: statusUrl, items: [{ state: 'pending' }] },
                    status_url: statusUrl,
                }, 202);
            }
            return previous(input, init);
        });
        const { container } = render(<SeoVisibleControls config={config} />);
        await screen.findByText('finding-1');
        fireEvent.click(screen.getAllByRole('button', { name: 'Generate AI proposal' })[0]);
        await screen.findByText(/Saved AI proposal for finding 1 is ready for explicit approval/);
        expect(screen.getByText(/Saved title/)).toBeInTheDocument();
        expect(sessionStorage.getItem(seoBatchStorageKey('alpha', 7))).toBeNull();
        const posts = fetchMock.mock.calls.filter(([url, init]) => String(url).includes('/ai-proposal') && init?.method === 'POST');
        expect(posts).toHaveLength(2);
        expect(posts.filter(([, init]) => !new Headers(init?.headers).has('X-AIMW-AI-BATCH'))).toHaveLength(1);
        expect(container.querySelector('[data-canonical-operation="' + SEO_OPERATIONS.generateSuggestions + '"]')).toBeEnabled();
    });

    it('restores a pending tenant/site batch on reload and never creates a second AI generation', async () => {
        const id = '123e4567-e89b-42d3-a456-426614174001';
        const statusUrl = '/api/tenants/alpha/ai-batches/' + id;
        sessionStorage.setItem(seoBatchStorageKey('alpha', 7), JSON.stringify({
            findingId: 2, id, statusUrl, state: 'queued',
        }));
        const previous = fetchMock.getMockImplementation() as (input: RequestInfo | URL, init?: RequestInit) => Promise<Response>;
        fetchMock.mockImplementation(async (input: RequestInfo | URL, init?: RequestInit) => {
            if (String(input) === statusUrl) return jsonResponse({
                data: { id, site_id: 7, state: 'completed', items: [{ state: 'completed' }] },
            });
            if (String(input).includes('/ai-proposal') && init?.method === 'POST') {
                expect(new Headers(init.headers).get('X-AIMW-AI-BATCH')).toBe(id);
                return jsonResponse({ proposal: { seo_description: 'Recovered saved description' }, requires_approval: true });
            }
            return previous(input, init);
        });
        const { container } = render(<SeoVisibleControls config={config} />);
        await screen.findByText(/Saved AI proposal for finding 2 is ready for explicit approval/);
        expect(screen.getByText(/Recovered saved description/)).toBeInTheDocument();
        expect(fetchMock.mock.calls.filter(([url, init]) => String(url).includes('/ai-proposal') && init?.method === 'POST')).toHaveLength(1);
        expect(container.querySelector('[data-canonical-operation="' + SEO_OPERATIONS.generateSuggestions + '"]')).toBeEnabled();
    });

    it('holds a paused batch without resubmitting and rejects a foreign status URL before polling', async () => {
        const id = '123e4567-e89b-42d3-a456-426614174002';
        const statusUrl = '/api/tenants/alpha/ai-batches/' + id;
        const previous = fetchMock.getMockImplementation() as (input: RequestInfo | URL, init?: RequestInit) => Promise<Response>;
        fetchMock.mockImplementation(async (input: RequestInfo | URL, init?: RequestInit) => {
            if (String(input) === statusUrl) return jsonResponse({
                data: { id, site_id: 7, state: 'paused', items: [{ state: 'paused', error_code: 'quota_exceeded' }] },
            });
            if (String(input).includes('/ai-proposal') && init?.method === 'POST') return jsonResponse({
                data: { id, site_id: 7, state: 'pending', status_url: statusUrl },
                status_url: statusUrl,
            }, 202);
            return previous(input, init);
        });
        const { unmount } = render(<SeoVisibleControls config={config} />);
        await screen.findByText('finding-1');
        fireEvent.click(screen.getAllByRole('button', { name: 'Generate AI proposal' })[0]);
        await screen.findByText(/Saved AI task for finding 1: paused/);
        expect(screen.getByRole('button', { name: 'Generate next suggestion' })).toBeDisabled();
        expect(fetchMock.mock.calls.filter(([, init]) => init?.method === 'POST' && String(init?.body) === '{}')).toHaveLength(1);
        expect(JSON.parse(sessionStorage.getItem(seoBatchStorageKey('alpha', 7))!)).toMatchObject({ id, findingId: 1, state: 'paused' });
        unmount();
        sessionStorage.clear();

        fetchMock.mockImplementation(async (input: RequestInfo | URL, init?: RequestInit) => {
            if (String(input).includes('/ai-proposal') && init?.method === 'POST') return jsonResponse({
                data: { id, site_id: 7, state: 'pending', status_url: 'https://foreign.example/ai-batches/' + id },
                status_url: 'https://foreign.example/ai-batches/' + id,
            }, 202);
            return previous(input, init);
        });
        render(<SeoVisibleControls config={config} />);
        await screen.findByText('finding-1');
        fireEvent.click(screen.getAllByRole('button', { name: 'Generate AI proposal' })[0]);
        await screen.findByText(/AI batch acceptance contract is invalid/);
        expect(fetchMock.mock.calls.some(([url]) => String(url).startsWith('https://foreign.example'))).toBe(false);
    });

    it('shows sanitized AI HTTP failure and does not render remote error bodies', async () => {
        const previous = fetchMock.getMockImplementation() as (input: RequestInfo | URL, init?: RequestInit) => Promise<Response>;
        fetchMock.mockImplementation(async (input: RequestInfo | URL, init?: RequestInit) => {
            if (String(input).includes('/ai-proposal') && init?.method === 'POST')
                return jsonResponse({ message: 'Bearer secret-provider-token SQL exception' }, 504);
            return previous(input, init);
        });
        render(<SeoVisibleControls config={config} />);
        await screen.findByText('finding-1');
        fireEvent.click(screen.getAllByRole('button', { name: 'Generate AI proposal' })[0]);
        await screen.findByText(/AI provider timed out \(HTTP 504\)/);
        expect(screen.queryByText(/secret-provider-token/)).not.toBeInTheDocument();
    });

    it('surfaces the canonical Retry failed control only for retryable failed proposals and posts through the governed retry endpoint', async () => {
        proposalsPayload = [
            { proposed_state: { seo_title: 'Retry title' }, execution: { id: 501, status: 'failed', attempts: 2 } },
            { proposed_state: {}, execution: { id: 502, status: 'failed', attempts: 1 } },
            { proposed_state: { seo_title: 'Already done' }, execution: { id: 503, status: 'succeeded', attempts: 1 } },
        ];
        const { container } = render(<SeoVisibleControls config={config} />);
        await screen.findByText('finding-1');

        const retryButton = screen.getByTestId('seo-retry-failed');
        expect(retryButton).toBeEnabled();
        expect(retryButton).toHaveAttribute('data-canonical-operation', SEO_OPERATIONS.retryFailed);
        expect(SEO_OPERATIONS.retryFailed).toBe('AIMW-AI-49E68B3816');

        fireEvent.click(retryButton);
        await screen.findByText(/Retry failed: 1 failed execution\(s\) queued/);

        const retryPost = fetchMock.mock.calls.find(([url, init]) => String(url).endsWith('/seo/remediations/failed/retry') && init?.method === 'POST');
        expect(retryPost).toBeTruthy();
        expect(JSON.parse(String(retryPost?.[1]?.body))).toEqual({});
        const headers = new Headers(retryPost?.[1]?.headers);
        expect(headers.get('X-CSRF-TOKEN')).toBe('csrf-test');
        expect(screen.getByText(/authoritative re-read verification/)).toBeInTheDocument();
        await waitFor(() => expect(container.querySelector('[data-testid="seo-retry-failed"]')).toBeDisabled());
    });
});
