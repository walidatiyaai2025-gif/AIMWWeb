import React, { useMemo, useState } from 'react';
import { apiRequest, type FrontendContext } from './core';

export type ConfigurationValidationItem = {
    key: string;
    title: string;
    status: 'valid' | 'warning' | 'error';
    value: string;
    message: string;
};

export type ConfigurationValidationReport = {
    operation_id: string;
    checked_at_utc: string;
    critical_count: number;
    warning_count: number;
    items: ConfigurationValidationItem[];
};

const FAILURE_MESSAGE = 'The browser did not confirm a clipboard write. No copy success was reported; you can retry.';

export function formatConfigurationValidationReport(report: ConfigurationValidationReport): string {
    const result = report.critical_count > 0
        ? 'Blocking errors found'
        : report.warning_count > 0
            ? 'No blockers; warnings require review'
            : 'No blockers detected in these checks';

    return [
        'AI WordPress Manager - Configuration Validation',
        `Checked: ${report.checked_at_utc}`,
        `Critical: ${report.critical_count}`,
        `Warnings: ${report.warning_count}`,
        `Result: ${result}`,
        '',
        ...report.items.flatMap((item) => [
            `[${item.status}] ${item.title}: ${item.value}`,
            item.message,
        ]),
    ].join('\n');
}

export function ConfigurationValidationCopyReportControl({ context }: { context: FrontendContext }) {
    const endpoint = context.api['configuration-validation'];
    const [copying, setCopying] = useState(false);
    const [success, setSuccess] = useState(false);
    const [error, setError] = useState('');
    const [report, setReport] = useState<ConfigurationValidationReport | null>(null);

    const disabled = !endpoint || copying;

    const copy = async () => {
        if (disabled) return;
        setCopying(true);
        setSuccess(false);
        setError('');

        try {
            const loaded = report ?? await apiRequest<ConfigurationValidationReport>(endpoint);
            setReport(loaded);

            const clipboard = navigator.clipboard;
            if (!clipboard || typeof clipboard.writeText !== 'function') throw new Error('Clipboard API unavailable');

            await clipboard.writeText(formatConfigurationValidationReport(loaded));
            setSuccess(true);
        } catch {
            setError(FAILURE_MESSAGE);
        } finally {
            setCopying(false);
        }
    };

    const label = useMemo(() => copying ? 'Copying…' : error ? 'Retry copy' : 'Copy report', [copying, error]);

    return (
        <section className="hero-panel" data-canonical-operation="AIMW-BILL-39BB044AF2">
            <div>
                <span className="workspace-kicker">CONFIGURATION</span>
                <h2>Configuration Validation</h2>
                <p>Copy a sanitized bounded validation report without exposing server paths or secrets.</p>
            </div>
            <div>
                <button type="button" className="btn" disabled={disabled} aria-busy={copying} onClick={() => void copy()}>{label}</button>
                {success ? <p role="status">Validation report copied. The browser confirmed the clipboard write.</p> : null}
                {error ? <p role="alert">{error}</p> : null}
            </div>
        </section>
    );
}
