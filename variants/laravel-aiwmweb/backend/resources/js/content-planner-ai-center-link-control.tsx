import React from 'react';
import { Link } from 'react-router-dom';
import { tenantUrl, workspaceRoutes, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const CONTENT_PLANNER_AI_CENTER_LINK_OPERATION_ID = 'AIMW-BILL-CAFE798AA3';
export const CONTENT_PLANNER_AI_CENTER_LINK_SOURCE_OPERATION_KEY =
    'visible:src/AIWordPressManager.Web/Components/Pages/ContentPlanner.razor:/content-planner:/ai-center:/ai-center';
export const CONTENT_PLANNER_AI_CENTER_LINK_SOURCE_PATH = '/content-planner';
export const CONTENT_PLANNER_AI_CENTER_LINK_SOURCE_CONTROL_TARGET = '/ai-center';

function hasPermission(context: FrontendContext, permission: string): boolean {
    return context.permissions.includes('*') || context.permissions.includes(permission);
}

export function contentPlannerAiCenterHref(context: FrontendContext): string {
    return tenantUrl(context.tenant.slug, '/ai-center');
}

export function canOpenContentPlannerAiCenter(context: FrontendContext): boolean {
    const plannerRoute = workspaceRoutes.find((route) => route.key === 'content-planner');
    const aiCenterRoute = workspaceRoutes.find((route) => route.key === 'ai-center');
    if (!plannerRoute || !aiCenterRoute) return false;

    const canViewPlanner = !plannerRoute.permission || hasPermission(context, plannerRoute.permission);
    const canUseAi = !aiCenterRoute.permission || hasPermission(context, aiCenterRoute.permission);

    return canViewPlanner && canUseAi;
}

export function ContentPlannerAiCenterLinkControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();

    if (!canOpenContentPlannerAiCenter(context)) return null;

    return (
        <section className="panel" aria-label={locale === 'ar' ? 'اختصارات الذكاء من مخطط المحتوى' : 'Content Planner AI shortcut'}>
            <div className="panel-header">
                <div>
                    <span className="workspace-kicker">AI WORKSPACE</span>
                    <strong>{locale === 'ar' ? 'تابع إلى مركز الذكاء' : 'Continue to AI Center'}</strong>
                </div>
                <Link
                    className="btn"
                    data-canonical-operation={CONTENT_PLANNER_AI_CENTER_LINK_OPERATION_ID}
                    data-source-operation-key={CONTENT_PLANNER_AI_CENTER_LINK_SOURCE_OPERATION_KEY}
                    data-source-path={CONTENT_PLANNER_AI_CENTER_LINK_SOURCE_PATH}
                    data-source-control-target={CONTENT_PLANNER_AI_CENTER_LINK_SOURCE_CONTROL_TARGET}
                    to={contentPlannerAiCenterHref(context)}
                >
                    ✦ {locale === 'ar' ? 'مركز الذكاء الاصطناعي' : 'AI Center'}
                </Link>
            </div>
        </section>
    );
}
