type MaintenancePolicy = {
    older_than_days: number;
    keep_latest: number;
};

type MaintenanceRefreshPayload = {
    data?: {
        operation_id?: string;
        policy?: MaintenancePolicy;
        storage?: Record<string, unknown>;
        preview?: Record<string, unknown>;
    };
};

const OPERATION_ID = 'AIMW-AI-C5BC29CF27';

function displayValue(value: unknown): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    return String(value);
}

function setField(name: string, value: unknown): void {
    const node = document.querySelector<HTMLElement>(`[data-maintenance-field="${name}"]`);
    if (node) {
        node.textContent = displayValue(value);
    }
}

function policyControl(name: keyof MaintenancePolicy): HTMLSelectElement | null {
    return document.querySelector<HTMLSelectElement>(`[data-maintenance-policy="${name}"]`);
}

function selectedPolicy(): MaintenancePolicy | null {
    const olderThanDays = policyControl('older_than_days');
    const keepLatest = policyControl('keep_latest');
    if (!olderThanDays || !keepLatest) {
        return null;
    }

    const policy = {
        older_than_days: Number(olderThanDays.value),
        keep_latest: Number(keepLatest.value),
    };

    if (!Number.isInteger(policy.older_than_days) || !Number.isInteger(policy.keep_latest)) {
        return null;
    }

    return policy;
}

function setPolicyControlsDisabled(disabled: boolean): void {
    policyControl('older_than_days')?.toggleAttribute('disabled', disabled);
    policyControl('keep_latest')?.toggleAttribute('disabled', disabled);
}

function refreshUrl(endpoint: string, policy: MaintenancePolicy): string {
    const url = new URL(endpoint, window.location.origin);
    if (url.origin !== window.location.origin) {
        throw new Error('Cross-origin maintenance refresh endpoint rejected');
    }

    url.searchParams.set('older_than_days', String(policy.older_than_days));
    url.searchParams.set('keep_latest', String(policy.keep_latest));

    return url.toString();
}

export async function refreshMaintenancePreview(button: HTMLButtonElement, status: HTMLElement): Promise<void> {
    const endpoint = button.dataset.refreshUrl;
    const policy = selectedPolicy();
    if (!endpoint || !policy) {
        status.textContent = 'Could not refresh maintenance preview. No cleanup was run.';
        return;
    }

    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    setPolicyControlsDisabled(true);
    status.textContent = 'Refreshing maintenance preview…';

    try {
        const response = await fetch(refreshUrl(endpoint, policy), {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        if (!response.ok) {
            throw new Error(`Refresh failed with ${response.status}`);
        }

        const payload = await response.json() as MaintenanceRefreshPayload;
        const acceptedPolicy = payload.data?.policy;
        if (
            payload.data?.operation_id !== OPERATION_ID
            || !payload.data.storage
            || !payload.data.preview
            || acceptedPolicy?.older_than_days !== policy.older_than_days
            || acceptedPolicy?.keep_latest !== policy.keep_latest
        ) {
            throw new Error('Refresh response contract mismatch');
        }

        const storage = payload.data.storage;
        const preview = payload.data.preview;
        setField('record_count', storage.record_count);
        setField('site_count', storage.site_count);
        setField('oldest_operation_at', storage.oldest_operation_at);
        setField('newest_operation_at', storage.newest_operation_at);
        setField('storage', storage.storage);
        setField('older_than_days', acceptedPolicy.older_than_days);
        setField('removable_count', preview.removable_count);
        setField('total_count', preview.total_count);
        setField('keep_latest', acceptedPolicy.keep_latest);
        setField('cutoff', preview.cutoff);
        status.textContent = 'Maintenance preview refreshed.';
    } catch {
        status.textContent = 'Could not refresh maintenance preview. No cleanup was run.';
    } finally {
        button.disabled = false;
        button.setAttribute('aria-busy', 'false');
        setPolicyControlsDisabled(false);
    }
}

export function bindMaintenancePreviewRefresh(): void {
    const button = document.querySelector<HTMLButtonElement>('[data-maintenance-refresh]');
    const status = document.querySelector<HTMLElement>('[data-maintenance-refresh-status]');
    const olderThanDays = policyControl('older_than_days');
    const keepLatest = policyControl('keep_latest');
    if (!button || !status || !olderThanDays || !keepLatest) {
        return;
    }

    const refresh = (): void => {
        void refreshMaintenancePreview(button, status);
    };

    button.addEventListener('click', refresh);
    olderThanDays.addEventListener('change', refresh);
    keepLatest.addEventListener('change', refresh);
}

document.addEventListener('DOMContentLoaded', bindMaintenancePreviewRefresh);
