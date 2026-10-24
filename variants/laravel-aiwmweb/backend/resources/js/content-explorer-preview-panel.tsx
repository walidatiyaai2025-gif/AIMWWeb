import React from 'react';
import { safeContentExplorerExternalUrl } from './content-explorer-wordpress-link-control';
import { useLocale } from './i18n';

export const CONTENT_EXPLORER_PREVIEW_LINK_OPERATION_ID = 'AIMW-CONT-37B4B4BEC4';

export type ContentExplorerPreviewRow = Record<string, unknown>;

function previewText(value: unknown): string {
    return typeof value === 'string' || typeof value === 'number' ? String(value) : '';
}

export function ContentExplorerPreviewPanel({
    row,
    onClose,
}: {
    row: ContentExplorerPreviewRow;
    onClose: () => void;
}) {
    const { locale } = useLocale();
    const safeUrl = safeContentExplorerExternalUrl(row.link);
    const title = previewText(row.title).trim() || `#${previewText(row.wordpress_id || row.id)}`;
    const slug = previewText(row.slug).trim();
    const type = previewText(row.type).trim();
    const status = previewText(row.status).trim();
    const modified = previewText(row.modified_at).trim();

    return (
        <aside className="panel explorer-preview-panel" aria-label={locale === 'ar' ? 'معاينة المحتوى' : 'Content preview'}>
            <header className="panel-header">
                <div>
                    <span className="workspace-kicker">{locale === 'ar' ? 'معاينة المحتوى' : 'CONTENT PREVIEW'}</span>
                    <h2>{title}</h2>
                </div>
                <button type="button" className="icon-button" onClick={onClose} aria-label={locale === 'ar' ? 'إغلاق المعاينة' : 'Close preview'}>×</button>
            </header>
            <div className="explorer-preview-meta">
                {type ? <span>{type}</span> : null}
                {status ? <span>{status}</span> : null}
                {modified ? <span data-bidi="technical">{modified}</span> : null}
            </div>
            <div className="explorer-preview-body">
                <p>{locale === 'ar'
                    ? 'هذه معاينة لبيانات المحتوى المتزامنة والموثوقة من الخادم.'
                    : 'This preview uses authoritative synchronized content metadata returned by the server.'}</p>
                {slug ? <code>/{slug}</code> : null}
            </div>
            <footer className="toolbar-actions">
                {safeUrl ? (
                    <a
                        className="btn"
                        href={safeUrl}
                        target="_blank"
                        rel="noopener noreferrer"
                        data-canonical-operation={CONTENT_EXPLORER_PREVIEW_LINK_OPERATION_ID}
                    >
                        {locale === 'ar' ? 'فتح في الموقع' : 'Open on site'} ↗
                    </a>
                ) : (
                    <span className="muted">{locale === 'ar' ? 'رابط الموقع غير متاح.' : 'Site link is unavailable.'}</span>
                )}
            </footer>
        </aside>
    );
}
