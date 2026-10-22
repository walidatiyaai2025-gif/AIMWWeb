import React, { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import { tenantUrl, workspaceRoutes, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const CURRENT_USER_SETTINGS_OPERATION = 'AIMW-IDEN-A1064E5A5E';

export function CurrentUserSettingsControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const [target, setTarget] = useState<HTMLElement | null>(null);

    useEffect(() => {
        setTarget(document.querySelector<HTMLElement>('.topbar-actions'));
    }, []);

    const settingsRoute = workspaceRoutes.find((route) => route.key === 'settings');
    const canOpenSettings = Boolean(
        target
        && context.permissions.includes('tenant.view')
        && settingsRoute?.path === '/settings'
        && settingsRoute.permission === 'tenant.view',
    );

    if (!target || !canOpenSettings) return null;

    return createPortal(
        <a
            className="btn current-user-settings-control"
            href={tenantUrl(context.tenant.slug, '/settings')}
            data-canonical-operation={CURRENT_USER_SETTINGS_OPERATION}
            aria-label={locale === 'ar' ? 'فتح الإعدادات' : 'Open Settings'}
            title={locale === 'ar' ? 'اللغة والمظهر والتفضيلات' : 'Language, appearance and preferences'}
        >
            <span aria-hidden="true">⚙</span>{' '}
            {locale === 'ar' ? 'الإعدادات' : 'Settings'}
        </a>,
        target,
    );
}
