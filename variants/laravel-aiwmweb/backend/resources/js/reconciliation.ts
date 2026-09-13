export class AuthoritativeReconciliationError extends Error {
    public readonly cause: unknown;

    constructor(cause: unknown) {
        super('The server accepted the operation, but the authoritative refresh failed. Reload this screen before repeating the operation.');
        this.name = 'AuthoritativeReconciliationError';
        this.cause = cause;
    }
}

export async function mutateThenReconcile<T>(
    mutate: () => Promise<T>,
    reconcile: () => Promise<void>,
): Promise<T> {
    const result = await mutate();

    try {
        await reconcile();
    } catch (error) {
        throw new AuthoritativeReconciliationError(error);
    }

    return result;
}
