import React from 'react';
import { Link, useLocation } from 'react-router-dom';
import { tenantUrl, workspaceRoutes, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const AUTOMATION_NEW_JOB_OPERATION_ID = 'AIMW-AUTO-3C8B141746';
export const AUTOMATION_REFRESH_OPERATION_ID = 'AIMW-AUTO-647F50CC73';
export const SCHEDULE_REFRESH_OPERATION_ID = 'AIMW-AUTO-E4269EADC3';
export const SCHEDULE_CONNECT_SITE_OPERATION_ID = 'AIMW-AUTO-7C13E2AA0B';
export const SCHEDULE_CANCEL_EDIT_OPERATION_ID = 'AIMW-AUTO-6EB9542C7A';
export const EXECUTION_SYNC_SITE_OPERATION_ID = 'AIMW-AUTO-B2CDFF403F';
export const PAGES_EXECUTION_LINK_OPERATION_ID = 'AIMW-AUTO-C11296372B';
export const HOME_EXECUTION_LINK_OPERATION_ID = 'AIMW-AUTO-66466C8C7F';
export const REPORTS_EXPORT_AUTOMATION_OPERATION_ID = 'AIMW-AUTO-C4A8DCCFEF';

export const AUTOMATION_PHASE_REFRESH_OPERATIONS: Partial<Record<string, string>> = {
    automation: AUTOMATION_REFRESH_OPERATION_ID,
    schedules: SCHEDULE_REFRESH_OPERATION_ID,
};

export const AUTOMATION_PHASE_ACTION_OPERATIONS: Partial<Record<string, string>> = {
    'automation.create': AUTOMATION_NEW_JOB_OPERATION_ID,
    'reports.export': REPORTS_EXPORT_AUTOMATION_OPERATION_ID,
};

function hasPermission(context: FrontendContext, permission: string): boolean {
    return context.permissions.includes('*') || context.permissions.includes(permission);
}

function canOpen(context: FrontendContext, routeKey: string, extraPermission?: string): boolean {
    const route = workspaceRoutes.find((candidate) => candidate.key === routeKey);
    if (!route) return false;
    if (route.permission && !hasPermission(context, route.permission)) return false;
    return !extraPermission || hasPermission(context, extraPermission);
}

function currentRouteKey(context: FrontendContext, pathname: string): string | null {
    const tenantPrefix = `/tenants/${encodeURIComponent(context.tenant.slug)}`;
    const relative = pathname.startsWith(tenantPrefix) ? pathname.slice(tenantPrefix.length) || '/' : pathname;
    return workspaceRoutes.find((route) => route.path === relative)?.key ?? null;
}

export function AutomationPhaseNavigationControls({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const location = useLocation();
    const routeKey = currentRouteKey(context, location.pathname);

    if (routeKey === 'pages' && canOpen(context, 'execution', 'operations.manage')) {
        return <Link className="btn" data-canonical-operation={PAGES_EXECUTION_LINK_OPERATION_ID} to={tenantUrl(context.tenant.slug, '/module/execution')}>▶ {locale === 'ar' ? 'التنفيذ' : 'Execution'}</Link>;
    }

    if (routeKey === 'execution' && canOpen(context, 'sync')) {
        return <Link className="btn" data-canonical-operation={EXECUTION_SYNC_SITE_OPERATION_ID} to={tenantUrl(context.tenant.slug, '/module/sync')}>↻ {locale === 'ar' ? 'المزامنة' : 'Synchronization'}</Link>;
    }

    if (routeKey === 'schedules' && canOpen(context, 'sites', 'sites.manage')) {
        return <Link className="btn" data-canonical-operation={SCHEDULE_CONNECT_SITE_OPERATION_ID} to={tenantUrl(context.tenant.slug, '/sites/connect')}>＋ {locale === 'ar' ? 'إضافة موقع' : 'Connect site'}</Link>;
    }

    return null;
}
