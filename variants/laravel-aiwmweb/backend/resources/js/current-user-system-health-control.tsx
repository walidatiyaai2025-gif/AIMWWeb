import React, { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import { tenantUrl, workspaceRoutes, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const CURRENT_USER_SYSTEM_HEALTH_OPERATION = 'AIMW-IDEN-FC900E61B8';

export function CurrentUserSystemHealthControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const [target, setTarget] = useState<HTMLElement | null>(null);

    useEffect(() => {
        setTarget(document.querySelector<HTMLElement>('.topbar-actions'));
    }, []);

    const systemHealthRoute = workspaceRoutes.find((route) => route.key === 'system-health');
    const canOpenSystemHealth = Boolean(
        target
        && context.permissions.includes('tenant.view')
        && context.permissions.includes('diagnostics.view')
        && systemHealthRoute?.path === '/system-health'
        && systemHealthRoute.permission === 'diagnostics.view',
    );

    if (!target || !canOpenSystemHealth) {
        return null;
    }

    return createPortal(
        <a
            href={tenantUrl(context.tenant.slug, '/system-health')}
            className="btn current-user-system-health-control"
            data-canonical-operation={CURRENT_USER_SYSTEM_HEALTH_OPERATION}
            aria-label={locale === 'ar' ? 'فتح صحة النظام' : 'Open System health'}
            title={locale === 'ar' ? 'فحص الخدمات وقاعدة البيانات' : 'Services and database status'}
        >
            <span aria-hidden="true">♥</span>
            <span>{locale === 'ar' ? 'صحة النظام' : 'System health'}</span>
        </a>,
        target,
    );
}
