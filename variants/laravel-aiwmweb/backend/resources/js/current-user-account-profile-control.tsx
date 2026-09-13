import React, { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import { tenantUrl, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const CURRENT_USER_ACCOUNT_PROFILE_OPERATION = 'AIMW-IDEN-9B043B1FAE';

export function CurrentUserAccountProfileControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const [target, setTarget] = useState<HTMLElement | null>(null);

    useEffect(() => {
        setTarget(document.querySelector<HTMLElement>('.topbar-actions'));
    }, []);

    const tenant = encodeURIComponent(context.tenant.slug);
    const expectedProfileApi = `/tenants/${tenant}/route-api/account-profile`;
    const canOpenProfile = Boolean(
        target
        && context.permissions.includes('tenant.view')
        && context.api['account.profile'] === expectedProfileApi,
    );

    if (!target || !canOpenProfile) return null;

    return createPortal(
        <a
            className="btn current-user-account-profile-control"
            href={tenantUrl(context.tenant.slug, '/account/profile')}
            data-canonical-operation={CURRENT_USER_ACCOUNT_PROFILE_OPERATION}
            aria-label={locale === 'ar' ? 'فتح حسابي' : 'Open My account'}
            title={locale === 'ar' ? 'الملف الشخصي وكلمة المرور' : 'Profile and password'}
        >
            <span aria-hidden="true">◎</span>{' '}
            {locale === 'ar' ? 'حسابي' : 'My account'}
        </a>,
        target,
    );
}
