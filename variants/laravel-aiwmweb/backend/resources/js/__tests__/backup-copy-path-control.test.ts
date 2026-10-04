import { describe, expect, it, vi } from 'vitest';
import {
    BACKUP_COPY_PATH_OPERATION_ID,
    BACKUP_COPY_PATH_SOURCE_OPERATION_KEY,
    backupCopyPathValue,
    canCopyBackupPath,
    copyBackupPath,
} from '../backup-copy-path-control';
import type { FrontendContext } from '../core';

const context = (
    permissions: string[] = ['backup.manage', 'backups.view'],
    api: Record<string, string> = { backups: '/tenants/alpha%20workspace/admin/backups' },
): FrontendContext => ({
    user: { id: 1, name: 'Operator', email: 'operator@example.test' },
    tenant: { slug: 'alpha workspace', name: 'Alpha' },
    tenants: [{ slug: 'alpha workspace', name: 'Alpha' }],
    permissions,
    connectors: [],
    capabilities: {},
    api,
    actions: {},
});

describe('canonical Backup CopyPathAsync visible control', () => {
    it('binds the exact operation and copies only the server-issued active-tenant backup path', async () => {
        const writeText = vi.fn().mockResolvedValue(undefined);
        expect(BACKUP_COPY_PATH_OPERATION_ID).toBe('AIMW-BILL-141E898A90');
        expect(BACKUP_COPY_PATH_SOURCE_OPERATION_KEY).toContain('BackupRestore.razor');
        expect(BACKUP_COPY_PATH_SOURCE_OPERATION_KEY).toContain('CopyPathAsync');
        expect(backupCopyPathValue(context())).toBe('/tenants/alpha%20workspace/admin/backups');
        await expect(copyBackupPath(context(), { writeText })).resolves.toBe('succeeded');
        expect(writeText).toHaveBeenCalledOnce();
        expect(writeText).toHaveBeenCalledWith('/tenants/alpha%20workspace/admin/backups');
    });

    it('fails closed for foreign tenant, absolute URLs, filesystem paths, or missing endpoint', () => {
        expect(backupCopyPathValue(context(undefined, { backups: '/tenants/beta/admin/backups' }))).toBeNull();
        expect(backupCopyPathValue(context(undefined, { backups: 'https://example.test/backups' }))).toBeNull();
        expect(backupCopyPathValue(context(undefined, { backups: 'C:\\\\Backups' }))).toBeNull();
        expect(backupCopyPathValue(context(undefined, {}))).toBeNull();
    });

    it('requires both backup permissions', () => {
        expect(canCopyBackupPath(context())).toBe(true);
        expect(canCopyBackupPath(context(['*']))).toBe(true);
        expect(canCopyBackupPath(context(['backups.view']))).toBe(false);
        expect(canCopyBackupPath(context(['backup.manage']))).toBe(false);
    });

    it('does not report success when clipboard support is missing or the write rejects', async () => {
        await expect(copyBackupPath(context(), undefined)).resolves.toBe('unavailable');
        await expect(copyBackupPath(context(), { writeText: vi.fn().mockRejectedValue(new Error('denied')) })).resolves.toBe('failed');
    });
});
