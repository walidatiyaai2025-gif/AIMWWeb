import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { SEO_OPERATIONS, SeoVisibleControls } from '../seo-visible-controls';

const config = {
    tenant: 'alpha',
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
        proposalsPayload = [];
        fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            const url = String(input);
            if (url.endsWith('/seo/audits')) return jsonResponse({ data: [{ id: 44, status: 'succeeded' }] });
            if (url.includes('/seo/audits/44/findings')) return jsonResponse(findings);
            if (url.endsWith('/seo/presentation')) return jsonResponse({ audit_id: 44, links: { '1': 'https://alpha.test/real-content/' } });
            if (url.endsWith('/seo/remediations/proposals')) return jsonResponse({ data: proposalsPayload });
            if (url.endsWith('/seo/remediations/failed/retry') && init?.method === 'POST') return jsonResponse({ queued: 1, execution_ids: [501], mutated: false }, 202);
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
