import React, { useState } from 'react';
import { apiRequest, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const APPLICATION_USERS_START_CREATE_OPERATION_ID = 'AIMW-CONT-E38ADF8481';

type RoleOption = { id: number; name: string };

export function ApplicationUsersStartCreateControl({
    context,
    busy = false,
    onCreated,
}: {
    context: FrontendContext;
    busy?: boolean;
    onCreated: () => void | Promise<void>;
}) {
    const { locale } = useLocale();
    const [open, setOpen] = useState(false);
    const [loadingRoles, setLoadingRoles] = useState(false);
    const [saving, setSaving] = useState(false);
    const [roles, setRoles] = useState<RoleOption[]>([]);
    const [email, setEmail] = useState('');
    const [roleId, setRoleId] = useState('');
    const [error, setError] = useState<string | null>(null);
    const canManage = context.permissions.includes('*') || context.permissions.includes('members.manage');
    const membersEndpoint = context.api['application-users'];
    const rolesEndpoint = context.api.roles;

    if (!canManage || !membersEndpoint || !rolesEndpoint) return null;

    const startCreate = async () => {
        if (busy || saving) return;
        setOpen(true);
        setEmail('');
        setRoleId('');
        setError(null);
        setLoadingRoles(true);
        try {
            const payload = await apiRequest<{ data?: RoleOption[] }>(rolesEndpoint);
            setRoles(Array.isArray(payload.data) ? payload.data : []);
        } catch (reason) {
            setRoles([]);
            setError(reason instanceof Error ? reason.message : (locale === 'ar' ? 'تعذر تحميل الأدوار.' : 'Roles could not be loaded.'));
        } finally {
            setLoadingRoles(false);
        }
    };

    const create = async (event: React.FormEvent) => {
        event.preventDefault();
        if (saving || !email.trim()) return;
        setSaving(true);
        setError(null);
        try {
            await apiRequest(membersEndpoint, {
                method: 'POST',
                body: JSON.stringify({
                    email: email.trim(),
                    role_id: roleId ? Number(roleId) : null,
                }),
            });
            await onCreated();
            setOpen(false);
        } catch (reason) {
            setError(reason instanceof Error ? reason.message : (locale === 'ar' ? 'تعذر إنشاء المستخدم.' : 'The user could not be created.'));
        } finally {
            setSaving(false);
        }
    };

    return (
        <>
            <button
                type="button"
                className="btn primary"
                data-canonical-operation={APPLICATION_USERS_START_CREATE_OPERATION_ID}
                disabled={busy || saving}
                onClick={() => void startCreate()}
            >
                {locale === 'ar' ? 'مستخدم جديد' : 'New user'}
            </button>
            {open ? (
                <div className="dialog-backdrop" role="presentation">
                    <div className="dialog" role="dialog" aria-modal="true" aria-labelledby="application-user-create-title">
                        <header className="dialog-header">
                            <div>
                                <span className="workspace-kicker">USER</span>
                                <h2 id="application-user-create-title">{locale === 'ar' ? 'إنشاء مستخدم' : 'Create user'}</h2>
                            </div>
                            <button type="button" className="icon-button" onClick={() => !saving && setOpen(false)} aria-label={locale === 'ar' ? 'إغلاق' : 'Close'}>×</button>
                        </header>
                        <form onSubmit={create} noValidate>
                            <label className="form-field">
                                <span>{locale === 'ar' ? 'البريد الإلكتروني' : 'Email'} *</span>
                                <input type="email" value={email} onChange={(event) => setEmail(event.target.value)} disabled={saving} required />
                            </label>
                            <label className="form-field">
                                <span>{locale === 'ar' ? 'الدور' : 'Role'}</span>
                                <select value={roleId} onChange={(event) => setRoleId(event.target.value)} disabled={saving || loadingRoles}>
                                    <option value="">{loadingRoles ? (locale === 'ar' ? 'جارٍ التحميل…' : 'Loading…') : '—'}</option>
                                    {roles.map((role) => <option key={role.id} value={role.id}>{role.name}</option>)}
                                </select>
                            </label>
                            {error ? <p role="alert" className="field-error">{error}</p> : null}
                            <footer className="dialog-actions">
                                <button type="button" className="btn" onClick={() => !saving && setOpen(false)} disabled={saving}>{locale === 'ar' ? 'إلغاء' : 'Cancel'}</button>
                                <button type="submit" className="btn primary" disabled={saving || loadingRoles || !email.trim()}>{saving ? (locale === 'ar' ? 'جارٍ الحفظ…' : 'Saving…') : (locale === 'ar' ? 'إنشاء' : 'Create')}</button>
                            </footer>
                        </form>
                    </div>
                </div>
            ) : null}
        </>
    );
}
