import React, { useState } from 'react';
import { apiRequest, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const AI_CENTER_GENERATE_OPERATION_ID = 'AIMW-AI-DDB072FE15';

type PromptOption = { key: string; title?: string };
type SiteOption = { id: number; name: string };

type GeneratedSuggestion = {
    operation_id: string;
    before: string;
    after: string;
    explanation: string;
    confidence: number;
    affected_fields: string[];
    provider: string;
    model: string;
    correlation_id: string;
    prompt_key?: string | null;
    site_id?: number | null;
};

type GenerateEnvelope = { data: GeneratedSuggestion };

export function AiCenterGenerateControl({
    context,
    prompts,
    sites,
}: {
    context: FrontendContext;
    prompts: PromptOption[];
    sites: SiteOption[];
}) {
    const { locale } = useLocale();
    const isArabic = locale === 'ar';
    const canUseAi = context.permissions.includes('ai.use') || context.permissions.includes('*');
    const [content, setContent] = useState('');
    const [promptKey, setPromptKey] = useState('');
    const [model, setModel] = useState('');
    const [temperature, setTemperature] = useState(0.2);
    const [maxTokens, setMaxTokens] = useState(1500);
    const [siteId, setSiteId] = useState('');
    const [working, setWorking] = useState(false);
    const [error, setError] = useState('');
    const [suggestion, setSuggestion] = useState<GeneratedSuggestion | null>(null);
    const [history, setHistory] = useState<GeneratedSuggestion[]>([]);

    if (!canUseAi) return null;

    const generate = async () => {
        if (!content.trim() || working) return;
        setWorking(true);
        setError('');
        setSuggestion(null);
        try {
            const result = await apiRequest<GenerateEnvelope>(
                `/api/tenants/${encodeURIComponent(context.tenant.slug)}/ai-center/generate`,
                {
                    method: 'POST',
                    body: JSON.stringify({
                        content,
                        prompt_key: promptKey || null,
                        model: model || null,
                        temperature,
                        max_output_tokens: maxTokens,
                        site_id: siteId ? Number(siteId) : null,
                    }),
                },
            );
            setSuggestion(result.data);
            setHistory((current) => [result.data, ...current].slice(0, 10));
        } catch (reason) {
            setError(reason instanceof Error ? reason.message : (isArabic ? 'فشل إنشاء الاقتراح.' : 'Suggestion generation failed.'));
        } finally {
            setWorking(false);
        }
    };

    return (
        <section className="panel workspace-stack" data-testid="ai-center-generate" data-canonical-operation={AI_CENTER_GENERATE_OPERATION_ID}>
            <header className="panel-header">
                <div>
                    <span className="workspace-kicker">AI COMPOSER</span>
                    <h2>{isArabic ? 'إنشاء اقتراح' : 'Generate suggestion'}</h2>
                </div>
                <span className="tenant-badge">{working ? (isArabic ? 'يعمل' : 'Working') : (isArabic ? 'خامل' : 'Idle')}</span>
            </header>

            <div className="form-grid">
                <label>
                    <span>{isArabic ? 'مفتاح القالب' : 'Prompt key'}</span>
                    <select aria-label={isArabic ? 'مفتاح القالب' : 'Prompt key'} value={promptKey} onChange={(event) => setPromptKey(event.target.value)} disabled={working}>
                        <option value="">{isArabic ? 'بدون قالب' : 'No template'}</option>
                        {prompts.map((prompt) => <option key={prompt.key} value={prompt.key}>{prompt.title || prompt.key}</option>)}
                    </select>
                </label>
                <label>
                    <span>{isArabic ? 'النموذج الاختياري' : 'Optional model'}</span>
                    <input aria-label={isArabic ? 'النموذج الاختياري' : 'Optional model'} value={model} onChange={(event) => setModel(event.target.value)} disabled={working} />
                </label>
                <label>
                    <span>{isArabic ? 'الحرارة' : 'Temperature'}: {temperature.toFixed(1)}</span>
                    <input aria-label={isArabic ? 'الحرارة' : 'Temperature'} type="range" min="0" max="1" step="0.1" value={temperature} onChange={(event) => setTemperature(Number(event.target.value))} disabled={working} />
                </label>
                <label>
                    <span>{isArabic ? 'الحد الأقصى للرموز' : 'Max output tokens'}</span>
                    <input aria-label={isArabic ? 'الحد الأقصى للرموز' : 'Max output tokens'} type="number" min="100" max="8000" value={maxTokens} onChange={(event) => setMaxTokens(Number(event.target.value))} disabled={working} />
                </label>
                <label>
                    <span>{isArabic ? 'الموقع' : 'Site'}</span>
                    <select aria-label={isArabic ? 'الموقع' : 'Site'} value={siteId} onChange={(event) => setSiteId(event.target.value)} disabled={working}>
                        <option value="">{isArabic ? 'بدون موقع محدد' : 'No specific site'}</option>
                        {sites.map((site) => <option key={site.id} value={site.id}>{site.name}</option>)}
                    </select>
                </label>
            </div>

            <label>
                <span>{isArabic ? 'القيمة الأصلية / المحتوى الحالي' : 'Original value / current content'}</span>
                <textarea
                    aria-label={isArabic ? 'القيمة الأصلية / المحتوى الحالي' : 'Original value / current content'}
                    rows={10}
                    value={content}
                    onChange={(event) => setContent(event.target.value)}
                    disabled={working}
                />
            </label>

            <div className="toolbar-actions">
                <button
                    type="button"
                    className="btn primary"
                    data-canonical-operation={AI_CENTER_GENERATE_OPERATION_ID}
                    disabled={working || !content.trim()}
                    aria-busy={working ? 'true' : 'false'}
                    onClick={() => void generate()}
                >
                    {working ? (isArabic ? 'جارٍ إنشاء الاقتراح…' : 'Generating suggestion…') : (isArabic ? 'إنشاء اقتراح' : 'Generate suggestion')}
                </button>
            </div>

            {error ? <div className="state-panel danger" role="alert"><strong>{isArabic ? 'فشل إنشاء الاقتراح' : 'Suggestion generation failed'}</strong><p>{error}</p></div> : null}
            {suggestion ? (
                <article className="workspace-stack" data-testid="ai-generated-suggestion">
                    <section className="panel">
                        <span className="workspace-kicker">SUGGESTION</span>
                        <p>{suggestion.after}</p>
                    </section>
                    <dl className="contract-details">
                        <div><dt>{isArabic ? 'السبب' : 'Rationale'}</dt><dd>{suggestion.explanation}</dd></div>
                        <div><dt>{isArabic ? 'الثقة' : 'Confidence'}</dt><dd>{Math.round(suggestion.confidence * 100)}%</dd></div>
                        <div><dt>{isArabic ? 'الحقول المتأثرة' : 'Affected fields'}</dt><dd>{suggestion.affected_fields.join(', ') || '—'}</dd></div>
                        <div><dt>{isArabic ? 'المزود / النموذج' : 'Provider / model'}</dt><dd>{suggestion.provider} / {suggestion.model}</dd></div>
                    </dl>
                </article>
            ) : null}

            {history.length ? (
                <section aria-label={isArabic ? 'اقتراحات الجلسة' : 'Session suggestions'}>
                    <div className="panel-header"><strong>{isArabic ? 'اقتراحات الجلسة' : 'Session suggestions'}</strong><span className="count-badge">{history.length}</span></div>
                    <div className="workspace-stack">
                        {history.map((item) => (
                            <button type="button" className="btn" key={item.correlation_id} onClick={() => { setContent(item.before); setSuggestion(item); }}>
                                {item.after.length > 100 ? `${item.after.slice(0, 100)}…` : item.after}
                            </button>
                        ))}
                    </div>
                </section>
            ) : null}
        </section>
    );
}
