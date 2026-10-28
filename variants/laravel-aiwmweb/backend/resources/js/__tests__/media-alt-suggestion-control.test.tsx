import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { LocaleProvider } from '../i18n';
import type { FrontendContext } from '../core';
import { MediaAltSuggestionControl, MEDIA_ALT_SUGGESTION_OPERATION_ID, mediaSuggestionSiteId } from '../media-alt-suggestion-control';

const context = (changes: Partial<FrontendContext> = {}): FrontendContext => ({
    user: { id: 1, name: 'Editor', email: 'editor@example.test' },
    tenant: { slug: 'alpha', name: 'Alpha' },
    tenants: [{ slug: 'alpha', name: 'Alpha' }],
    permissions: ['content.view', 'content.edit', 'ai.use'],
    connectors: [],
    capabilities: {},
    api: { media: '/api/v1/tenants/alpha/sites/7/media' },
    actions: {},
    ...changes,
});
const row = { id: 4, site_id: 7, title: 'Hero image', mime_type: 'image/jpeg' };
const endpoint = '/api/v1/tenants/alpha/sites/7/media';

function renderControl(overrides: Partial<FrontendContext> = {}, onSuggestion?: (value: string) => void) {
    return render(<LocaleProvider><MediaAltSuggestionControl context={context(overrides)} row={row} endpoint={endpoint} onSuggestion={onSuggestion} /></LocaleProvider>);
}

afterEach(() => vi.unstubAllGlobals());

describe('AIMW-SYNC-21483C88FA MediaManager SuggestAltClickedAsync', () => {
    it('exposes exactly the canonical source action for authorized tenant-owned images', async () => {
        const onSuggestion = vi.fn();
        const fetchMock = vi.fn(async () => new Response(JSON.stringify({ data: { after: 'Office desk with a laptop', explanation: 'Context-supported description' } }), { status: 200, headers: { 'Content-Type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);
        renderControl({}, onSuggestion);
        const control = screen.getByRole('button', { name: 'Suggest alt text' });
        expect(control.closest('[data-canonical-operation]')).toHaveAttribute('data-canonical-operation', MEDIA_ALT_SUGGESTION_OPERATION_ID);
        fireEvent.click(control);
        fireEvent.change(screen.getByLabelText('Image context'), { target: { value: 'Desk photo with a laptop' } });
        fireEvent.click(screen.getByRole('button', { name: 'Generate suggestion' }));
        await waitFor(() => expect(screen.getByLabelText('Review suggested alt text')).toHaveValue('Office desk with a laptop'));
        expect(fetchMock).toHaveBeenCalledTimes(1);
        const [url, request] = fetchMock.mock.calls[0] as [string, RequestInit];
        expect(url).toBe('/api/tenants/alpha/ai-center/generate');
        expect(JSON.parse(String(request.body))).toMatchObject({ site_id: 7 });
        fireEvent.click(screen.getByRole('button', { name: 'Use in draft' }));
        expect(onSuggestion).toHaveBeenCalledWith('Office desk with a laptop');
    });

    it('fails closed across tenants, sites, permissions and nonimage rows', () => {
        expect(mediaSuggestionSiteId(context(), '/api/v1/tenants/beta/sites/7/media', row)).toBeNull();
        expect(mediaSuggestionSiteId(context(), endpoint, { ...row, site_id: 8 })).toBeNull();
        expect(mediaSuggestionSiteId(context({ tenant: { slug: '../foreign', name: 'Foreign' } }), endpoint, row)).toBeNull();
        const { rerender } = renderControl({ permissions: ['content.view', 'ai.use'] });
        expect(screen.queryByRole('button', { name: 'Suggest alt text' })).not.toBeInTheDocument();
        rerender(<LocaleProvider><MediaAltSuggestionControl context={context()} row={{ ...row, mime_type: 'application/pdf' }} endpoint={endpoint} /></LocaleProvider>);
        expect(screen.queryByRole('button', { name: 'Suggest alt text' })).not.toBeInTheDocument();
    });

    it('shows an authoritative 404 / foreign tenant provider failure instead of inventing a suggestion', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ message: 'Site not found' }), { status: 404, headers: { 'Content-Type': 'application/json' } })));
        renderControl();
        fireEvent.click(screen.getByRole('button', { name: 'Suggest alt text' }));
        fireEvent.click(screen.getByRole('button', { name: 'Generate suggestion' }));
        await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('Site not found'));
        expect(screen.queryByLabelText('Review suggested alt text')).not.toBeInTheDocument();
    });
});
