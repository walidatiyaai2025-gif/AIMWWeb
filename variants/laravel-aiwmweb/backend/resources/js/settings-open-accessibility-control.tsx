import React, { useEffect, useState } from 'react';
import type { FrontendContext } from './core';
import { useLocale } from './i18n';

export const SETTINGS_OPEN_ACCESSIBILITY_OPERATION_ID = 'AIMW-CONT-C49C37E2C9';
export const SETTINGS_ACCESSIBILITY_DIALOG_ID = 'settings-accessibility-display-center';

const STORAGE_KEYS = {
    font: 'aiwm-accessibility-font',
    density: 'aiwm-accessibility-density',
    contrast: 'aiwm-accessibility-contrast',
    motion: 'aiwm-accessibility-motion',
} as const;

type FontScale = 'small' | 'normal' | 'large';
type Density = 'compact' | 'default' | 'comfortable';

type AccessibilityState = {
    font: FontScale;
    density: Density;
    contrast: boolean;
    motion: boolean;
};

const DEFAULT_STATE: AccessibilityState = {
    font: 'normal',
    density: 'default',
    contrast: false,
    motion: false,
};

function readState(): AccessibilityState {
    const font = window.localStorage.getItem(STORAGE_KEYS.font);
    const density = window.localStorage.getItem(STORAGE_KEYS.density);

    return {
        font: font === 'small' || font === 'large' ? font : 'normal',
        density: density === 'compact' || density === 'comfortable' ? density : 'default',
        contrast: window.localStorage.getItem(STORAGE_KEYS.contrast) === 'high',
        motion: window.localStorage.getItem(STORAGE_KEYS.motion) === 'reduced',
    };
}

function ensureRuntimeStyles(): void {
    if (document.getElementById('aiwm-laravel-accessibility-runtime')) return;

    const style = document.createElement('style');
    style.id = 'aiwm-laravel-accessibility-runtime';
    style.textContent = [
        'html[data-font-scale="small"] { font-size: 90%; }',
        'html[data-font-scale="normal"] { font-size: 100%; }',
        'html[data-font-scale="large"] { font-size: 112.5%; }',
        'html[data-density="compact"] .panel, html[data-density="compact"] .toolbar-panel { padding: .75rem !important; }',
        'html[data-density="compact"] .workspace-stack { gap: .65rem !important; }',
        'html[data-density="comfortable"] .panel, html[data-density="comfortable"] .toolbar-panel { padding: 1.35rem !important; }',
        'html[data-density="comfortable"] .workspace-stack { gap: 1.35rem !important; }',
        'html[data-contrast="high"] { filter: contrast(1.12); }',
        'html[data-reduced-motion="true"] *, html[data-reduced-motion="true"] *::before, html[data-reduced-motion="true"] *::after { animation-duration: .001ms !important; animation-iteration-count: 1 !important; transition-duration: .001ms !important; scroll-behavior: auto !important; }',
    ].join('\n');
    document.head.appendChild(style);
}

function applyState(state: AccessibilityState): void {
    ensureRuntimeStyles();
    const root = document.documentElement;
    root.setAttribute('data-font-scale', state.font);
    root.setAttribute('data-density', state.density);
    root.setAttribute('data-contrast', state.contrast ? 'high' : 'normal');
    root.setAttribute('data-reduced-motion', state.motion ? 'true' : 'false');
}

function persistState(state: AccessibilityState): void {
    window.localStorage.setItem(STORAGE_KEYS.font, state.font);
    window.localStorage.setItem(STORAGE_KEYS.density, state.density);
    window.localStorage.setItem(STORAGE_KEYS.contrast, state.contrast ? 'high' : 'normal');
    window.localStorage.setItem(STORAGE_KEYS.motion, state.motion ? 'reduced' : 'normal');
    applyState(state);
}

export function SettingsOpenAccessibilityControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const canViewSettings = context.permissions.includes('*') || context.permissions.includes('tenant.view');
    const [open, setOpen] = useState(false);
    const [state, setState] = useState<AccessibilityState>(() => readState());

    useEffect(() => {
        applyState(state);
    }, [state]);

    if (!canViewSettings) return null;

    const update = (patch: Partial<AccessibilityState>) => {
        setState((current) => {
            const next = { ...current, ...patch };
            persistState(next);
            return next;
        });
    };

    const reset = () => {
        persistState(DEFAULT_STATE);
        setState(DEFAULT_STATE);
    };

    return (
        <section
            className="toolbar-panel"
            aria-label={locale === 'ar' ? 'مركز العرض' : 'Display center'}
            data-canonical-operation={SETTINGS_OPEN_ACCESSIBILITY_OPERATION_ID}
        >
            <div>
                <span className="workspace-kicker">ACCESSIBILITY</span>
                <strong>{locale === 'ar' ? 'مركز العرض' : 'Display center'}</strong>
                <small>Ctrl + Alt + A</small>
            </div>
            <div className="toolbar-actions">
                <button
                    type="button"
                    className="btn"
                    aria-expanded={open}
                    aria-controls={SETTINGS_ACCESSIBILITY_DIALOG_ID}
                    data-canonical-operation={SETTINGS_OPEN_ACCESSIBILITY_OPERATION_ID}
                    onClick={() => setOpen((value) => !value)}
                >
                    <span aria-hidden="true">Aa</span>
                    {locale === 'ar' ? 'فتح المركز' : 'Open center'}
                </button>
            </div>

            {open ? (
                <section
                    id={SETTINGS_ACCESSIBILITY_DIALOG_ID}
                    className="panel"
                    role="dialog"
                    aria-modal={false}
                    aria-labelledby="settings-accessibility-dialog-title"
                    data-testid="settings-accessibility-dialog"
                >
                    <header className="logs-toolbar">
                        <div>
                            <strong id="settings-accessibility-dialog-title">
                                {locale === 'ar' ? 'سهولة الوصول والعرض' : 'Accessibility & display'}
                            </strong>
                            <small>
                                {locale === 'ar'
                                    ? 'خصص شكل النظام بما يناسبك'
                                    : 'Customize the interface for your needs'}
                            </small>
                        </div>
                        <button
                            type="button"
                            className="btn"
                            aria-label={locale === 'ar' ? 'إغلاق إعدادات سهولة الوصول' : 'Close accessibility settings'}
                            onClick={() => setOpen(false)}
                        >
                            ×
                        </button>
                    </header>

                    <div className="workspace-stack">
                        <section>
                            <strong>{locale === 'ar' ? 'حجم الخط' : 'Text size'}</strong>
                            <div className="toolbar-actions">
                                {([
                                    ['small', locale === 'ar' ? 'صغير' : 'Small'],
                                    ['normal', locale === 'ar' ? 'عادي' : 'Normal'],
                                    ['large', locale === 'ar' ? 'كبير' : 'Large'],
                                ] as const).map(([value, label]) => (
                                    <button
                                        key={value}
                                        type="button"
                                        className="btn"
                                        aria-pressed={state.font === value}
                                        onClick={() => update({ font: value })}
                                    >
                                        {label}
                                    </button>
                                ))}
                            </div>
                        </section>

                        <section>
                            <strong>{locale === 'ar' ? 'كثافة العرض' : 'Display density'}</strong>
                            <div className="toolbar-actions">
                                {([
                                    ['compact', locale === 'ar' ? 'مضغوط' : 'Compact'],
                                    ['default', locale === 'ar' ? 'افتراضي' : 'Default'],
                                    ['comfortable', locale === 'ar' ? 'مريح' : 'Comfortable'],
                                ] as const).map(([value, label]) => (
                                    <button
                                        key={value}
                                        type="button"
                                        className="btn"
                                        aria-pressed={state.density === value}
                                        onClick={() => update({ density: value })}
                                    >
                                        {label}
                                    </button>
                                ))}
                            </div>
                        </section>

                        <section className="toolbar-actions">
                            <button
                                type="button"
                                className="btn"
                                aria-pressed={state.contrast}
                                onClick={() => update({ contrast: !state.contrast })}
                            >
                                {locale === 'ar' ? 'تباين عالٍ' : 'High contrast'}
                            </button>
                            <button
                                type="button"
                                className="btn"
                                aria-pressed={state.motion}
                                onClick={() => update({ motion: !state.motion })}
                            >
                                {locale === 'ar' ? 'تقليل الحركة' : 'Reduce motion'}
                            </button>
                            <button type="button" className="btn secondary" onClick={reset}>
                                {locale === 'ar' ? 'استعادة الإعدادات الافتراضية' : 'Reset to defaults'}
                            </button>
                        </section>
                    </div>
                </section>
            ) : null}
        </section>
    );
}
