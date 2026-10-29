import React, { useState } from 'react';
import { apiRequest, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const ROLES_START_CREATE_OPERATION_ID = 'AIMW-CONT-6CB3D0BE1B';

export function RolesStartCreateControl({
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
    const [error, setError] = useState<string | null>(null);
    const canManage = context.permissions.includes('*') || context.permissions.includes('roles.manage');
    const rolesEndpoint = context.api.roles;

    if (!canManage || !rolesEndpoint) return null;

    const startCreate = () => {
        if (busy || saving) return;
        setName('');
        setError(null);
        setOpen(true);
    };

    const create = async (event: React.FormEvent) => {
        event.preventDefault();
        const normalizedName = name.trim();
        if (saving || !normalizedName) return;

        setSaving(true);
        setError(null);
        try {
            await apiRequest(rolesEndpoint, {
                method: 'POST',
                body: JSON.stringify({
                    name: normalizedName,
                    permissions: [],
                }),
            });
            await onCreated();
            setOpen(false);
        } catch (reason) {
            setError(
                reason instanceof Error
                    ? reason.message
                    : (locale === 'ar' ? 'تعذر إنشاء الدور.' : 'The role could not be created.'),
            );
        } finally {
            setSaving(false);
        }
    };

    return (
        <>
            <button
                type="button"
                className="btn primary"
                data-canonical-operation={ROLES_START_CREATE_OPERATION_ID}
                disabled={busy || saving}
                aria-busy={busy || saving ? 'true' : 'false'}
                onClick={startCreate}
            >
                {locale === 'ar' ? '＋ دور جديد' : '＋ New role'}
            </button>
            {open ? (
                <div className="dialog-backdrop" role="presentation">
                    <div className="dialog" role="dialog" aria-modal="true" aria-labelledby="role-create-title">
                        <header className="dialog-header">
                            <div>
                                <span className="workspace-kicker">ROLE</span>
                                <h2 id="role-create-title">{locale === 'ar' ? 'إنشاء دور' : 'Create role'}</h2>
                            </div>
                            <button
                                type="button"
                                className="icon-button"
                                onClick={() => !saving && setOpen(false)}
                                aria-label={locale === 'ar' ? 'إغلاق' : 'Close'}
                            >×</button>
                        </header>
                        <form onSubmit={create} noValidate>
                            <label className="form-field">
                                <span>{locale === 'ar' ? 'اسم الدور' : 'Role name'} *</span>
                                <input
                                    value={name}
                                    onChange={(event) => setName(event.target.value)}
                                    disabled={saving}
                                    maxLength={100}
                                    required
                                    autoFocus
                                />
                                <small>
                                    {locale === 'ar'
                                        ? 'يبدأ الدور الجديد بلا صلاحيات، التزامًا بمبدأ أقل صلاحية.'
                                        : 'The new role starts with no permissions, following least privilege.'}
                                </small>
                            </label>
                            {error ? <p role="alert" className="field-error">{error}</p> : null}
                            <footer className="dialog-actions">
                                <button
                                    type="button"
                                    className="btn"
                                    onClick={() => !saving && setOpen(false)}
                                    disabled={saving}
                                >{locale === 'ar' ? 'إلغاء' : 'Cancel'}</button>
                                <button
                                    type="submit"
                                    className="btn primary"
                                    disabled={saving || !name.trim()}
                                >
                                    {saving
                                        ? (locale === 'ar' ? 'جارٍ الحفظ…' : 'Saving…')
                                        : (locale === 'ar' ? 'إنشاء' : 'Create')}
                                </button>
                            </footer>
                        </form>
                    </div>
                </div>
            ) : null}
        </>
    );
}
