import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    APP_DATA_GRID_SELECT_ALL_FILTERED_OPERATION_ID,
    areAllAppDataGridFilteredSelected,
    canSelectAllAppDataGridFiltered,
    selectAllAppDataGridFiltered,
} from '../app-data-grid-select-all-filtered-control';
import { SeoVisibleControls } from '../seo-visible-controls';

const config = {
    tenant: 'alpha',
    can_view_billing: false,
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

describe(`${APP_DATA_GRID_SELECT_ALL_FILTERED_OPERATION_ID} AppDataGrid SelectAllFilteredAsync`, () => {
    let fetchMock: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        document.head.innerHTML = '<meta name="csrf-token" content="csrf-test">';
        fetchMock = vi.fn(async (input: RequestInfo | URL) => {
            const url = String(input);
            if (url.endsWith('/seo/audits')) return jsonResponse({ data: [{ id: 44, status: 'succeeded' }] });
            if (url.includes('/seo/audits/44/findings')) return jsonResponse(findings);
            if (url.endsWith('/seo/presentation')) return jsonResponse({ audit_id: 44, links: {} });
            if (url.endsWith('/seo/remediations/proposals')) return jsonResponse({ data: [] });
            throw new Error(`Unexpected fetch: ${url}`);
        });
        vi.stubGlobal('fetch', fetchMock);
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
    });

    it('matches the source selection semantics without mutating the supplied set', () => {
        const current = new Set([99, 1]);
        const next = selectAllAppDataGridFiltered(current, [1, 2, 3]);

        expect(Array.from(next).sort((a, b) => a - b)).toEqual([1, 2, 3, 99]);
        expect(Array.from(current).sort((a, b) => a - b)).toEqual([1, 99]);
        expect(areAllAppDataGridFilteredSelected(next, [1, 2, 3])).toBe(true);
        expect(areAllAppDataGridFilteredSelected(next, [])).toBe(false);
        expect(canSelectAllAppDataGridFiltered(12, 10)).toBe(true);
        expect(canSelectAllAppDataGridFiltered(10, 10)).toBe(false);
    });

    it('selects every proposal-eligible filtered finding across pages without dispatching a request', async () => {
        const { container } = render(<SeoVisibleControls config={config} />);
        await screen.findByText('finding-1');

        const button = container.querySelector(
            `[data-canonical-operation="${APP_DATA_GRID_SELECT_ALL_FILTERED_OPERATION_ID}"]`,
        ) as HTMLButtonElement;
        expect(button).toBeInTheDocument();
        expect(button).toHaveTextContent('Select all filtered rows');

        const readsBeforeSelection = fetchMock.mock.calls.length;
        fireEvent.click(button);
        expect(fetchMock).toHaveBeenCalledTimes(readsBeforeSelection);
        await waitFor(() => expect(container.querySelector(
            `[data-canonical-operation="${APP_DATA_GRID_SELECT_ALL_FILTERED_OPERATION_ID}"]`,
        )).not.toBeInTheDocument());

        fireEvent.click(screen.getByRole('button', { name: 'Next' }));
        await waitFor(() => expect(screen.getByText('Page 2 of 2')).toBeInTheDocument());

        expect(await screen.findByLabelText('Select finding 11')).toBeChecked();
        expect(await screen.findByLabelText('Select finding 12')).toBeChecked();
        expect(fetchMock.mock.calls.some(([url]) => String(url).includes('/seo/audits/44/findings'))).toBe(true);
        expect(fetchMock.mock.calls.every(([, init]) => !init || !init.method || init.method === 'GET')).toBe(true);
    });

    it('does not expose the generic control when the filtered selectable set fits on the current page', async () => {
        const { container } = render(<SeoVisibleControls config={config} />);
        await screen.findByText('finding-1');

        fireEvent.change(screen.getByLabelText('SEO severity'), { target: { value: 'high' } });

        await waitFor(() => expect(container.querySelector(
            `[data-canonical-operation="${APP_DATA_GRID_SELECT_ALL_FILTERED_OPERATION_ID}"]`,
        )).not.toBeInTheDocument());
        expect(screen.getByText('finding-1')).toBeInTheDocument();
        expect(screen.queryByText('finding-2')).not.toBeInTheDocument();
    });
});
