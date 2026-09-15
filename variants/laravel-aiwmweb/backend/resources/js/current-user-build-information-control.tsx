import React, { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import { tenantUrl, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const CURRENT_USER_BUILD_INFORMATION_OPERATION = 'AIMW-IDEN-2387758315';

export function CurrentUserBuildInformationControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const [target, setTarget] = useState<HTMLElement | null>(null);

    useEffect(() => {
        setTarget(document.querySelector<HTMLElement>('.topbar-actions'));
    }, []);

    const canOpenBuildInformation = Boolean(
        target
        && (context.permissions.includes('tenant.view') || context.permissions.includes('*')),
    );

    if (!target || !canOpenBuildInformation) return null;

    return createPortal(
        <a
            className="btn current-user-build-information-control"
            href={tenantUrl(context.tenant.slug, '/about-build')}
            data-canonical-operation={CURRENT_USER_BUILD_INFORMATION_OPERATION}
            aria-label={locale === 'ar' ? 'فتح معلومات الإصدار' : 'Open Build information'}
            title={locale === 'ar' ? 'الفرع والنسخة وتفاصيل البناء' : 'Branch, version and build details'}
        >
            <span aria-hidden="true">ⓘ</span>{' '}
            {locale === 'ar' ? 'معلومات الإصدار' : 'Build information'}
        </a>,
        target,
    );
}
