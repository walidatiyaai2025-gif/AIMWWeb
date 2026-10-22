import React from 'react';
import { tenantUrl, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const SETTINGS_AI_PROMPTS_LINK_OPERATION_ID = 'AIMW-AI-0D4D60320B';

export function SettingsAiPromptsLinkControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const canManagePrompts = context.permissions.includes('settings.manage');

    if (!canManagePrompts) return null;

    const target = tenantUrl(context.tenant.slug, '/settings/ai-prompts');

    return (
        <section className="toolbar-panel" aria-label={locale === 'ar' ? 'إعدادات الذكاء الاصطناعي' : 'AI settings navigation'}>
            <div className="toolbar-actions">
                <a
                    className="btn"
                    href={target}
                    data-canonical-operation={SETTINGS_AI_PROMPTS_LINK_OPERATION_ID}
                >
                    <span aria-hidden="true">✦</span>
                    {locale === 'ar' ? 'قوالب أوامر الذكاء الاصطناعي' : 'AI prompt templates'}
                </a>
            </div>
        </section>
    );
}
