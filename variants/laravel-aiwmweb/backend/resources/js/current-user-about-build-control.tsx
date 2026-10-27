import React, { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import { tenantUrl, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const CURRENT_USER_ABOUT_BUILD_OPERATION_ID = 'AIMW-IDEN-2387758315';

export function CurrentUserAboutBuildControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const [target, setTarget] = useState<HTMLElement | null>(null);

    useEffect(() => {
        setTarget(document.querySelector<HTMLElement>('.user-chip'));
    }, []);

    const activeTenantIsAuthoritative = Boolean(
        context.tenant.slug
        && context.tenants.some((tenant) => tenant.slug === context.tenant.slug)
        && Number.isSafeInteger(context.user.id)
        && context.user.id > 0,
    );
    const canViewTenant = context.permissions.includes('tenant.view') || context.permissions.includes('*');

    if (!target || !activeTenantIsAuthoritative || !canViewTenant) return null;

    return createPortal(
        <a
            className="current-user-about-build-control"
            href={tenantUrl(context.tenant.slug, '/about-build')}
            data-canonical-operation={CURRENT_USER_ABOUT_BUILD_OPERATION_ID}
            aria-label={locale === 'ar' ? 'فتح معلومات الإصدار' : 'Open About Build'}
            title={locale === 'ar' ? 'معلومات الإصدار وبيئة التشغيل' : 'Build and runtime information'}
        >
            <span aria-hidden="true">ⓘ</span>
            <span>{locale === 'ar' ? 'عن الإصدار' : 'About Build'}</span>
        </a>,
        target,
    );
}
