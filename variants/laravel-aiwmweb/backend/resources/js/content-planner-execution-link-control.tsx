import React from 'react';
import { Link } from 'react-router-dom';
import { tenantUrl, workspaceRoutes, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const CONTENT_PLANNER_EXECUTION_LINK_OPERATION_ID = 'AIMW-BILL-0E16C6E79E';
export const CONTENT_PLANNER_EXECUTION_LINK_SOURCE_OPERATION_KEY =
    'visible:src/AIWordPressManager.Web/Components/Pages/ContentPlanner.razor:/content-planner:/module/execution:/module/execution';
export const CONTENT_PLANNER_EXECUTION_LINK_SOURCE_PATH = '/content-planner';
export const CONTENT_PLANNER_EXECUTION_LINK_SOURCE_CONTROL_TARGET = '/module/execution';

export function contentPlannerExecutionHref(context: FrontendContext): string {
    return tenantUrl(context.tenant.slug, '/module/execution');
}

function hasPermission(context: FrontendContext, permission: string): boolean {
    return context.permissions.includes('*') || context.permissions.includes(permission);
}

export function canOpenContentPlannerExecution(context: FrontendContext): boolean {
    const plannerRoute = workspaceRoutes.find((route) => route.key === 'content-planner');
    const executionRoute = workspaceRoutes.find((route) => route.key === 'execution');
    if (!plannerRoute || !executionRoute) return false;

    const canViewPlanner = !plannerRoute.permission || hasPermission(context, plannerRoute.permission);
    const canViewExecution = !executionRoute.permission || hasPermission(context, executionRoute.permission);

    return canViewPlanner && canViewExecution && hasPermission(context, 'operations.manage');
}

export function ContentPlannerExecutionLinkControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();

    if (!canOpenContentPlannerExecution(context)) return null;

    return (
        <section className="panel" aria-label={locale === 'ar' ? 'اختصارات مخطط المحتوى' : 'Content Planner shortcuts'}>
            <div className="panel-header">
                <div>
                    <span className="workspace-kicker">CONTENT OPERATIONS</span>
                    <strong>{locale === 'ar' ? 'أرسل العمل إلى التنفيذ' : 'Continue to execution'}</strong>
                </div>
                <Link
                    className="btn"
                    data-canonical-operation={CONTENT_PLANNER_EXECUTION_LINK_OPERATION_ID}
                    data-source-operation-key={CONTENT_PLANNER_EXECUTION_LINK_SOURCE_OPERATION_KEY}
                    data-source-path={CONTENT_PLANNER_EXECUTION_LINK_SOURCE_PATH}
                    data-source-control-target={CONTENT_PLANNER_EXECUTION_LINK_SOURCE_CONTROL_TARGET}
                    to={contentPlannerExecutionHref(context)}
                >
                    ▶ {locale === 'ar' ? 'مركز التنفيذ' : 'Execution Center'}
                </Link>
            </div>
        </section>
    );
}
