import React from 'react';
import { Link } from 'react-router-dom';
import { tenantUrl, type FrontendContext } from './core';
import { useLocale } from './i18n';
import { CommentsCommentLinksControl } from './comments-comment-link-control';

export const COMMENTS_BACK_TO_SITES_OPERATION_ID = 'AIMW-COMM-85A340C8BC';

export function CommentsBackToSitesControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const target = tenantUrl(context.tenant.slug, '/sites');

    return (
        <>
            <nav
                className="toolbar-panel comments-navigation"
                aria-label={locale === 'ar' ? 'تنقل التعليقات' : 'Comments navigation'}
                data-canonical-operation={COMMENTS_BACK_TO_SITES_OPERATION_ID}
            >
                <Link className="btn" to={target}>
                    ← {locale === 'ar' ? 'العودة إلى المواقع' : 'Back to sites'}
                </Link>
            </nav>
            <CommentsCommentLinksControl context={context} />
        </>
    );
}
