import React from 'react';
import { tenantUrl, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const SETTINGS_AI_PROVIDERS_LINK_OPERATION_ID = 'AIMW-AI-8205320842';

export function SettingsAiProvidersLinkControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();

    if (!context.permissions.includes('settings.manage')) return null;

    return (
        <section className="toolbar-panel" aria-label={locale === 'ar' ? 'إعدادات مزودي الذكاء الاصطناعي' : 'AI provider settings navigation'}>
            <div className="toolbar-actions">
                <a
                    className="btn"
                    href={tenantUrl(context.tenant.slug, '/settings/ai-providers')}
                    data-canonical-operation={SETTINGS_AI_PROVIDERS_LINK_OPERATION_ID}
                >
                    <span aria-hidden="true">⚙</span>
                    {locale === 'ar' ? 'مزودو الذكاء الاصطناعي' : 'AI providers'}
                </a>
            </div>
        </section>
    );
}
