import React, { useEffect, useState } from 'react';
import type { FrontendContext } from './core';
import { useLocale } from './i18n';

export const SETTINGS_SET_GOLD_OPERATION_ID = 'AIMW-CONT-69B5134C7E';
export const SETTINGS_THEME_STORAGE_KEY = 'aiwm-color-theme';

type AccentTheme = 'gold' | 'ocean' | 'emerald' | 'violet' | 'rose' | 'amber' | 'cyan' | 'slate';

const supportedThemes: readonly AccentTheme[] = ['gold', 'ocean', 'emerald', 'violet', 'rose', 'amber', 'cyan', 'slate'];

function readTheme(): AccentTheme {
    try {
        const saved = window.localStorage.getItem(SETTINGS_THEME_STORAGE_KEY);
        return supportedThemes.includes(saved as AccentTheme) ? saved as AccentTheme : 'gold';
    } catch {
        return 'gold';
    }
}

function readAppliedTheme(): AccentTheme {
    const applied = document.documentElement.dataset.theme;
    if (applied && supportedThemes.includes(applied as AccentTheme)) return applied as AccentTheme;
    return readTheme();
}

export function applyGoldTheme(): AccentTheme {
    document.documentElement.removeAttribute('data-theme');
    try {
        window.localStorage.setItem(SETTINGS_THEME_STORAGE_KEY, 'gold');
    } catch {
        // Match appTheme.set: the runtime theme remains authoritative when persistence is unavailable.
    }
    return 'gold';
}

export function SettingsSetGoldControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const canViewSettings = context.permissions.includes('*') || context.permissions.includes('tenant.view');
    const [theme, setTheme] = useState<AccentTheme>(() => readAppliedTheme());

    useEffect(() => {
        const syncTheme = () => setTheme(readAppliedTheme());
        const observer = new MutationObserver(syncTheme);
        observer.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });

        const onStorage = (event: StorageEvent) => {
            if (event.key === SETTINGS_THEME_STORAGE_KEY) syncTheme();
        };
        window.addEventListener('storage', onStorage);

        return () => {
            observer.disconnect();
            window.removeEventListener('storage', onStorage);
        };
    }, []);

    if (!canViewSettings) return null;

    const setGold = () => {
        setTheme(applyGoldTheme());
    };

    return (
        <section className="toolbar-panel" aria-label={locale === 'ar' ? 'لون النظام' : 'Accent color'}>
            <div>
                <span className="workspace-kicker">APPEARANCE</span>
                <strong>{locale === 'ar' ? 'لون النظام' : 'Accent color'}</strong>
                <small>{locale === 'ar'
                    ? 'الذهبي هو لوحة الألوان الافتراضية ويتم تطبيقه فورًا.'
                    : 'Gold is the default accent palette and is applied immediately.'}</small>
            </div>
            <div className="toolbar-actions">
                <button
                    type="button"
                    className={`btn ${theme === 'gold' ? 'primary' : ''}`}
                    aria-pressed={theme === 'gold'}
                    data-canonical-operation={SETTINGS_SET_GOLD_OPERATION_ID}
                    data-source-handler="SetGold"
                    onClick={setGold}
                >
                    <span aria-hidden="true">●</span>{' '}
                    {locale === 'ar' ? 'ذهبي' : 'Gold'}
                </button>
            </div>
        </section>
    );
}
