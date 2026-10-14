import React, { useEffect, useMemo, useState } from 'react';
import { createPortal } from 'react-dom';
import { Link } from 'react-router-dom';
import { apiRequest, tenantUrl, type FrontendContext } from './core';
import { useLocale } from './i18n';
import './notification-center-toggle-control.css';

export const NOTIFICATION_CENTER_TOGGLE_OPERATION_ID = 'AIMW-EMAI-8E3B0AACD6';

type NotificationRow = Record<string, unknown>;

type NotificationEnvelope = {
    data?: NotificationRow[];
    items?: NotificationRow[];
};

type LoadState = 'idle' | 'loading' | 'ready' | 'error';

function endpointWithPage(endpoint: string): string {
    const url = new URL(endpoint, window.location.origin);
    url.searchParams.set('page', '1');
    return `${url.pathname}${url.search}`;
}

function normalizeRows(payload: NotificationEnvelope | NotificationRow[]): NotificationRow[] {
    const rows = Array.isArray(payload) ? payload : (payload.data ?? payload.items ?? []);
    return rows.filter((row): row is NotificationRow => Boolean(row && typeof row === 'object')).slice(0, 12);
}

function text(row: NotificationRow, ...keys: string[]): string {
    for (const key of keys) {
        const value = row[key];
        if (typeof value === 'string' && value.trim()) return value.trim();
    }
    return '';
}

function isUnread(row: NotificationRow): boolean {
    if (typeof row.is_read === 'boolean') return !row.is_read;
    if (typeof row.isRead === 'boolean') return !row.isRead;
    return !row.read_at && !row.readAt;
}

function severity(row: NotificationRow): string {
    return text(row, 'severity', 'level', 'type').toLowerCase() || 'information';
}

function createdAt(row: NotificationRow, locale: 'en' | 'ar'): string {
    const value = text(row, 'created_at', 'createdAt', 'created_at_utc', 'createdAtUtc');
    if (!value) return '';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;
    return new Intl.DateTimeFormat(locale === 'ar' ? 'ar-KW' : 'en', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(date);
}

export function NotificationCenterToggleControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const [target, setTarget] = useState<HTMLElement | null>(null);
    const [open, setOpen] = useState(false);
    const [state, setState] = useState<LoadState>('idle');
    const [items, setItems] = useState<NotificationRow[]>([]);
    const [error, setError] = useState('');

    const endpoint = context.api.notifications;
    const authorized = context.permissions.includes('*') || context.permissions.includes('notifications.view');
    const unreadCount = useMemo(() => items.filter(isUnread).length, [items]);

    useEffect(() => {
        setTarget(document.querySelector<HTMLElement>('.topbar-actions'));
    }, []);

    const refresh = async () => {
        if (!endpoint || state === 'loading') return;
        setState('loading');
        setError('');
        try {
            const payload = await apiRequest<NotificationEnvelope | NotificationRow[]>(endpointWithPage(endpoint));
            setItems(normalizeRows(payload));
            setState('ready');
        } catch (failure) {
            setError(failure instanceof Error ? failure.message : (locale === 'ar' ? 'تعذر تحميل التنبيهات.' : 'Notifications could not be loaded.'));
            setState('error');
        }
    };

    const toggle = () => {
        if (open) {
            setOpen(false);
            return;
        }

        setOpen(true);
        void refresh();
    };

    if (!target || !authorized || !endpoint) return null;

    return createPortal(
        <div className="notification-center-toggle">
            <button
                type="button"
                className="icon-button notification-center-trigger"
                data-canonical-operation={NOTIFICATION_CENTER_TOGGLE_OPERATION_ID}
                aria-label={locale === 'ar' ? 'مركز التنبيهات' : 'Notification center'}
                aria-expanded={open}
                aria-controls="notification-center-popover"
                onClick={toggle}
            >
                <span aria-hidden="true">♢</span>
                {unreadCount > 0 ? <b className="notification-center-badge">{unreadCount > 99 ? '99+' : unreadCount}</b> : null}
            </button>

            {open ? (
                <section
                    id="notification-center-popover"
                    className="notification-center-popover"
                    aria-label={locale === 'ar' ? 'مركز التنبيهات' : 'Notification center'}
                >
                    <header>
                        <div>
                            <strong>{locale === 'ar' ? 'مركز التنبيهات' : 'Notification center'}</strong>
                            <small>{locale === 'ar' ? `${unreadCount} غير مقروء` : `${unreadCount} unread`}</small>
                        </div>
                    </header>

                    {state === 'loading' ? (
                        <div className="notification-center-state" role="status">
                            {locale === 'ar' ? 'جارٍ تحميل التنبيهات…' : 'Loading notifications…'}
                        </div>
                    ) : state === 'error' ? (
                        <div className="notification-center-state error" role="alert">
                            <strong>{locale === 'ar' ? 'تعذر تحميل التنبيهات' : 'Notifications could not be loaded'}</strong>
                            <small>{error}</small>
                        </div>
                    ) : items.length === 0 ? (
                        <div className="notification-center-state">
                            <span aria-hidden="true">✓</span>
                            <strong>{locale === 'ar' ? 'لا توجد تنبيهات' : "You're all caught up"}</strong>
                            <small>{locale === 'ar' ? 'ستظهر عمليات النظام المهمة هنا.' : 'Important system activity will appear here.'}</small>
                        </div>
                    ) : (
                        <div className="notification-center-list">
                            {items.map((row, index) => (
                                <article
                                    className={`notification-center-item ${isUnread(row) ? 'unread' : ''}`}
                                    data-severity={severity(row)}
                                    key={String(row.id ?? index)}
                                >
                                    <span className="notification-center-dot" aria-hidden="true" />
                                    <div>
                                        <strong>{text(row, 'title', 'subject') || (locale === 'ar' ? 'تنبيه من النظام' : 'System notification')}</strong>
                                        {text(row, 'message', 'body') ? <small>{text(row, 'message', 'body')}</small> : null}
                                        {createdAt(row, locale) ? <time>{createdAt(row, locale)}</time> : null}
                                    </div>
                                </article>
                            ))}
                        </div>
                    )}

                    <footer>
                        <Link to={tenantUrl(context.tenant.slug, '/notifications')} onClick={() => setOpen(false)}>
                            {locale === 'ar' ? 'عرض كل الإشعارات' : 'View all notifications'}
                        </Link>
                    </footer>
                </section>
            ) : null}
        </div>,
        target,
    );
}
