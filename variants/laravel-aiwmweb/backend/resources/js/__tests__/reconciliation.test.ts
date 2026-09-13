import { describe, expect, it, vi } from 'vitest';
import { AuthoritativeReconciliationError, mutateThenReconcile } from '../reconciliation';

describe('visible-control mutation reconciliation', () => {
    it('does not resolve a mutation until the authoritative refresh completes', async () => {
        const order: string[] = [];

        const result = await mutateThenReconcile(
            async () => {
                order.push('mutate');
                return { id: 7 };
            },
            async () => {
                order.push('reconcile');
            },
        );

        expect(result).toEqual({ id: 7 });
        expect(order).toEqual(['mutate', 'reconcile']);
    });

    it('surfaces a committed mutation whose authoritative refresh fails', async () => {
        const mutation = vi.fn().mockResolvedValue({ id: 7 });
        const refreshFailure = new Error('authoritative GET failed');
        const reconcile = vi.fn().mockRejectedValue(refreshFailure);

        const promise = mutateThenReconcile(mutation, reconcile);

        await expect(promise).rejects.toBeInstanceOf(AuthoritativeReconciliationError);
        await expect(promise).rejects.toMatchObject({
            message: 'The server accepted the operation, but the authoritative refresh failed. Reload this screen before repeating the operation.',
            cause: refreshFailure,
        });
        expect(mutation).toHaveBeenCalledTimes(1);
        expect(reconcile).toHaveBeenCalledTimes(1);
    });

    it('does not attempt reconciliation when the backend mutation itself fails', async () => {
        const mutationFailure = new Error('mutation rejected');
        const mutation = vi.fn().mockRejectedValue(mutationFailure);
        const reconcile = vi.fn();

        await expect(mutateThenReconcile(mutation, reconcile)).rejects.toBe(mutationFailure);
        expect(reconcile).not.toHaveBeenCalled();
    });
});
