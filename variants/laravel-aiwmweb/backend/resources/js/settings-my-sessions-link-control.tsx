import React from 'react';
import { tenantUrl, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const SETTINGS_MY_SESSIONS_LINK_OPERATION_ID = 'AIMW-CONT-A6EA54E66D';
export const SETTINGS_MY_SESSIONS_SOURCE_PATH = '/settings/sessions';

export function SettingsMySessionsLinkControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const canOpenMySessions =
        context.permissions.includes('*')
        || (
            context.permissions.includes('sessions.view')
            && context.permissions.includes('sessions.manage')
        );

    if (!canOpenMySessions) return null;

    return (
        <section
            className="toolbar-panel"
            aria-label={locale === 'ar' ? 'جلساتي' : 'My sessions'}
            data-canonical-operation={SETTINGS_MY_SESSIONS_LINK_OPERATION_ID}
        >
            <div>
                <span className="workspace-kicker">SESSIONS</span>
                <strong>{locale === 'ar' ? 'جلساتي' : 'My sessions'}</strong>
                <small>
                    {locale === 'ar'
                        ? 'الأجهزة والجلسات النشطة'
                        : 'Devices and active sessions'}
                </small>
            </div>
            <div className="toolbar-actions">
                <a
                    className="btn secondary"
                    href={tenantUrl(context.tenant.slug, SETTINGS_MY_SESSIONS_SOURCE_PATH)}
                    data-canonical-operation={SETTINGS_MY_SESSIONS_LINK_OPERATION_ID}
                >
                    <span aria-hidden="true">◉</span>
                    {locale === 'ar' ? 'فتح جلساتي' : 'Open my sessions'}
                </a>
            </div>
        </section>
    );
}
