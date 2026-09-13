import React from 'react';
import { Link } from 'react-router-dom';
import { tenantUrl, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const AI_USAGE_AI_CENTER_OPERATION_ID = 'AIMW-AI-411CFF23F3';

const canOpenAiCenter = (context: FrontendContext): boolean =>
    context.permissions.includes('tenant.view')
    && context.permissions.includes('ai.viewUsage')
    && context.permissions.includes('ai.use');

export function AiUsageAiCenterLinkControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();

    if (!canOpenAiCenter(context)) return null;

    return (
        <nav className="toolbar-panel" aria-label={locale === 'ar' ? 'تنقل استخدام الذكاء الاصطناعي' : 'AI usage navigation'}>
            <div className="toolbar-actions">
                <Link
                    className="btn"
                    to={tenantUrl(context.tenant.slug, '/ai-center')}
                    data-canonical-operation={AI_USAGE_AI_CENTER_OPERATION_ID}
                >
                    <span aria-hidden="true">✦</span>
                    {locale === 'ar' ? 'مركز الذكاء الاصطناعي' : 'AI Center'}
                </Link>
            </div>
        </nav>
    );
}
