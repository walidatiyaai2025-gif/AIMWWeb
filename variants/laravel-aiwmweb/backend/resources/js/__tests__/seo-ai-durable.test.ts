import { describe, expect, it } from 'vitest';
import {
    acceptedSeoBatch, completedSeoProposal, parseSeoBatchStatus,
    safeSeoAiError, seoBatchPath, validSavedSeoBatch,
} from '../seo-ai-durable';

const id = '123e4567-e89b-42d3-a456-426614174003';
const path = seoBatchPath('wordpress', id);
const batch = { id, statusUrl: path, findingId: 42, state: 'pending' };

describe('SEO durable AI boundary', () => {
    it('accepts only exact same-tenant, same-site immutable 202 status URLs', () => {
        expect(acceptedSeoBatch({
            data: { id, status_url: path, site_id: 7, state: 'pending' },
            status_url: path,
        }, 'wordpress', 7, 42)).toEqual(batch);
        const candidates = [
            { data: { id, status_url: path, site_id: 8, state: 'pending' }, status_url: path },
            { data: { id, status_url: path, site_id: 7, state: 'pending' }, status_url: 'https://foreign.example/a' },
            { data: { id, status_url: '/api/tenants/other/ai-batches/' + id, site_id: 7, state: 'pending' }, status_url: '/api/tenants/other/ai-batches/' + id },
            { data: { id: 'bad', status_url: path, site_id: 7, state: 'pending' }, status_url: path },
            { data: { id, status_url: path, site_id: 7, state: 'unknown' }, status_url: path },
        ];
        for (const value of candidates) expect(() => acceptedSeoBatch(value, 'wordpress', 7, 42)).toThrow();
        expect(validSavedSeoBatch({ ...batch, statusUrl: 'https://foreign.example' }, 'wordpress', 7)).toBeNull();
        expect(validSavedSeoBatch({ ...batch, findingId: 0 }, 'wordpress', 7)).toBeNull();
        expect(validSavedSeoBatch(batch, 'wordpress', 7)).toEqual(batch);
    });

    it('requires real non-empty explicit-approval proposal fields', () => {
        expect(completedSeoProposal({ proposal: { seo_title: 'Safe' }, requires_approval: true }))
            .toEqual({ seo_title: 'Safe' });
        for (const candidate of [
            { proposal: {}, requires_approval: true },
            { proposal: { seo_title: 'Unapproved' }, requires_approval: false },
            { proposal: { secret_token: 'unsafe' }, requires_approval: true },
            { proposal: ['unsafe'], requires_approval: true },
            { proposal: null, requires_approval: true },
        ]) expect(() => completedSeoProposal(candidate)).toThrow();
    });

    it('rejects mismatched status ownership and unknown states', () => {
        expect(parseSeoBatchStatus({
            data: { id, site_id: 7, items: [{ state: 'needs_reconciliation', error_code: 'quOtaSecret Bearer' }] },
        }, batch, 7)).toEqual({ state: 'needs_reconciliation', code: null });
        for (const candidate of [
            { data: { id, site_id: 8, items: [{ state: 'pending' }] } },
            { data: { id: 'foreign', site_id: 7, items: [{ state: 'pending' }] } },
            { data: { id, site_id: 7, items: [] } },
            { data: { id, site_id: 7, items: [{ state: 'super_secret' }] } },
        ]) expect(() => parseSeoBatchStatus(candidate, batch, 7)).toThrow();
    });

    it('maps 401 403 422 429 500 504 into safe HTTP-only errors', () => {
        for (const code of [401, 403, 422, 429, 500, 504]) {
            const message = safeSeoAiError(code);
            expect(message).toContain('HTTP ' + code);
            expect(message).not.toContain('Bearer');
        }
    });
});
