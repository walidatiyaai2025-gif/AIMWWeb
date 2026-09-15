import React from 'react';
import { Link } from 'react-router-dom';
import { tenantUrl, workspaceRoutes, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const CONTENT_EXPLORER_EXECUTION_LINK_OPERATION_ID = 'AIMW-AUTO-22BC08CEF1';
export const CONTENT_EXPLORER_EXECUTION_LINK_SOURCE_OPERATION_KEY =
    'visible:src/AIWordPressManager.Web/Components/Pages/ContentExplorer.razor:/sites/{Id:guid}/explorer:/module/execution:/module/execution';
export const CONTENT_EXPLORER_EXECUTION_LINK_SOURCE_PATH = '/sites/{Id:guid}/explorer';
export const CONTENT_EXPLORER_EXECUTION_LINK_SOURCE_CONTROL_TARGET = '/module/execution';

export function contentExplorerExecutionHref(context: FrontendContext): string {
    return tenantUrl(context.tenant.slug, '/module/execution');
}

function hasPermission(context: FrontendContext, permission: string): boolean {
    return context.permissions.includes('*') || context.permissions.includes(permission);
}

export function canOpenContentExplorerExecution(context: FrontendContext): boolean {
    const explorerRoute = workspaceRoutes.find((route) => route.key === 'explorer');
    const executionRoute = workspaceRoutes.find((route) => route.key === 'execution');
    if (!explorerRoute || !executionRoute) return false;

    const canViewExplorer = !explorerRoute.permission || hasPermission(context, explorerRoute.permission);
    const canViewExecution = !executionRoute.permission || hasPermission(context, executionRoute.permission);

    return canViewExplorer && canViewExecution && hasPermission(context, 'operations.manage');
}

export function ContentExplorerExecutionLinkControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();

    if (!canOpenContentExplorerExecution(context)) return null;

    return (
        <section className="panel" aria-label={locale === 'ar' ? 'اختصارات مستكشف المحتوى' : 'Content Explorer shortcuts'}>
            <div className="panel-header">
                <div>
                    <span className="workspace-kicker">OPERATIONS</span>
                    <strong>{locale === 'ar' ? 'تنفيذ التغييرات' : 'Execute changes'}</strong>
                </div>
                <Link
                    className="btn"
                    data-canonical-operation={CONTENT_EXPLORER_EXECUTION_LINK_OPERATION_ID}
                    data-source-operation-key={CONTENT_EXPLORER_EXECUTION_LINK_SOURCE_OPERATION_KEY}
                    data-source-path={CONTENT_EXPLORER_EXECUTION_LINK_SOURCE_PATH}
                    data-source-control-target={CONTENT_EXPLORER_EXECUTION_LINK_SOURCE_CONTROL_TARGET}
                    to={contentExplorerExecutionHref(context)}
                >
                    ▶ {locale === 'ar' ? 'مركز التنفيذ' : 'Execution Center'}
                </Link>
            </div>
        </section>
    );
}
