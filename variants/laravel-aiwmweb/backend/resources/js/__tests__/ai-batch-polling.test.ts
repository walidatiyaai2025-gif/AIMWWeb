import { afterEach, describe, expect, it, vi } from 'vitest';
import { apiRequest } from '../core';

const reply = (status: number, payload: unknown) => ({ status, ok: status < 400, headers: new Headers({ 'content-type': 'application/json' }), json: async () => payload });
afterEach(() => { vi.useRealTimers(); vi.unstubAllGlobals(); });
describe('durable AI polling', () => {
    it('polls without another generation and reads the saved domain result', async () => {
        vi.useFakeTimers();
        const fetch = vi.fn()
            .mockResolvedValueOnce(reply(202, { status_url: '/api/tenants/alpha/ai-batches/id' }))
            .mockResolvedValueOnce(reply(200, { data: { id: 'id', state: 'completed', completed: 1, total: 1 } }))
            .mockResolvedValueOnce(reply(200, { data: { after: 'saved' } }));
        vi.stubGlobal('fetch', fetch);
        const response = apiRequest('/generate', { method: 'POST', body: '{}' });
        await vi.advanceTimersByTimeAsync(5000);
        expect(await response).toEqual({ data: { after: 'saved' } });
        expect((fetch.mock.calls[2][1].headers as Headers).get('X-AIMW-AI-BATCH')).toBe('id');
        expect(fetch.mock.calls.map((call) => call[0])).toEqual(['/generate', '/api/tenants/alpha/ai-batches/id', '/generate']);
    });
    it('stops polling at a quota pause and preserves the status URL', async () => {
        vi.useFakeTimers();
        const fetch = vi.fn()
            .mockResolvedValueOnce(reply(202, { status_url: '/api/tenants/alpha/ai-batches/id' }))
            .mockResolvedValueOnce(reply(200, { data: { id: 'id', state: 'paused', completed: 17, total: 50 } }));
        vi.stubGlobal('fetch', fetch);
        const response = expect(apiRequest('/generate', { method: 'POST' })).rejects.toThrow('17/50');
        await vi.advanceTimersByTimeAsync(5000);
        await response;
        expect(fetch).toHaveBeenCalledTimes(2);
    });
    it('preserves unrelated asynchronous API contracts without AI polling', async () => {
        const payload = { status_url: '/api/tenants/alpha/operations/one', data: { state: 'queued' } };
        const fetch = vi.fn().mockResolvedValueOnce(reply(202, payload));
        vi.stubGlobal('fetch', fetch);
        expect(await apiRequest('/operations', { method: 'POST' })).toEqual(payload);
        expect(fetch).toHaveBeenCalledTimes(1);
    });

});
