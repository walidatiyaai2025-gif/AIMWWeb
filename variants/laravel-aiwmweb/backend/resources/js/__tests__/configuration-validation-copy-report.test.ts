import { describe, expect, it } from 'vitest';
import {
    buildConfigurationValidationRunRequest,
    formatConfigurationValidationReport,
    RUN_VALIDATION_OPERATION_ID,
    type ConfigurationValidationReport,
} from '../configuration-validation-copy-report';
import { workspaceRoutes } from '../core';

const report: ConfigurationValidationReport = {
    operation_id: 'AIMW-BILL-39BB044AF2',
    checked_at_utc: '2026-10-07T10:00:00Z',
    critical_count: 0,
    warning_count: 1,
    items: [
        { key: 'environment', title: 'Runtime environment', status: 'warning', value: 'Non-production runtime', message: 'The application is not running in the production environment.' },
        { key: 'app-key', title: 'Application encryption key', status: 'valid', value: 'Configured', message: 'The application encryption key is configured.' },
    ],
};

describe('configuration validation controls', () => {
    it('formats only the sanitized validation read model', () => {
        const formatted = formatConfigurationValidationReport(report);
        expect(formatted).toContain('AI WordPress Manager - Configuration Validation');
        expect(formatted).toContain('Warnings: 1');
        expect(formatted).toContain('[warning] Runtime environment: Non-production runtime');
        expect(formatted).not.toContain('/var/');
        expect(formatted).not.toContain('APP_KEY=');
    });

    it('binds RunValidation to the active-tenant POST command and canonical operation', () => {
        const request = buildConfigurationValidationRunRequest('/tenants/alpha/configuration-validation/run');
        expect(request).toEqual({
            url: '/tenants/alpha/configuration-validation/run',
            init: { method: 'POST' },
        });
        expect(RUN_VALIDATION_OPERATION_ID).toBe('AIMW-BILL-EE0BEAAC55');

        const route = workspaceRoutes.find((candidate) => candidate.key === 'configuration-validation');
        expect(route?.controls).toContain('configuration.run-validation');
        expect(route?.controls).toContain('configuration.copy-report');
    });
});
