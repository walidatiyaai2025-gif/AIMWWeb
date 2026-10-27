import React from 'react';
import { Link } from 'react-router-dom';
import { tenantUrl, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const MODULE_WORKSPACE_BACK_TO_HOME_OPERATION_ID = 'AIMW-CONT-371D22489E';

function hasPermission(context: FrontendContext, permission: string): boolean {
    return context.permissions.includes('*') || context.permissions.includes(permission);
}

export function authoritativeModuleWorkspaceBackToHomeHref(context: FrontendContext): string | null {
    if (!hasPermission(context, 'tenant.view')) return null;

    return tenantUrl(context.tenant.slug, '/');
}

export function ModuleWorkspaceBackToHomeControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const href = authoritativeModuleWorkspaceBackToHomeHref(context);

    if (!href) return null;

    return (
        <section
            className="panel empty-state"
            data-testid="module-unavailable"
            data-canonical-operation={MODULE_WORKSPACE_BACK_TO_HOME_OPERATION_ID}
        >
            <h2>{locale === 'ar' ? 'هذه الوظيفة غير متاحة بعد' : 'This capability is not available yet'}</h2>
            <p>
                {locale === 'ar'
                    ? 'تمت إزالة البيانات والأزرار التجريبية. لن يعرض AIMWWeb نتائج أو إجراءات وهمية إلى أن يتم توصيل هذه الوظيفة بخدمة حقيقية واختبارها من المتصفح.'
                    : 'Mock data and inert controls have been removed. AIMWWeb will not present fake results or actions until this capability is connected to a real service and browser-verified.'}
            </p>
            <Link
                className="btn"
                to={href}
                data-testid="module-back-to-home-link"
            >
                {locale === 'ar' ? 'العودة للرئيسية' : 'Back to home'}
            </Link>
        </section>
    );
}
