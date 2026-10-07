import { describe, expect, it } from 'vitest';
import { formatConfigurationValidationReport, type ConfigurationValidationReport } from '../configuration-validation-copy-report';

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

describe('configuration validation copy report', () => {
    it('formats only the sanitized validation read model', () => {
        const formatted = formatConfigurationValidationReport(report);
        expect(formatted).toContain('AI WordPress Manager - Configuration Validation');
        expect(formatted).toContain('Warnings: 1');
        expect(formatted).toContain('[warning] Runtime environment: Non-production runtime');
        expect(formatted).not.toContain('/var/');
        expect(formatted).not.toContain('APP_KEY=');
    });
});
