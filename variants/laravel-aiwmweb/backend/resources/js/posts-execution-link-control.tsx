import React from 'react';
import { Link } from 'react-router-dom';
import { tenantUrl, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const POSTS_EXECUTION_OPERATION_ID = 'AIMW-AUTO-06CF784553';

const canOpenExecutionCenter = (context: FrontendContext): boolean =>
    context.permissions.includes('tenant.view')
    && context.permissions.includes('content.view')
    && context.permissions.includes('operations.manage')
    && context.permissions.includes('execution.view');

export function PostsExecutionLinkControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();

    if (!canOpenExecutionCenter(context)) return null;

    return (
        <nav className="toolbar-panel" aria-label={locale === 'ar' ? 'إجراءات المقالات' : 'Posts actions'}>
            <div className="toolbar-actions">
                <Link
                    className="btn"
                    to={tenantUrl(context.tenant.slug, '/module/execution')}
                    data-canonical-operation={POSTS_EXECUTION_OPERATION_ID}
                >
                    <span aria-hidden="true">▶</span>
                    {locale === 'ar' ? 'مركز التنفيذ' : 'Execution Center'}
                </Link>
            </div>
        </nav>
    );
}
