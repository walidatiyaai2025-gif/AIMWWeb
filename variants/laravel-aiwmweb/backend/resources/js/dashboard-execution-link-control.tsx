import React from 'react';
import { Link } from 'react-router-dom';
import { tenantUrl, workspaceRoutes, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const DASHBOARD_EXECUTION_LINK_OPERATION_ID = 'AIMW-AUTO-151800BD9D';

export function dashboardExecutionHref(context: FrontendContext): string {
    return tenantUrl(context.tenant.slug, '/module/execution');
}

function hasPermission(context: FrontendContext, permission: string): boolean {
    return context.permissions.includes('*') || context.permissions.includes(permission);
}

export function canOpenDashboardExecution(context: FrontendContext): boolean {
    const executionRoute = workspaceRoutes.find((route) => route.key === 'execution');
    if (!executionRoute) return false;

    const canViewExecution = !executionRoute.permission || hasPermission(context, executionRoute.permission);
    return canViewExecution && hasPermission(context, 'operations.manage');
}

export function DashboardExecutionLinkControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();

    if (!canOpenDashboardExecution(context)) return null;

    return (
        <section className="panel" aria-label={locale === 'ar' ? 'اختصارات لوحة التحكم' : 'Dashboard shortcuts'}>
            <div className="panel-header">
                <div>
                    <span className="workspace-kicker">OPERATIONS</span>
                    <strong>{locale === 'ar' ? 'العودة إلى التنفيذ' : 'Jump back in'}</strong>
                </div>
                <Link
                    className="btn"
                    data-canonical-operation={DASHBOARD_EXECUTION_LINK_OPERATION_ID}
                    to={dashboardExecutionHref(context)}
                >
                    ▶ {locale === 'ar' ? 'التنفيذ' : 'Execution'}
                </Link>
            </div>
        </section>
    );
}
