import React from 'react';
import type { FrontendContext } from './core';
import { useLocale } from './i18n';

/** The server applies platform.admin regardless of client-side visibility. */
export function SettingsSystemUpdatesLinkControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    if (!context.permissions.includes('*') && !context.permissions.includes('settings.manage')) return null;

    return (
        <section className="toolbar-panel" aria-label={locale === 'ar' ? 'تحديثات النظام' : 'System updates'}>
            <div className="toolbar-actions">
                <a className="btn" href="/admin/system-updates">
                    <span aria-hidden="true">↻</span>
                    {locale === 'ar' ? 'تحديثات النظام' : 'System updates'}
                </a>
            </div>
            <p className="muted">
                {locale === 'ar'
                    ? 'تثبيت إصدارات Laravel الموقعة من GitHub بواسطة مدير المنصة فقط'
                    : 'Install signed Laravel releases from GitHub (platform administrator only)'}
            </p>
        </section>
    );
}
