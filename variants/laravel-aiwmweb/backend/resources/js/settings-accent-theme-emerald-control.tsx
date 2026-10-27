import React, { useState } from 'react';
import type { FrontendContext } from './core';
import { useLocale } from './i18n';

export const SETTINGS_SET_EMERALD_OPERATION_ID = 'AIMW-CONT-566A1899F1';
export const SETTINGS_THEME_STORAGE_KEY = 'aiwm-color-theme';

function readEmeraldActive(): boolean {
    try {
        return window.localStorage.getItem(SETTINGS_THEME_STORAGE_KEY) === 'emerald';
    } catch {
        return false;
    }
}

export function applyEmeraldTheme() {
    document.documentElement.dataset.theme = 'emerald';
    try {
        window.localStorage.setItem(SETTINGS_THEME_STORAGE_KEY, 'emerald');
    } catch {
        // Match the source appTheme runtime: apply the session theme even when persistence is unavailable.
    }
}

export function SettingsAccentThemeEmeraldControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const canViewSettings = context.permissions.includes('*') || context.permissions.includes('tenant.view');
    const [active, setActive] = useState<boolean>(() => readEmeraldActive());

    if (!canViewSettings) return null;

    const setEmerald = () => {
        applyEmeraldTheme();
        setActive(true);
    };

    return (
        <section className="toolbar-panel" aria-label={locale === 'ar' ? 'لون النظام' : 'Accent color'}>
            <div>
                <span className="workspace-kicker">ACCENT</span>
                <strong>{locale === 'ar' ? 'لون النظام' : 'Accent color'}</strong>
                <small>{locale === 'ar' ? 'اختر اللون الزمردي لواجهة مساحة العمل' : 'Apply the Emerald workspace accent palette'}</small>
            </div>
            <div className="toolbar-actions">
                <button
                    type="button"
                    className={`btn ${active ? 'primary' : ''}`}
                    aria-pressed={active}
                    data-canonical-operation={SETTINGS_SET_EMERALD_OPERATION_ID}
                    onClick={setEmerald}
                >
                    <span aria-hidden="true">●</span>
                    {locale === 'ar' ? 'زمردي' : 'Emerald'}
                </button>
            </div>
        </section>
    );
}
