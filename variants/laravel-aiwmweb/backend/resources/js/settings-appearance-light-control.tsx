import React, { useEffect, useState } from 'react';
import type { FrontendContext } from './core';
import { useLocale } from './i18n';

export const SETTINGS_SET_LIGHT_OPERATION_ID = 'AIMW-CONT-4961F90970';
export const SETTINGS_SET_AMBER_OPERATION_ID = 'AIMW-CONT-CF4BE4EC3B';
export const APPEARANCE_MODE_EVENT = 'aiwm:appearance-mode';
const MODE_STORAGE_KEY = 'aiwm.mode';
const THEME_STORAGE_KEY = 'aiwm-color-theme';

type AppearanceMode = 'dark' | 'light';

function readMode(): AppearanceMode {
    return window.localStorage.getItem(MODE_STORAGE_KEY) === 'light' ? 'light' : 'dark';
}

function readTheme(): string {
    return document.documentElement.dataset.theme
        ?? window.localStorage.getItem(THEME_STORAGE_KEY)
        ?? 'gold';
}

function applyAmberTheme() {
    document.documentElement.dataset.theme = 'amber';
    window.localStorage.setItem(THEME_STORAGE_KEY, 'amber');
}

export function applyAppearanceMode(mode: AppearanceMode) {
    window.localStorage.setItem(MODE_STORAGE_KEY, mode);
    document.documentElement.dataset.mode = mode;
    document.documentElement.style.colorScheme = mode;
    window.dispatchEvent(new CustomEvent(APPEARANCE_MODE_EVENT, { detail: { mode } }));
}

export function SettingsAppearanceLightControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const canViewSettings = context.permissions.includes('*') || context.permissions.includes('tenant.view');
    const [mode, setMode] = useState<AppearanceMode>(() => readMode());
    const [theme, setTheme] = useState<string>(() => readTheme());

    useEffect(() => {
        const onMode = (event: Event) => {
            const next = (event as CustomEvent<{ mode?: unknown }>).detail?.mode;
            if (next === 'dark' || next === 'light') setMode(next);
        };
        window.addEventListener(APPEARANCE_MODE_EVENT, onMode);
        return () => window.removeEventListener(APPEARANCE_MODE_EVENT, onMode);
    }, []);

    useEffect(() => {
        const syncTheme = () => setTheme(readTheme());
        const observer = new MutationObserver(syncTheme);
        observer.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
        window.addEventListener('storage', syncTheme);
        return () => {
            observer.disconnect();
            window.removeEventListener('storage', syncTheme);
        };
    }, []);

    if (!canViewSettings) return null;

    const setLight = () => {
        applyAppearanceMode('light');
        setMode('light');
    };

    const setAmber = () => {
        applyAmberTheme();
        setTheme('amber');
    };

    return (
        <>
        <section className="toolbar-panel" aria-label={locale === 'ar' ? 'وضع العرض' : 'Display mode'}>
            <div>
                <span className="workspace-kicker">APPEARANCE</span>
                <strong>{locale === 'ar' ? 'وضع العرض' : 'Display mode'}</strong>
                <small>{locale === 'ar' ? 'وضوح أعلى في الإضاءة' : 'Clearer in bright environments'}</small>
            </div>
            <div className="toolbar-actions">
                <button
                    type="button"
                    className={`btn ${mode === 'light' ? 'primary' : ''}`}
                    aria-pressed={mode === 'light'}
                    data-canonical-operation={SETTINGS_SET_LIGHT_OPERATION_ID}
                    onClick={setLight}
                >
                    <span aria-hidden="true">☀</span>
                    {locale === 'ar' ? 'فاتح' : 'Light'}
                </button>
            </div>
        </section>
        <section className="toolbar-panel" aria-label={locale === 'ar' ? 'لون النظام' : 'Accent color'}>
            <div>
                <span className="workspace-kicker">APPEARANCE</span>
                <strong>{locale === 'ar' ? 'لون النظام' : 'Accent color'}</strong>
                <small>{locale === 'ar' ? 'اختر اللون الكهرماني للواجهة.' : 'Use the amber application accent.'}</small>
            </div>
            <div className="toolbar-actions">
                <button
                    type="button"
                    className={`btn ${theme === 'amber' ? 'primary' : ''}`}
                    aria-pressed={theme === 'amber'}
                    data-canonical-operation={SETTINGS_SET_AMBER_OPERATION_ID}
                    data-source-handler="SetAmber"
                    onClick={setAmber}
                >
                    <span aria-hidden="true">●</span>
                    {locale === 'ar' ? 'كهرماني' : 'Amber'}
                </button>
            </div>
        </section>
        </>
    );
}
