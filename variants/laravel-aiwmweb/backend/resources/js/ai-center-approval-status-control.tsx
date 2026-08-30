import React, { useCallback, useEffect, useState } from 'react';
import { apiRequest, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const AI_CENTER_REFRESH_APPROVAL_STATUS_OPERATION_ID = 'AIMW-AI-168B406674';

type ApprovalStatus = {
    id: number;
    status: string;
    decided_at?: string | null;
    updated_at?: string | null;
};

type ApprovalStatusResponse = { data: ApprovalStatus | null };

type LoadState = 'idle' | 'loading' | 'ready' | 'error';

export function AiCenterApprovalStatusControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const [approval, setApproval] = useState<ApprovalStatus | null>(null);
    const [state, setState] = useState<LoadState>('idle');
    const [error, setError] = useState('');
    const canRead = context.permissions.includes('ai.use');
    const endpoint = `/api/tenants/${encodeURIComponent(context.tenant.slug)}/ai-center/approval-status`;

    const load = useCallback(async () => {
        if (!canRead) return;
        setState('loading');
        setError('');
        try {
            const payload = await apiRequest<ApprovalStatusResponse>(endpoint);
            setApproval(payload.data ?? null);
            setState('ready');
        } catch (reason) {
            setError(reason instanceof Error ? reason.message : (locale === 'ar' ? 'تعذر تحديث حالة الموافقة.' : 'Approval status refresh failed.'));
            setState('error');
        }
    }, [canRead, endpoint, locale]);

    useEffect(() => {
        void load();
    }, [load]);

    if (!canRead) return null;
    if (state === 'loading' && approval === null) return null;
    if (state === 'ready' && approval === null) return null;
    if (state === 'error' && approval === null) return null;

    return (
        <section className="panel ai-approval-status-control" aria-label={locale === 'ar' ? 'حالة موافقة مركز الذكاء الاصطناعي' : 'AI Center approval status'}>
            <header className="panel-header">
                <div>
                    <span className="workspace-kicker">GOVERNANCE</span>
                    <strong>{locale === 'ar' ? 'حالة الموافقة' : 'Approval state'}</strong>
                </div>
                <button
                    type="button"
                    className="btn"
                    data-canonical-operation={AI_CENTER_REFRESH_APPROVAL_STATUS_OPERATION_ID}
                    disabled={state === 'loading'}
                    onClick={() => void load()}
                >
                    {state === 'loading' ? (locale === 'ar' ? 'جارٍ التحديث…' : 'Refreshing…') : (locale === 'ar' ? 'تحديث الحالة' : 'Refresh state')}
                </button>
            </header>
            {approval ? (
                <div className="contract-details" data-approval-id={approval.id}>
                    <div><dt>{locale === 'ar' ? 'الموافقة' : 'Approval'}</dt><dd>{approval.id}</dd></div>
                    <div><dt>{locale === 'ar' ? 'الحالة' : 'Status'}</dt><dd>{approval.status}</dd></div>
                </div>
            ) : null}
            {error ? <p role="alert">{error}</p> : null}
        </section>
    );
}
