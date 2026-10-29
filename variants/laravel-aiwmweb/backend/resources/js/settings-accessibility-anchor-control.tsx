import React from 'react';
import type { FrontendContext } from './core';
import { useLocale } from './i18n';

export const SETTINGS_ACCESSIBILITY_ANCHOR_OPERATION_ID = 'AIMW-CONT-6AB0ED6C83';
export const SETTINGS_ACCESSIBILITY_TARGET_ID = 'accessibility';

export function SettingsAccessibilityAnchorControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const canViewSettings = context.permissions.includes('*') || context.permissions.includes('tenant.view');

    if (!canViewSettings) return null;

    return (
        <>
            <nav className="toolbar-panel" aria-label={locale === 'ar' ? 'أقسام الإعدادات' : 'Settings sections'}>
                <div>
                    <span className="workspace-kicker">ACCESSIBILITY</span>
                    <strong>{locale === 'ar' ? 'سهولة الاستخدام' : 'Accessibility'}</strong>
                    <small>{locale === 'ar' ? 'الخط والكثافة والحركة' : 'Font, density, and motion'}</small>
                </div>
                <div className="toolbar-actions">
                    <a
                        className="btn"
                        href={'#' + SETTINGS_ACCESSIBILITY_TARGET_ID}
                        aria-controls={SETTINGS_ACCESSIBILITY_TARGET_ID}
                        data-canonical-operation={SETTINGS_ACCESSIBILITY_ANCHOR_OPERATION_ID}
                    >
                        <span aria-hidden="true">Aa</span>
                        {locale === 'ar' ? 'سهولة الاستخدام' : 'Accessibility'}
                    </a>
                </div>
            </nav>

            <section
                id={SETTINGS_ACCESSIBILITY_TARGET_ID}
                className="toolbar-panel"
                aria-labelledby="settings-accessibility-heading"
                data-settings-section="accessibility"
            >
                <div>
                    <span className="workspace-kicker">ACCESSIBILITY</span>
                    <strong id="settings-accessibility-heading">{locale === 'ar' ? 'سهولة الاستخدام والعرض' : 'Accessibility and display'}</strong>
                    <small>
                        {locale === 'ar'
                            ? 'انتقل إلى إعدادات العرض لضبط حجم الخط والكثافة والتباين والحركة.'
                            : 'Use the display accessibility controls to adjust font size, density, contrast, and motion.'}
                    </small>
                </div>
            </section>
        </>
    );
}
