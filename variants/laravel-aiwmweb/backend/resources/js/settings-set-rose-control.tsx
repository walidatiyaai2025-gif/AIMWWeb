import React, { useEffect, useState } from 'react';
import type { FrontendContext } from './core';
import { useLocale } from './i18n';

export const SETTINGS_SET_ROSE_OPERATION_ID = 'AIMW-CONT-6700342002';
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

export function applyRoseTheme(): AccentTheme {
    document.documentElement.dataset.theme = 'rose';
    try {
        window.localStorage.setItem(SETTINGS_THEME_STORAGE_KEY, 'rose');
    } catch {
        // Match appTheme.set: keep the rendered session theme when persistence is unavailable.
    }
    return 'rose';
}

export function SettingsSetRoseControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const canViewSettings = context.permissions.includes('*') || context.permissions.includes('tenant.view');
    const [theme, setTheme] = useState<AccentTheme>(() => readTheme());

    useEffect(() => {
        const syncFromAuthoritativeState = () => {
            const applied = document.documentElement.dataset.theme;
            if (applied && supportedThemes.includes(applied as AccentTheme)) {
                setTheme(applied as AccentTheme);
                return;
            }
            setTheme(readTheme());
        };

        const observer = new MutationObserver(syncFromAuthoritativeState);
        observer.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });

        const onStorage = (event: StorageEvent) => {
            if (event.key === SETTINGS_THEME_STORAGE_KEY) syncFromAuthoritativeState();
        };
        window.addEventListener('storage', onStorage);

        return () => {
            observer.disconnect();
            window.removeEventListener('storage', onStorage);
        };
    }, []);

    if (!canViewSettings) return null;

    const setRose = () => {
        setTheme(applyRoseTheme());
    };

    return (
        <section className="toolbar-panel" aria-label={locale === 'ar' ? 'لون النظام' : 'Accent color'}>
            <div>
                <span className="workspace-kicker">APPEARANCE</span>
                <strong>{locale === 'ar' ? 'لون النظام' : 'Accent color'}</strong>
                <small>{locale === 'ar'
                    ? 'يتم حفظ تفضيل اللون محليًا وتطبيقه فورًا.'
                    : 'The color preference is stored locally and applied immediately.'}</small>
            </div>
            <div className="toolbar-actions">
                <button
                    type="button"
                    className={`btn ${theme === 'rose' ? 'primary' : ''}`}
                    aria-pressed={theme === 'rose'}
                    data-canonical-operation={SETTINGS_SET_ROSE_OPERATION_ID}
                    data-source-handler="SetRose"
                    onClick={setRose}
                >
                    <span aria-hidden="true">●</span>{' '}
                    {locale === 'ar' ? 'وردي' : 'Rose'}
                </button>
            </div>
        </section>
    );
}
