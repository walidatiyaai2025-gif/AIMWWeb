import { describe, expect, it } from 'vitest';
import { SEO_NETWORK_ERROR, safeSeoRequestError } from '../seo-safe-request-error';

describe('SEO generic API error redaction', () => {
    it('classifies authorization, validation, conflicts, limits and upstream failures by status only', () => {
        const cases: Array<[number, string]> = [
            [401, 'sign-in'], [403, 'not authorized'], [404, 'tenant or site'],
            [409, 'source state'], [422, 'validation'], [429, 'rate limit'],
            [500, 'server error'], [502, 'upstream'], [503, 'temporarily unavailable'],
            [504, 'timed out'],
        ];
        for (const [status, reason] of cases) {
            const message = safeSeoRequestError(status);
            expect(message).toContain('HTTP ' + status);
            expect(message).toContain(reason);
            expect(message).not.toMatch(/Bearer|trace|stack|exception|api[_-]?key/i);
        }
    });

    it('does not echo arbitrary provider or transport diagnostics for unknown responses', () => {
        expect(safeSeoRequestError(418)).toContain('HTTP 418');
        expect(safeSeoRequestError(Number.NaN)).toContain('HTTP unknown');
        expect(safeSeoRequestError(900)).toContain('HTTP unknown');
        expect(SEO_NETWORK_ERROR).toContain('connectivity');
        expect(SEO_NETWORK_ERROR).not.toMatch(/token|Bearer|stack|exception/i);
    });
});
