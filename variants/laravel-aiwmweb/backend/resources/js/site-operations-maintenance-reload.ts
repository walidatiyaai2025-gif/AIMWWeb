import { refreshMaintenanceSnapshot } from './site-operations-maintenance-refresh';

const OPERATION_ID = 'AIMW-AI-CAAC427FC0';

export async function reloadMaintenanceSnapshot(button: HTMLButtonElement, status: HTMLElement): Promise<void> {
    return refreshMaintenanceSnapshot(button, status, OPERATION_ID, {
        loading: 'Refreshing maintenance data…',
        success: 'Maintenance data refreshed.',
        failure: 'Could not refresh maintenance data. No cleanup was run.',
    });
}

export function bindMaintenanceReload(): void {
    const button = document.querySelector<HTMLButtonElement>('[data-maintenance-reload]');
    const status = document.querySelector<HTMLElement>('[data-maintenance-refresh-status]');
    if (!button || !status) {
        return;
    }

    button.addEventListener('click', () => {
        void reloadMaintenanceSnapshot(button, status);
    });
}

document.addEventListener('DOMContentLoaded', bindMaintenanceReload);
