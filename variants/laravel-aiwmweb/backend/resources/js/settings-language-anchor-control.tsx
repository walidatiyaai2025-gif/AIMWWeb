import React from 'react';
import type { FrontendContext } from './core';
import { useLocale } from './i18n';

export const SETTINGS_LANGUAGE_ANCHOR_OPERATION_ID = 'AIMW-CONT-041B5ED720';

export function SettingsLanguageAnchorControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const canViewSettings = context.permissions.includes('*') || context.permissions.includes('tenant.view');

    if (!canViewSettings) return null;

    return (
        <nav className="toolbar-panel" aria-label={locale === 'ar' ? 'أقسام الإعدادات' : 'Settings sections'}>
            <div>
                <span className="workspace-kicker">LANGUAGE</span>
                <strong>{locale === 'ar' ? 'اللغة والاتجاه' : 'Language and direction'}</strong>
                <small>{locale === 'ar' ? 'العربية والإنجليزية' : 'Arabic and English'}</small>
            </div>
            <div className="toolbar-actions">
                <a
                    className="btn"
                    href="#language"
                    aria-controls="language"
                    data-canonical-operation={SETTINGS_LANGUAGE_ANCHOR_OPERATION_ID}
                >
                    <span aria-hidden="true">文</span>
                    {locale === 'ar' ? 'اللغة والاتجاه' : 'Language and direction'}
                </a>
            </div>
        </nav>
    );
}
