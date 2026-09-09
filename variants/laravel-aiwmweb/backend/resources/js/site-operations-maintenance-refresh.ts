type MaintenanceRefreshPayload = {
    data?: {
        operation_id?: string;
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

export async function refreshMaintenancePreview(button: HTMLButtonElement, status: HTMLElement): Promise<void> {
    const endpoint = button.dataset.refreshUrl;
    if (!endpoint) {
        status.textContent = 'Could not refresh maintenance preview. Sync remains disabled until this view is refreshed.';
        return;
    }

    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    status.textContent = 'Refreshing maintenance preview…';

    try {
        const response = await fetch(endpoint, {
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
        if (payload.data?.operation_id !== OPERATION_ID || !payload.data.storage || !payload.data.preview) {
            throw new Error('Refresh response contract mismatch');
        }

        const storage = payload.data.storage;
        const preview = payload.data.preview;
        setField('record_count', storage.record_count);
        setField('site_count', storage.site_count);
        setField('oldest_operation_at', storage.oldest_operation_at);
        setField('newest_operation_at', storage.newest_operation_at);
        setField('storage', storage.storage);
        setField('removable_count', preview.removable_count);
        setField('total_count', preview.total_count);
        setField('keep_latest', preview.keep_latest);
        setField('cutoff', preview.cutoff);
        status.textContent = 'Maintenance preview refreshed.';
    } catch {
        status.textContent = 'Could not refresh maintenance preview. Sync remains disabled until this view is refreshed.';
    } finally {
        button.disabled = false;
        button.setAttribute('aria-busy', 'false');
    }
}

export function bindMaintenancePreviewRefresh(): void {
    const button = document.querySelector<HTMLButtonElement>('[data-maintenance-refresh]');
    const status = document.querySelector<HTMLElement>('[data-maintenance-refresh-status]');
    if (!button || !status) {
        return;
    }

    button.addEventListener('click', () => {
        void refreshMaintenancePreview(button, status);
    });
}

document.addEventListener('DOMContentLoaded', bindMaintenancePreviewRefresh);
