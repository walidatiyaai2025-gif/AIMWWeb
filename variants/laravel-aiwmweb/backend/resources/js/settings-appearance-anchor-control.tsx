import React from 'react';
import type { FrontendContext } from './core';
import { useLocale } from './i18n';

export const SETTINGS_APPEARANCE_ANCHOR_OPERATION_ID = 'AIMW-CONT-918BE3DBF1';
export const SETTINGS_APPEARANCE_TARGET_ID = 'appearance';

export function SettingsAppearanceAnchorControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const canViewSettings = context.permissions.includes('*') || context.permissions.includes('tenant.view');

    if (!canViewSettings) return null;

    return (
        <nav className="toolbar-panel" aria-label={locale === 'ar' ? 'أقسام الإعدادات' : 'Settings sections'}>
            <div>
                <span className="workspace-kicker">APPEARANCE</span>
                <strong>{locale === 'ar' ? 'المظهر والألوان' : 'Appearance and colors'}</strong>
                <small>{locale === 'ar' ? 'الوضع والثيم' : 'Mode and theme'}</small>
            </div>
            <div className="toolbar-actions">
                <a
                    className="btn"
                    href={'#' + SETTINGS_APPEARANCE_TARGET_ID}
                    aria-controls={SETTINGS_APPEARANCE_TARGET_ID}
                    data-canonical-operation={SETTINGS_APPEARANCE_ANCHOR_OPERATION_ID}
                >
                    <span aria-hidden="true">◐</span>
                    {locale === 'ar' ? 'المظهر والألوان' : 'Appearance and colors'}
                </a>
            </div>
        </nav>
    );
}
