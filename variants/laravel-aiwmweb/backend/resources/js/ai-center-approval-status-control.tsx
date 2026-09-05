import React, { useCallback, useEffect, useRef, useState } from 'react';
import { AiCenterApprovalQueueLink } from './ai-center-approval-queue-link';
import { apiRequest, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const AI_CENTER_REFRESH_APPROVAL_STATUS_OPERATION_ID = 'AIMW-AI-168B406674';
export const AI_CENTER_NEW_SESSION_OPERATION_ID = 'AIMW-AI-C7621E276C';

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
    const [promptKey, setPromptKey] = useState('');
    const [content, setContent] = useState('');
    const readEpoch = useRef(0);
    const canRead = context.permissions.includes('ai.use');
    const endpoint = `/api/tenants/${encodeURIComponent(context.tenant.slug)}/ai-center/approval-status`;

    const load = useCallback(async () => {
        if (!canRead) return;
        const epoch = ++readEpoch.current;
        setState('loading');
        setError('');
        try {
            const payload = await apiRequest<ApprovalStatusResponse>(endpoint);
            if (epoch !== readEpoch.current) return;
            setApproval(payload.data ?? null);
            setState('ready');
        } catch (reason) {
            if (epoch !== readEpoch.current) return;
            setError(reason instanceof Error ? reason.message : (locale === 'ar' ? 'تعذر تحديث حالة الموافقة.' : 'Approval status refresh failed.'));
            setState('error');
        }
    }, [canRead, endpoint, locale]);

    useEffect(() => {
        void load();
    }, [load]);

    const clearSession = () => {
        readEpoch.current += 1;
        setPromptKey('');
        setContent('');
        setApproval(null);
        setError('');
        setState('idle');
    };

    if (!canRead) return null;

    const sessionControls = (
        <section className="panel ai-session-controls" aria-label={locale === 'ar' ? 'جلسة مركز الذكاء الاصطناعي' : 'AI Center session'}>
            <header className="panel-header">
                <div>
                    <span className="workspace-kicker">AI WORKSPACE</span>
                    <strong>{locale === 'ar' ? 'الجلسة الحالية' : 'Current session'}</strong>
                </div>
                <button
                    type="button"
                    className="btn"
                    data-canonical-operation={AI_CENTER_NEW_SESSION_OPERATION_ID}
                    onClick={clearSession}
                >
                    <span aria-hidden="true">＋</span>
                    {locale === 'ar' ? 'جلسة جديدة' : 'New session'}
                </button>
            </header>
            <div className="ai-options-grid">
                <label>
                    <span>{locale === 'ar' ? 'مفتاح القالب' : 'Prompt key'}</span>
                    <input value={promptKey} onChange={(event) => setPromptKey(event.target.value)} placeholder="content.rewrite" data-bidi="technical" />
                </label>
                <label className="wide">
                    <span>{locale === 'ar' ? 'القيمة الأصلية / المحتوى الحالي' : 'Original value / current content'}</span>
                    <textarea value={content} onChange={(event) => setContent(event.target.value)} rows={4} />
                </label>
            </div>
        </section>
    );

    const approvalControls = approval ? (
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
            <div className="contract-details" data-approval-id={approval.id}>
                <div><dt>{locale === 'ar' ? 'الموافقة' : 'Approval'}</dt><dd>{approval.id}</dd></div>
                <div><dt>{locale === 'ar' ? 'الحالة' : 'Status'}</dt><dd>{approval.status}</dd></div>
            </div>
            {error ? <p role="alert">{error}</p> : null}
        </section>
    ) : null;

    return <><AiCenterApprovalQueueLink context={context} />{sessionControls}{approvalControls}</>;
}
