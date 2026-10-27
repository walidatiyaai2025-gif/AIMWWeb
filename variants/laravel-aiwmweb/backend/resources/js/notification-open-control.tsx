import React from 'react';
import { tenantUrl, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const NOTIFICATION_OPEN_OPERATION_ID = 'AIMW-EMAI-BDF888551C';

export function safeNotificationTarget(context: FrontendContext, value: unknown): string | null {
    if (typeof value !== 'string' || !value.startsWith('/') || value.startsWith('//') || /[\u0000-\u001F\u007F]/.test(value)) {
        return null;
    }

    return tenantUrl(context.tenant.slug, value);
}

export function NotificationOpenControl({
    context,
    row,
}: {
    context: FrontendContext;
    row: Record<string, unknown>;
}) {
    const { locale } = useLocale();
    const target = safeNotificationTarget(context, row.deep_link);

    if (!target) return null;

    return (
        <a
            className="btn compact"
            href={target}
            data-canonical-operation={NOTIFICATION_OPEN_OPERATION_ID}
        >
            {locale === 'ar' ? 'فتح' : 'Open'} <span aria-hidden="true">↗</span>
        </a>
    );
}
