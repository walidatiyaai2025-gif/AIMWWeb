import { describe, expect, it } from 'vitest';
import { ApiError } from '../core';
import {
    SITE_ONBOARDING_SAVE_TEST_SYNC_OPERATION_ID,
    canonicalSiteOnboardingEndpoint,
    onboardingRetryTokenFromError,
} from '../site-onboarding-save-test-sync-control';

describe('Site onboarding SaveTestAndSyncAsync terminality', () => {
    it('binds the exact canonical operation to the tenant-scoped sites API', () => {
        expect(SITE_ONBOARDING_SAVE_TEST_SYNC_OPERATION_ID).toBe('AIMW-BILL-2EF6B8A27A');
        expect(canonicalSiteOnboardingEndpoint('/api/tenants/alpha/sites'))
            .toBe('/api/tenants/alpha/sites/onboarding');
        expect(canonicalSiteOnboardingEndpoint('/api/tenants/alpha/sites/99')).toBeNull();
        expect(canonicalSiteOnboardingEndpoint(undefined)).toBeNull();
    });

    it('does not derive onboarding from a caller-owned site identifier', () => {
        const endpoint = canonicalSiteOnboardingEndpoint('/api/tenants/alpha/sites');
        expect(endpoint).not.toContain('/sites/0/');
        expect(endpoint).not.toContain('/sites/99/');
    });

    it('accepts only the server-issued retry token carried in a safe API error payload', () => {
        const error = new ApiError(
            'Connection failed',
            422,
            'http_422',
            {},
            { retry_token: 'server-issued-retry-token' },
        );

        expect(onboardingRetryTokenFromError(error)).toBe('server-issued-retry-token');
        expect(onboardingRetryTokenFromError(new Error('not an API error'))).toBeNull();
    });
});
