import React from 'react';
import { Link } from 'react-router-dom';
import { tenantUrl, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const AI_CENTER_AI_USAGE_OPERATION_ID = 'AIMW-AI-331ED9D5EE';

const canOpenAiUsage = (context: FrontendContext): boolean =>
    context.permissions.includes('tenant.view')
    && context.permissions.includes('ai.use')
    && context.permissions.includes('ai.viewUsage');

export function AiCenterAiUsageLinkControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();

    if (!canOpenAiUsage(context)) return null;

    return (
        <nav className="toolbar-panel" aria-label={locale === 'ar' ? 'تنقل مركز الذكاء الاصطناعي' : 'AI Center navigation'}>
            <div className="toolbar-actions">
                <Link
                    className="btn"
                    to={tenantUrl(context.tenant.slug, '/module/ai-usage')}
                    data-canonical-operation={AI_CENTER_AI_USAGE_OPERATION_ID}
                >
                    <span aria-hidden="true">▥</span>
                    {locale === 'ar' ? 'استخدام وتكلفة الذكاء' : 'AI Usage & Cost'}
                </Link>
            </div>
        </nav>
    );
}
