import React, { useState } from 'react';
import { apiRequest, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const ROLES_PERMISSIONS_START_CREATE_OPERATION_ID = 'AIMW-CONT-6CB3D0BE1B';

function permissionKeys(value: string): string[] {
    return [...new Set(value.split(',').map((item) => item.trim()).filter(Boolean))];
}

export function RolesPermissionsStartCreateControl({
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
    const [saving, setSaving] = useState(false);
    const [name, setName] = useState('');
    const [permissions, setPermissions] = useState('');
    const [error, setError] = useState<string | null>(null);
    const canManage = context.permissions.includes('*') || context.permissions.includes('roles.manage');
    const endpoint = context.api.roles;

    if (!canManage || !endpoint) return null;

    const startCreate = () => {
        if (busy || saving) return;
        setName('');
        setPermissions('');
        setError(null);
        setOpen(true);
    };

    const create = async (event: React.FormEvent) => {
        event.preventDefault();
        if (saving || !name.trim()) return;
        setSaving(true);
        setError(null);
        try {
            await apiRequest(endpoint, {
                method: 'POST',
                body: JSON.stringify({
                    name: name.trim(),
                    permissions: permissionKeys(permissions),
                }),
            });
            await onCreated();
            setOpen(false);
        } catch (reason) {
            setError(reason instanceof Error ? reason.message : (locale === 'ar' ? 'تعذر إنشاء الدور.' : 'The role could not be created.'));
        } finally {
            setSaving(false);
        }
    };

    return (
        <>
            <button
                type="button"
                className="btn primary"
                data-canonical-operation={ROLES_PERMISSIONS_START_CREATE_OPERATION_ID}
                disabled={busy || saving}
                onClick={startCreate}
            >
                {locale === 'ar' ? 'دور جديد' : 'New role'}
            </button>
            {open ? (
                <div className="dialog-backdrop" role="presentation">
                    <div className="dialog" role="dialog" aria-modal="true" aria-labelledby="role-create-title">
                        <header className="dialog-header">
                            <div>
                                <span className="workspace-kicker">ROLE</span>
                                <h2 id="role-create-title">{locale === 'ar' ? 'إنشاء دور' : 'Create role'}</h2>
                            </div>
                            <button type="button" className="icon-button" onClick={() => !saving && setOpen(false)} aria-label={locale === 'ar' ? 'إغلاق' : 'Close'}>×</button>
                        </header>
                        <form onSubmit={create} noValidate>
                            <label className="form-field">
                                <span>{locale === 'ar' ? 'اسم الدور' : 'Role name'} *</span>
                                <input
                                    type="text"
                                    value={name}
                                    onChange={(event) => setName(event.target.value)}
                                    disabled={saving}
                                    maxLength={100}
                                    required
                                />
                            </label>
                            <label className="form-field">
                                <span>{locale === 'ar' ? 'مفاتيح الصلاحيات' : 'Permission keys'}</span>
                                <textarea
                                    value={permissions}
                                    onChange={(event) => setPermissions(event.target.value)}
                                    disabled={saving}
                                    placeholder={locale === 'ar' ? 'مثال: content.view, sites.view' : 'Example: content.view, sites.view'}
                                />
                                <small>{locale === 'ar' ? 'افصل مفاتيح الصلاحيات بفواصل. اتركها فارغة لإنشاء دور بدون صلاحيات.' : 'Separate permission keys with commas. Leave empty to create a role with no permissions.'}</small>
                            </label>
                            {error ? <p role="alert" className="field-error">{error}</p> : null}
                            <footer className="dialog-actions">
                                <button type="button" className="btn" onClick={() => !saving && setOpen(false)} disabled={saving}>{locale === 'ar' ? 'إلغاء' : 'Cancel'}</button>
                                <button type="submit" className="btn primary" disabled={saving || !name.trim()}>
                                    {saving ? (locale === 'ar' ? 'جارٍ الحفظ…' : 'Saving…') : (locale === 'ar' ? 'إنشاء' : 'Create')}
                                </button>
                            </footer>
                        </form>
                    </div>
                </div>
            ) : null}
        </>
    );
}
