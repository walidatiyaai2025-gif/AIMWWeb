import React, { useRef, useState } from 'react';
import type { FrontendContext } from './core';
import { useLocale } from './i18n';
import { authorizedWordPressUsersRefreshEndpoint } from './wordpress-users-refresh-control';

export const GLOBAL_USERS_REFRESH_OPERATION_ID = 'AIMW-SYNC-7C77CF7F7A';
export type GlobalUsersRole = 'all' | 'administrator' | 'editor' | 'author' | 'contributor' | 'subscriber';

/** GlobalUsersWorkspace::RefreshAsync reloads actual WordPress users for the selected owned site. */
export function GlobalUsersRefreshControl({
    context, endpoint, role, onRoleChange, busy, onRefreshRequested,
}: {
    context: FrontendContext;
    endpoint?: string;
    role: GlobalUsersRole;
    onRoleChange: (value: GlobalUsersRole) => void;
    busy: boolean;
    onRefreshRequested: (trustedEndpoint: string) => Promise<void>;
}) {
    const { locale } = useLocale();
    const authorized = authorizedWordPressUsersRefreshEndpoint(context, endpoint);
    const inflight = useRef(false);
    const [refreshing, setRefreshing] = useState(false);
    const [error, setError] = useState<string | null>(null);
    if (!authorized) return null;

    const refresh = async () => {
        if (busy || inflight.current) return;
        inflight.current = true;
        setRefreshing(true);
        setError(null);
        try {
            await onRefreshRequested(authorized);
        } catch (cause) {
            setError(cause instanceof Error ? cause.message
                : (locale === 'ar' ? 'تعذّر تحديث المستخدمين.' : 'Could not refresh users.'));
        } finally {
            inflight.current = false;
            setRefreshing(false);
        }
    };
    return (
        <>
            <label htmlFor="global-users-role">
                {locale === 'ar' ? 'دور المستخدم' : 'User role'}
                <select id="global-users-role" value={role} disabled={busy || refreshing}
                    onChange={(event) => onRoleChange(event.currentTarget.value as GlobalUsersRole)}>
                    {(['all', 'administrator', 'editor', 'author', 'contributor', 'subscriber'] as const).map((value) =>
                        <option key={value} value={value}>{value === 'all'
                            ? (locale === 'ar' ? 'كل الأدوار' : 'All roles') : value}</option>)}
                </select>
            </label>
            <button type="button" className="btn" data-canonical-operation={GLOBAL_USERS_REFRESH_OPERATION_ID}
                disabled={busy || refreshing} aria-busy={busy || refreshing} onClick={() => void refresh()}>
                {busy || refreshing
                    ? (locale === 'ar' ? 'جارٍ تحديث المستخدمين…' : 'Refreshing global users…')
                    : (locale === 'ar' ? 'تحديث المستخدمين العام' : 'Refresh global users')}
            </button>
            {error && <span role="alert">{error}</span>}
        </>
    );
}
