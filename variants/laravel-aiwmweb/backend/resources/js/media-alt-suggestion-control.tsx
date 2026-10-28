import React, { useState } from 'react';
import { apiRequest, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const MEDIA_ALT_SUGGESTION_OPERATION_ID = 'AIMW-SYNC-21483C88FA';

type SuggestionEnvelope = { data?: { after?: string; explanation?: string; provider?: string; model?: string } };

/** Site identity is taken from the tenant-qualified Media API, never a free-form URL. */
export function mediaSuggestionSiteId(context: FrontendContext, endpoint: string, row: Record<string, unknown>): number | null {
    const siteId = Number(row.site_id);
    if (!Number.isSafeInteger(siteId) || siteId < 1) return null;
    const slug = context.tenant.slug;
    if (!slug || slug === '.' || slug === '..' || slug.includes('/') || slug.includes('\\')) return null;
    const expected = `/api/v1/tenants/${encodeURIComponent(slug)}/sites/${siteId}/media`;
    if (endpoint !== expected || context.api.media !== expected) return null;
    return siteId;
}

export function MediaAltSuggestionControl({
    context, endpoint, row, onSuggestion,
}: {
    context: FrontendContext;
    endpoint: string;
    row: Record<string, unknown>;
    onSuggestion?: (value: string) => void;
}) {
    const { locale } = useLocale();
    const [expanded, setExpanded] = useState(false);
    const [contextText, setContextText] = useState('');
    const [suggestion, setSuggestion] = useState('');
    const [explanation, setExplanation] = useState('');
    const [working, setWorking] = useState(false);
    const [error, setError] = useState('');
    const siteId = mediaSuggestionSiteId(context, endpoint, row);
    const canEdit = context.permissions.includes('*') || context.permissions.includes('content.edit');
    const canUseAi = context.permissions.includes('*') || context.permissions.includes('ai.use');
    const isImage = String(row.mime_type ?? '').toLowerCase().startsWith('image/');
    const isArabic = locale === 'ar';

    if (!canEdit || !canUseAi || !isImage || siteId === null || !Number.isSafeInteger(Number(row.id))) return null;

    const generate = async () => {
        if (working || !contextText.trim()) return;
        setWorking(true);
        setError('');
        setSuggestion('');
        try {
            const response = await apiRequest<SuggestionEnvelope>(
                `/api/tenants/${encodeURIComponent(context.tenant.slug)}/ai-center/generate`,
                {
                    method: 'POST',
                    body: JSON.stringify({
                        site_id: siteId,
                        content: `Write accurate, concise image alt text (maximum 500 characters) in ${locale}. Only describe the supplied context; do not invent image details. Image title: ${String(row.title ?? '').slice(0, 300)}. Context: ${contextText.trim()}`,
                        max_output_tokens: 300,
                        temperature: 0.2,
                    }),
                },
            );
            const proposed = response.data?.after?.trim() ?? '';
            if (!proposed || proposed.length > 500) throw new Error('The AI provider returned an invalid alt-text suggestion.');
            setSuggestion(proposed);
            setExplanation(response.data?.explanation ?? '');
        } catch (reason) {
            setError(reason instanceof Error ? reason.message : 'Alt-text generation failed.');
        } finally {
            setWorking(false);
        }
    };

    return (
        <div className="media-alt-suggestion" data-canonical-operation={MEDIA_ALT_SUGGESTION_OPERATION_ID}>
            <button type="button" className="btn" onClick={() => {
                setExpanded((value) => !value);
                setError('');
                if (!expanded && !contextText) setContextText(String(row.title ?? ''));
            }} disabled={working}>
                {isArabic ? 'اقتراح Alt Text' : 'Suggest alt text'}
            </button>
            {expanded ? (
                <section className="panel workspace-stack" aria-label={isArabic ? 'اقتراح Alt Text' : 'Alt-text suggestion'}>
                    <label>
                        <span>{isArabic ? 'سياق الصورة' : 'Image context'}</span>
                        <textarea aria-label={isArabic ? 'سياق الصورة' : 'Image context'} value={contextText} onChange={(e) => setContextText(e.target.value)} disabled={working} maxLength={3000} />
                    </label>
                    <button className="btn primary" type="button" disabled={working || !contextText.trim()} aria-busy={working} onClick={() => void generate()}>
                        {working ? (isArabic ? 'جارٍ الإنشاء…' : 'Generating…') : (isArabic ? 'إنشاء الاقتراح' : 'Generate suggestion')}
                    </button>
                    {error ? <p role="alert">{error}</p> : null}
                    {suggestion ? (
                        <div>
                            <label><span>{isArabic ? 'الاقتراح (غير محفوظ)' : 'Suggestion (not saved)'}</span>
                                <textarea aria-label="Review suggested alt text" readOnly value={suggestion} />
                            </label>
                            {explanation ? <p>{explanation}</p> : null}
                            {onSuggestion ? <button type="button" className="btn" onClick={() => onSuggestion(suggestion)}>
                                {isArabic ? 'استخدام في المسودة' : 'Use in draft'}
                            </button> : null}
                        </div>
                    ) : null}
                </section>
            ) : null}
        </div>
    );
}
