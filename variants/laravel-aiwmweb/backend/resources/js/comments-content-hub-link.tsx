import React from 'react';
import { Link } from 'react-router-dom';
import { tenantUrl, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const COMMENTS_CONTENT_HUB_LINK_OPERATION = 'AIMW-COMM-A0D005681B';

export function CommentsContentHubLink({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const canViewContent = context.permissions.includes('*') || context.permissions.includes('content.view');

    if (!canViewContent) return null;

    return (
        <nav className="toolbar-panel" aria-label={locale === 'ar' ? 'تنقل مركز التعليقات' : 'Comments workspace navigation'}>
            <div className="toolbar-actions">
                <Link
                    className="btn"
                    to={tenantUrl(context.tenant.slug, '/content')}
                    data-canonical-operation={COMMENTS_CONTENT_HUB_LINK_OPERATION}
                >
                    {locale === 'ar' ? 'مركز المحتوى' : 'Content hub'}
                </Link>
            </div>
        </nav>
    );
}
