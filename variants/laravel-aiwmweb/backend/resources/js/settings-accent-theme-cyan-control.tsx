import React, { useState } from 'react';
import type { FrontendContext } from './core';
import { useLocale } from './i18n';

export const SETTINGS_SET_CYAN_OPERATION_ID = 'AIMW-CONT-349017BDF5';
export const SETTINGS_THEME_STORAGE_KEY = 'aiwm-color-theme';

function readCyanActive(): boolean {
    try {
        return window.localStorage.getItem(SETTINGS_THEME_STORAGE_KEY) === 'cyan';
    } catch {
        return false;
    }
}

export function applyCyanTheme() {
    document.documentElement.dataset.theme = 'cyan';
    try {
        window.localStorage.setItem(SETTINGS_THEME_STORAGE_KEY, 'cyan');
    } catch {
        // Match the source appTheme runtime: apply the session theme even when persistence is unavailable.
    }
}

export function SettingsAccentThemeCyanControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const canViewSettings = context.permissions.includes('*') || context.permissions.includes('tenant.view');
    const [active, setActive] = useState<boolean>(() => readCyanActive());

    if (!canViewSettings) return null;

    const setCyan = () => {
        applyCyanTheme();
        setActive(true);
    };

    return (
        <section className="toolbar-panel" aria-label={locale === 'ar' ? 'لون النظام' : 'Accent color'}>
            <div>
                <span className="workspace-kicker">ACCENT</span>
                <strong>{locale === 'ar' ? 'لون النظام' : 'Accent color'}</strong>
                <small>{locale === 'ar' ? 'اختر اللون السماوي لواجهة مساحة العمل' : 'Apply the Cyan workspace accent palette'}</small>
            </div>
            <div className="toolbar-actions">
                <button
                    type="button"
                    className={`btn ${active ? 'primary' : ''}`}
                    aria-pressed={active}
                    data-canonical-operation={SETTINGS_SET_CYAN_OPERATION_ID}
                    onClick={setCyan}
                >
                    <span aria-hidden="true">●</span>
                    {locale === 'ar' ? 'سماوي' : 'Cyan'}
                </button>
            </div>
        </section>
    );
}
