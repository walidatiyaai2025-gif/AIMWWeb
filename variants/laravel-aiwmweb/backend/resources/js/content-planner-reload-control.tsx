import React from 'react';
import type { FrontendContext } from './core';
import { useLocale } from './i18n';

export const CONTENT_PLANNER_RELOAD_OPERATION_ID = 'AIMW-BILL-8BE4A39748';
export const CONTENT_PLANNER_RELOAD_SOURCE_OPERATION_KEY =
    'visible:src/AIWordPressManager.Web/Components/Pages/ContentPlanner.razor:/content-planner:ReloadAsync:ReloadAsync';

export function reloadContentPlanner(reload: () => void = () => window.location.reload()): void {
    reload();
}

function hasPlannerAccess(context: FrontendContext): boolean {
    return context.permissions.includes('*') || context.permissions.includes('content.view');
}

export function ContentPlannerReloadControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();

    if (!hasPlannerAccess(context)) return null;

    return (
        <button
            type="button"
            className="btn"
            data-canonical-operation={CONTENT_PLANNER_RELOAD_OPERATION_ID}
            data-source-operation-key={CONTENT_PLANNER_RELOAD_SOURCE_OPERATION_KEY}
            onClick={() => reloadContentPlanner()}
            aria-label={locale === 'ar' ? 'إعادة تحميل مخطط المحتوى' : 'Reload content planner'}
            title={locale === 'ar' ? 'إعادة تحميل' : 'Reload'}
        >
            ↻
        </button>
    );
}
