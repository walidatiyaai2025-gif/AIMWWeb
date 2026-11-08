import { describe, expect, it } from 'vitest';
import { billingSupportPageUrl } from '../admin-billing-support-pages';

describe('Admin Billing Support server pagination contract', () => {
    const endpoint = '/api/tenants/alpha/billing/admin/subscriptions';

    it('loads explicit authoritative pages with approved page sizes and escaped search', () => {
        expect(billingSupportPageUrl(endpoint, 2, '  alpha@example.test  ', 25))
            .toBe(endpoint + '?page=2&per_page=25&search=alpha%40example.test');
        expect(billingSupportPageUrl(endpoint, 3, '', 100))
            .toBe(endpoint + '?page=3&per_page=100');
    });

    it('keeps unsupported sizes and malicious/out-of-range page values away from the API', () => {
        expect(billingSupportPageUrl(endpoint, Number.MAX_SAFE_INTEGER, '', 999))
            .toBe(endpoint + '?page=1&per_page=25');
        expect(billingSupportPageUrl(endpoint, 0, '', 10))
            .toBe(endpoint + '?page=1&per_page=10');
    });

    it('preserves tenant-bound path and clear-search state without fabricated totals', () => {
        const url = billingSupportPageUrl(endpoint + '?source=admin&search=old', 1, '', 50);
        expect(url).toBe(endpoint + '?source=admin&page=1&per_page=50');
        expect(url).not.toContain('total=');
        expect(url).not.toContain('search=');
    });
});
