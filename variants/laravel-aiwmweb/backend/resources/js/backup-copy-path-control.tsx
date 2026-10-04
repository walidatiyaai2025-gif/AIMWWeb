import React, { useState } from 'react';
import { useToast } from './components';
import type { FrontendContext } from './core';
import { useLocale } from './i18n';

export const BACKUP_COPY_PATH_OPERATION_ID = 'AIMW-BILL-141E898A90';
export const BACKUP_COPY_PATH_SOURCE_OPERATION_KEY =
    'visible:src/AIWordPressManager.Web/Components/Pages/BackupRestore.razor:/backups | /module/backups:CopyPathAsync [CopyPathAsync]';

function hasPermission(context: FrontendContext, permission: string): boolean {
    return context.permissions.includes('*') || context.permissions.includes(permission);
}

export function canCopyBackupPath(context: FrontendContext): boolean {
    return hasPermission(context, 'backup.manage') && hasPermission(context, 'backups.view');
}

export function backupCopyPathValue(context: FrontendContext): string | null {
    const value = context.api.backups;
    if (typeof value !== 'string' || !value) return null;

    const prefix = `/tenants/${encodeURIComponent(context.tenant.slug)}/`;
    if (!value.startsWith(prefix)) return null;
    if (value.includes('://') || value.includes('\\\\')) return null;

    return value;
}

export async function copyBackupPath(
    context: FrontendContext,
    clipboard: Pick<Clipboard, 'writeText'> | undefined = navigator.clipboard,
): Promise<'succeeded' | 'unavailable' | 'failed'> {
    const value = backupCopyPathValue(context);
    if (!value || !clipboard || typeof clipboard.writeText !== 'function') return 'unavailable';

    try {
        await clipboard.writeText(value);
        return 'succeeded';
    } catch {
        return 'failed';
    }
}

export function BackupCopyPathControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const { notify } = useToast();
    const [busy, setBusy] = useState(false);
    const value = backupCopyPathValue(context);

    if (!canCopyBackupPath(context) || !value) return null;

    const copy = async () => {
        if (busy) return;
        setBusy(true);
        const result = await copyBackupPath(context);
        setBusy(false);

        if (result === 'succeeded') {
            notify(locale === 'ar' ? 'تم نسخ مسار النسخ الاحتياطية.' : 'Backup path copied.', 'success');
            return;
        }

        notify(
            locale === 'ar'
                ? 'تعذر تأكيد نسخ المسار من المتصفح. لم يتم الإبلاغ عن نجاح.'
                : 'The browser did not confirm the clipboard write. No copy success was reported.',
            'error',
        );
    };

    return (
        <section className="panel" aria-label={locale === 'ar' ? 'مسار النسخ الاحتياطية' : 'Backup path'}>
            <div className="panel-header">
                <div>
                    <span className="workspace-kicker">BACKUP LOCATION</span>
                    <code data-backup-copy-path-value>{value}</code>
                </div>
                <button
                    type="button"
                    className="btn"
                    data-canonical-operation={BACKUP_COPY_PATH_OPERATION_ID}
                    data-source-operation-key={BACKUP_COPY_PATH_SOURCE_OPERATION_KEY}
                    disabled={busy}
                    aria-busy={busy ? 'true' : 'false'}
                    onClick={() => void copy()}
                >
                    {locale === 'ar' ? 'نسخ المسار' : 'Copy path'}
                </button>
            </div>
        </section>
    );
}
