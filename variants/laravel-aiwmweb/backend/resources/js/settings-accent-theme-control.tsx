import React, { useEffect, useState } from 'react';
import type { FrontendContext } from './core';
import { useLocale } from './i18n';

export const SETTINGS_SET_OCEAN_OPERATION_ID = 'AIMW-CONT-659BEF8765';
export const SETTINGS_THEME_STORAGE_KEY = 'aiwm-color-theme';

export type AccentTheme = 'gold' | 'ocean' | 'emerald' | 'violet' | 'rose' | 'amber' | 'cyan' | 'slate';

type AccentThemeOption = {
    theme: AccentTheme;
    operationId: string;
    sourceHandler: string;
    englishName: string;
    arabicName: string;
};

const supportedThemes: readonly AccentTheme[] = ['gold', 'ocean', 'emerald', 'violet', 'rose', 'amber', 'cyan', 'slate'];

const options: readonly AccentThemeOption[] = [
    {
        theme: 'ocean',
        operationId: SETTINGS_SET_OCEAN_OPERATION_ID,
        sourceHandler: 'SetOcean',
        englishName: 'Ocean',
        arabicName: 'أزرق',
    },
];

function readStoredTheme(): AccentTheme {
    try {
        const saved = window.localStorage.getItem(SETTINGS_THEME_STORAGE_KEY);
        return supportedThemes.includes(saved as AccentTheme) ? saved as AccentTheme : 'gold';
    } catch {
        return 'gold';
    }
}

function readAppliedTheme(): AccentTheme {
    const applied = document.documentElement.getAttribute('data-theme');
    if (applied && supportedThemes.includes(applied as AccentTheme)) return applied as AccentTheme;
    return readStoredTheme();
}

export function applyAccentTheme(theme: AccentTheme): AccentTheme {
    if (theme === 'gold') document.documentElement.removeAttribute('data-theme');
    else document.documentElement.setAttribute('data-theme', theme);

    try {
        window.localStorage.setItem(SETTINGS_THEME_STORAGE_KEY, theme);
    } catch {
        // appTheme.set keeps the applied DOM theme authoritative if persistence is unavailable.
    }

    return theme;
}

export function SettingsAccentThemeControl({ context }: { context: FrontendContext }) {
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

    return (
        <section className="toolbar-panel" aria-label={locale === 'ar' ? 'لون النظام' : 'Accent color'}>
            <div>
                <span className="workspace-kicker">APPEARANCE</span>
                <strong>{locale === 'ar' ? 'لون النظام' : 'Accent color'}</strong>
                <small>{locale === 'ar'
                    ? 'اختر لون الواجهة؛ يتم تطبيق الاختيار وحفظه فورًا.'
                    : 'Choose an interface accent; the selection is applied and saved immediately.'}</small>
            </div>
            <div className="toolbar-actions">
                {options.map((option) => (
                    <button
                        key={option.theme}
                        type="button"
                        className={`btn ${theme === option.theme ? 'primary' : ''}`}
                        aria-pressed={theme === option.theme}
                        data-canonical-operation={option.operationId}
                        data-source-handler={option.sourceHandler}
                        onClick={() => setTheme(applyAccentTheme(option.theme))}
                    >
                        <span aria-hidden="true">●</span>{' '}
                        {locale === 'ar' ? option.arabicName : option.englishName}
                    </button>
                ))}
            </div>
        </section>
    );
}
