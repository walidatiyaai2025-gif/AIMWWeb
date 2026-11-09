import { describe, expect, it, vi } from 'vitest';
import { assertValidWordPressCollection, readWorkspaceCollectionResponse } from '../wordpress-collection-contract';

const routes = ['posts', 'pages', 'media', 'comments', 'taxonomy', 'categories', 'tags', 'wp-users'];

describe('WordPress collection GET fail-closed contract', () => {
    it.each(routes)('accepts genuine empty and paginated %s data', (route) => {
        expect(() => assertValidWordPressCollection(route, [])).not.toThrow();
        expect(() => assertValidWordPressCollection(route, {
            data: [], total: 0, current_page: 1, last_page: 1,
        })).not.toThrow();
        expect(() => assertValidWordPressCollection(route, {
            items: [{ id: 2 }], meta: { total: 1, current_page: 1, last_page: 1 },
        })).not.toThrow();
    });

    it.each(routes)('rejects malformed HTTP 200 %s payloads instead of reporting no rows', async (route) => {
        const malformed = [
            null, {}, { message: 'Server error' }, { data: null }, { data: {} },
            { data: [null] }, { data: ['row'] }, { items: { id: 1 } },
            { data: [], success: false }, { data: [], error: 'remote_failed' },
            { data: [], total: -1 }, { data: [], current_page: 0 },
            { data: [], last_page: 'next' }, { data: [], meta: [] },
            { data: [], current_page: 3, last_page: 2 },
            { data: [], page: 4, lastPage: 3 },
            { data: [], meta: { current_page: 2, last_page: 1 } },
        ];
        for (const payload of malformed) {
            const transport = vi.fn().mockResolvedValue(payload);
            await expect(readWorkspaceCollectionResponse(route, '/api/tenant/wordpress', transport))
                .rejects.toThrow('WordPress collection response was invalid');
            expect(transport).toHaveBeenCalledWith('/api/tenant/wordpress');
        }
    });

    it.each(routes)('refuses untrusted WordPress pagination authority and page sizes for %s', async (route) => {
        const malformed = [
            { data: [{ id: 1 }], total: 100, pagination_authoritative: 'false' },
            { data: [{ id: 1 }], total: 100, pagination_authoritative: 0 },
            { data: [{ id: 1 }], total: 100, pagination_authoritative: null },
            { data: [{ id: 1 }], total: 100, meta: { pagination_authoritative: 'false' } },
            { data: [{ id: 1 }], per_page: '25' },
            { data: [{ id: 1 }], per_page: 0 },
            { data: [{ id: 1 }], per_page: -1 },
            { data: [{ id: 1 }], per_page: 101 },
            { data: [{ id: 1 }], per_page: 1.25 },
            { data: [{ id: 1 }], per_page: Number.MAX_SAFE_INTEGER + 1 },
            { data: [{ id: 1 }], meta: { per_page: '50' } },
            { data: [{ id: 1 }], meta: { per_page: 101 } },
        ];
        for (const payload of malformed) {
            const transport = vi.fn().mockResolvedValue(payload);
            await expect(readWorkspaceCollectionResponse(route, '/wp/collection', transport))
                .rejects.toThrow('WordPress collection response was invalid');
            expect(transport).toHaveBeenCalledTimes(1);
            expect(transport).toHaveBeenCalledWith('/wp/collection');
        }
    });

    it.each(routes)('accepts real WordPress pagination flags and bounded sizes for %s', (route) => {
        for (const flag of [true, false] as const) {
            expect(() => assertValidWordPressCollection(route, {
                data: [{ id: 7 }], total: 1, current_page: 1, last_page: 1,
                pagination_authoritative: flag, per_page: 25, meta: { per_page: 25 },
            })).not.toThrow();
        }
        // A cursor-only API may omit last_page, and a last-page response can
        // legitimately contain no rows after a concurrent deletion.
        expect(() => assertValidWordPressCollection(route, {
            data: [], current_page: 4,
        })).not.toThrow();
        expect(() => assertValidWordPressCollection(route, {
            data: [], current_page: 2, last_page: 2,
        })).not.toThrow();
        // Legacy endpoints may legitimately omit authority and page size entirely.
        expect(() => assertValidWordPressCollection(route, {
            data: [{ id: 7 }], total: 1, current_page: 1, last_page: 1,
        })).not.toThrow();
    });

    it('does not widen read validation to unrelated dashboard and settings contracts', async () => {
        const payload = { status: 'operational' };
        const transport = vi.fn().mockResolvedValue(payload);
        await expect(readWorkspaceCollectionResponse('system-health', '/health', transport))
            .resolves.toEqual(payload);
    });

    it('preserves transport failures and never invents cached rows', async () => {
        const transport = vi.fn().mockRejectedValue(new Error('HTTP 502'));
        await expect(readWorkspaceCollectionResponse('posts', '/posts', transport))
            .rejects.toThrow('HTTP 502');
        expect(transport).toHaveBeenCalledTimes(1);
    });

    it('returns the authoritative payload unchanged on valid reads', async () => {
        const payload = { data: [{ id: 1, title: 'A' }], total: 1, current_page: 1, last_page: 1 };
        const transport = vi.fn().mockResolvedValue(payload);
        expect(await readWorkspaceCollectionResponse('posts', '/posts', transport)).toBe(payload);
    });
});
