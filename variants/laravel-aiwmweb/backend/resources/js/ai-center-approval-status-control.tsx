import React, { useCallback, useEffect, useRef, useState } from 'react';
import { AiCenterAiUsageLinkControl } from './ai-center-ai-usage-link-control';
import { AiCenterApprovalQueueLink } from './ai-center-approval-queue-link';
import { apiRequest, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const AI_CENTER_REFRESH_APPROVAL_STATUS_OPERATION_ID = 'AIMW-AI-168B406674';
export const AI_CENTER_NEW_SESSION_OPERATION_ID = 'AIMW-AI-C7621E276C';
export const AI_CENTER_CLEAR_HISTORY_OPERATION_ID = 'AIMW-AI-746EDAE589';
export const AI_CENTER_COPY_OUTPUT_OPERATION_ID = 'AIMW-AI-B711182657';
export const AI_CENTER_SUBMIT_APPROVAL_OPERATION_ID = 'AIMW-AI-93EBFDE5A1';

type ApprovalStatus = {
    id: number;
    status: string;
    decided_at?: string | null;
    updated_at?: string | null;
};

export type AiCenterSessionHistoryEntry = {
    id: string;
    title: string;
    promptKey: string;
    output: string;
};

export type AiCenterStructuredSuggestion = {
    before: string;
    after: string;
    explanation?: string;
    confidence?: number | null;
    affectedFields?: string[];
    promptKey?: string;
    model?: string;
    siteId?: number | null;
};

type ApprovalStatusResponse = { data: ApprovalStatus | null };
type LoadState = 'idle' | 'loading' | 'ready' | 'error';
type CopyState = 'idle' | 'copying' | 'success' | 'error';
type SubmitState = 'idle' | 'submitting' | 'success' | 'error';
type AiCenterApprovalStatusControlProps = {
    context: FrontendContext;
    initialHistory?: AiCenterSessionHistoryEntry[];
    initialOutput?: string;
    initialSuggestion?: AiCenterStructuredSuggestion | null;
};

export function AiCenterApprovalStatusControl({
    context,
    initialHistory = [],
    initialOutput = '',
    initialSuggestion = null,
}: AiCenterApprovalStatusControlProps) {
    const { locale } = useLocale();
    const [approval, setApproval] = useState<ApprovalStatus | null>(null);
    const [state, setState] = useState<LoadState>('idle');
    const [error, setError] = useState('');
    const [promptKey, setPromptKey] = useState(initialSuggestion?.promptKey ?? '');
    const [content, setContent] = useState(initialSuggestion?.before ?? '');
    const [model] = useState(initialSuggestion?.model ?? '');
    const [output, setOutput] = useState(initialSuggestion?.after ?? initialOutput);
    const [suggestion, setSuggestion] = useState<AiCenterStructuredSuggestion | null>(initialSuggestion);
    const [history, setHistory] = useState<AiCenterSessionHistoryEntry[]>(() => initialHistory.slice(0, 10));
    const [copyState, setCopyState] = useState<CopyState>('idle');
    const [submitState, setSubmitState] = useState<SubmitState>('idle');
    const [submitError, setSubmitError] = useState('');
    const [approvalTitle, setApprovalTitle] = useState('');
    const [operationType, setOperationType] = useState('AI.ContentUpdate');
    const [riskLevel, setRiskLevel] = useState('High');
    const readEpoch = useRef(0);
    const copyEpoch = useRef(0);
    const copying = useRef(false);
    const submittingApproval = useRef(false);
    const canRead = context.permissions.includes('ai.use');
    const tenantSegment = encodeURIComponent(context.tenant.slug);
    const endpoint = `/api/tenants/${tenantSegment}/ai-center/approval-status`;
    const submitEndpoint = `/api/tenants/${tenantSegment}/ai-center/approvals`;

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
        copyEpoch.current += 1;
        copying.current = false;
        submittingApproval.current = false;
        setPromptKey('');
        setContent('');
        setOutput('');
        setSuggestion(null);
        setCopyState('idle');
        setSubmitState('idle');
        setSubmitError('');
        setApproval(null);
        setError('');
        setState('idle');
    };

    const clearHistory = () => {
        setHistory([]);
    };

    const copyOutput = async () => {
        if (copying.current || output.trim() === '') return;

        copying.current = true;
        const epoch = ++copyEpoch.current;
        setCopyState('copying');

        try {
            const clipboard = navigator.clipboard;
            if (!clipboard || typeof clipboard.writeText !== 'function') {
                throw new Error('Clipboard API unavailable');
            }

            await clipboard.writeText(output);
            if (epoch === copyEpoch.current) setCopyState('success');
        } catch {
            if (epoch === copyEpoch.current) setCopyState('error');
        } finally {
            if (epoch === copyEpoch.current) copying.current = false;
        }
    };

    const submitForApproval = async () => {
        if (submittingApproval.current || suggestion === null) return;
        if (!globalThis.crypto || typeof globalThis.crypto.randomUUID !== 'function') {
            setSubmitError(locale === 'ar' ? 'تعذر إنشاء مفتاح طلب آمن.' : 'A secure approval request key could not be generated.');
            setSubmitState('error');
            return;
        }

        submittingApproval.current = true;
        setSubmitState('submitting');
        setSubmitError('');
        try {
            const payload = await apiRequest<ApprovalStatusResponse>(submitEndpoint, {
                method: 'POST',
                body: JSON.stringify({
                    request_key: globalThis.crypto.randomUUID(),
                    site_id: suggestion.siteId ?? null,
                    operation_type: operationType.trim() || 'AI.ContentUpdate',
                    title: approvalTitle.trim() || 'AI content proposal',
                    risk_level: riskLevel,
                    before_content: suggestion.before,
                    after_content: suggestion.after,
                    prompt_key: promptKey,
                    model,
                    explanation: suggestion.explanation ?? '',
                    confidence: suggestion.confidence ?? null,
                    affected_fields: suggestion.affectedFields ?? [],
                }),
            });
            setApproval(payload.data ?? null);
            setState('ready');
            setSubmitState('success');
        } catch (reason) {
            setSubmitError(reason instanceof Error ? reason.message : (locale === 'ar' ? 'فشل إرسال الموافقة.' : 'Approval submission failed.'));
            setSubmitState('error');
        } finally {
            submittingApproval.current = false;
        }
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

    const outputControls = output.trim() !== '' ? (
        <section className="panel ai-output-panel" aria-label={locale === 'ar' ? 'القيمة المقترحة' : 'Proposed value'}>
            <header className="panel-header">
                <div>
                    <span className="workspace-kicker">SUGGESTION</span>
                    <strong>{locale === 'ar' ? 'القيمة المقترحة' : 'Proposed value'}</strong>
                </div>
                <button
                    type="button"
                    className="btn"
                    data-canonical-operation={AI_CENTER_COPY_OUTPUT_OPERATION_ID}
                    disabled={copyState === 'copying'}
                    aria-busy={copyState === 'copying' ? 'true' : 'false'}
                    onClick={() => void copyOutput()}
                >
                    <span aria-hidden="true">⧉</span>
                    {copyState === 'copying'
                        ? (locale === 'ar' ? 'جارٍ النسخ…' : 'Copying…')
                        : (locale === 'ar' ? 'نسخ' : 'Copy')}
                </button>
            </header>
            <article className="ai-generated-output">{output}</article>
            {copyState === 'success' ? (
                <p role="status">{locale === 'ar' ? 'تم نسخ الاقتراح.' : 'Suggestion copied.'}</p>
            ) : null}
            {copyState === 'error' ? (
                <p role="alert">
                    {locale === 'ar'
                        ? 'لم يؤكد المتصفح نسخ الاقتراح إلى الحافظة. لم يتم الإبلاغ عن نجاح النسخ.'
                        : 'The browser did not confirm the clipboard write. No copy success was reported.'}
                </p>
            ) : null}
        </section>
    ) : null;

    const submitControls = suggestion ? (
        <section className="panel ai-approval-box" aria-label={locale === 'ar' ? 'إرسال للموافقة' : 'Submit for approval'}>
            <header className="panel-header">
                <div>
                    <span className="workspace-kicker">GOVERNANCE</span>
                    <strong>{locale === 'ar' ? 'إرسال للموافقة' : 'Submit for approval'}</strong>
                </div>
            </header>
            <div className="ai-approval-grid">
                <label>
                    <span>{locale === 'ar' ? 'درجة الخطورة' : 'Risk level'}</span>
                    <select value={riskLevel} onChange={(event) => setRiskLevel(event.target.value)}>
                        <option value="Low">{locale === 'ar' ? 'منخفض' : 'Low'}</option>
                        <option value="Medium">{locale === 'ar' ? 'متوسط' : 'Medium'}</option>
                        <option value="High">{locale === 'ar' ? 'مرتفع' : 'High'}</option>
                        <option value="Critical">{locale === 'ar' ? 'حرج' : 'Critical'}</option>
                    </select>
                </label>
                <label>
                    <span>{locale === 'ar' ? 'نوع العملية' : 'Operation type'}</span>
                    <input value={operationType} onChange={(event) => setOperationType(event.target.value)} data-bidi="technical" />
                </label>
                <label className="wide">
                    <span>{locale === 'ar' ? 'عنوان طلب الموافقة' : 'Approval title'}</span>
                    <input value={approvalTitle} onChange={(event) => setApprovalTitle(event.target.value)} />
                </label>
            </div>
            <div className="ai-compare-grid">
                <div><small>{locale === 'ar' ? 'قبل' : 'Before'}</small><pre>{suggestion.before}</pre></div>
                <div><small>{locale === 'ar' ? 'بعد' : 'After'}</small><pre>{suggestion.after}</pre></div>
            </div>
            <button
                type="button"
                className="btn primary full"
                data-canonical-operation={AI_CENTER_SUBMIT_APPROVAL_OPERATION_ID}
                disabled={submitState === 'submitting'}
                aria-busy={submitState === 'submitting' ? 'true' : 'false'}
                onClick={() => void submitForApproval()}
            >
                <span aria-hidden="true">✓</span>
                {submitState === 'submitting'
                    ? (locale === 'ar' ? 'جارٍ الإرسال...' : 'Submitting...')
                    : (locale === 'ar' ? 'إرسال إلى قائمة الموافقات' : 'Submit to approval queue')}
            </button>
            {submitState === 'success' ? (
                <p role="status">{locale === 'ar' ? 'تم إرسال الاقتراح المنظم إلى قائمة الموافقات.' : 'Structured proposal submitted to the approval queue.'}</p>
            ) : null}
            {submitState === 'error' ? <p role="alert">{submitError}</p> : null}
        </section>
    ) : null;

    const historyControls = (
        <section className="panel ai-session-history" aria-label={locale === 'ar' ? 'سجل اقتراحات الجلسة' : 'Session suggestions'}>
            <header className="panel-header">
                <div>
                    <span className="workspace-kicker">SESSION HISTORY</span>
                    <strong>{locale === 'ar' ? 'اقتراحات الجلسة' : 'Session suggestions'}</strong>
                </div>
                <button
                    type="button"
                    className="btn"
                    data-canonical-operation={AI_CENTER_CLEAR_HISTORY_OPERATION_ID}
                    disabled={history.length === 0}
                    onClick={clearHistory}
                >
                    {locale === 'ar' ? 'مسح السجل' : 'Clear history'}
                </button>
            </header>
            {history.length === 0 ? (
                <p className="empty-state">{locale === 'ar' ? 'لا توجد اقتراحات في هذه الجلسة بعد.' : 'No session suggestions yet.'}</p>
            ) : (
                <ul className="activity-list" aria-label={locale === 'ar' ? 'اقتراحات محفوظة في الجلسة' : 'In-memory session suggestions'}>
                    {history.map((item) => (
                        <li key={item.id} data-history-id={item.id}>
                            <strong>{item.title}</strong>
                            <span data-bidi="technical">{item.promptKey}</span>
                            <p>{item.output}</p>
                        </li>
                    ))}
                </ul>
            )}
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

    return <><AiCenterAiUsageLinkControl context={context} /><AiCenterApprovalQueueLink context={context} />{sessionControls}{outputControls}{submitControls}{historyControls}{approvalControls}</>;
}
