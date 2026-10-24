import React, { useEffect, useState } from 'react';
import type { FrontendContext } from './core';
import { useLocale } from './i18n';

export const SETTINGS_SET_LIGHT_OPERATION_ID = 'AIMW-CONT-4961F90970';
export const APPEARANCE_MODE_EVENT = 'aiwm:appearance-mode';
const MODE_STORAGE_KEY = 'aiwm.mode';

type AppearanceMode = 'dark' | 'light';

function readMode(): AppearanceMode {
    return window.localStorage.getItem(MODE_STORAGE_KEY) === 'light' ? 'light' : 'dark';
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

    useEffect(() => {
        const onMode = (event: Event) => {
            const next = (event as CustomEvent<{ mode?: unknown }>).detail?.mode;
            if (next === 'dark' || next === 'light') setMode(next);
        };
        window.addEventListener(APPEARANCE_MODE_EVENT, onMode);
        return () => window.removeEventListener(APPEARANCE_MODE_EVENT, onMode);
    }, []);

    if (!canViewSettings) return null;

    const setLight = () => {
        applyAppearanceMode('light');
        setMode('light');
    };

    return (
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
    );
}
