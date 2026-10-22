import { describe, expect, it } from 'vitest';
import {
    SEO_OPERATIONS,
    seoBillingHref,
    type SeoConfig,
} from '../seo-visible-controls';

const config = (overrides: Partial<SeoConfig> = {}): SeoConfig => ({
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
    ...overrides,
});

describe('canonical SEO Manager billing link', () => {
    it('binds the exact operation and active-tenant billing destination', () => {
        expect(SEO_OPERATIONS.billing).toBe('AIMW-BILL-1EA01528A9');
        expect(seoBillingHref(config())).toBe('/tenants/alpha/account/billing');
    });

    it('fails closed when billing permission was not authorized server-side', () => {
        expect(seoBillingHref(config({ can_view_billing: false }))).toBeNull();
    });

    it('rejects a billing destination for another tenant or an absolute provider URL', () => {
        const foreign = config();
        foreign.urls.billing = '/tenants/beta/account/billing';
        expect(seoBillingHref(foreign)).toBeNull();

        const external = config();
        external.urls.billing = 'https://payments.example.test/account/billing';
        expect(seoBillingHref(external)).toBeNull();
    });
});
