import React from 'react';
import { Link } from 'react-router-dom';
import { tenantUrl, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const AI_CENTER_APPROVAL_QUEUE_OPERATION = 'AIMW-AI-991683D92C';

const canOpenApprovalQueue = (context: FrontendContext): boolean =>
    context.permissions.includes('ai.use')
    && context.permissions.includes('tenant.view')
    && context.permissions.includes('approvals.view');

export function AiCenterApprovalQueueLink({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();

    if (!canOpenApprovalQueue(context)) return null;

    return (
        <nav className="toolbar-panel" aria-label={locale === 'ar' ? 'تنقل مركز الذكاء الاصطناعي' : 'AI Center navigation'}>
            <div className="toolbar-actions">
                <Link
                    className="btn"
                    to={tenantUrl(context.tenant.slug, '/approvals')}
                    data-canonical-operation={AI_CENTER_APPROVAL_QUEUE_OPERATION}
                >
                    <span aria-hidden="true">✓</span>
                    {locale === 'ar' ? 'قائمة الموافقات' : 'Approval queue'}
                </Link>
            </div>
        </nav>
    );
}
