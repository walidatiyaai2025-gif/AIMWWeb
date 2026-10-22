import React from 'react';
import { tenantUrl, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const SETTINGS_AI_PROVIDERS_LINK_OPERATION_ID = 'AIMW-AI-8205320842';
export const SETTINGS_LANGUAGE_TOGGLE_OPERATION_ID = 'AIMW-BILL-1234961B6E';

export function SettingsAiProvidersLinkControl({ context }: { context: FrontendContext }) {
    const { locale, toggleLocale } = useLocale();
    const canViewSettings = context.permissions.includes('*') || context.permissions.includes('tenant.view');
    const canManageProviders = context.permissions.includes('settings.manage');

    if (!canViewSettings && !canManageProviders) return null;

    return (
        <>
            {canViewSettings ? (
                <section className="toolbar-panel" aria-label={locale === 'ar' ? 'إعدادات اللغة' : 'Language settings'}>
                    <div className="toolbar-actions">
                        <button
                            type="button"
                            className="btn primary"
                            data-canonical-operation={SETTINGS_LANGUAGE_TOGGLE_OPERATION_ID}
                            onClick={toggleLocale}
                        >
                            <span aria-hidden="true">🌐</span>
                            {locale === 'ar' ? 'تبديل اللغة' : 'Switch language'}
                        </button>
                    </div>
                </section>
            ) : null}

            {canManageProviders ? (
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
            ) : null}
        </>
    );
}
