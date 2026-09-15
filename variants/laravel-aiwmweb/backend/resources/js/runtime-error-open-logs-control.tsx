import React from 'react';

export const RUNTIME_ERROR_OPEN_LOGS_OPERATION_ID = 'AIMW-OPER-21EC1BDE45';

export function runtimeErrorLogsHref(pathname: string): string | null {
    const match = pathname.match(/^\/tenants\/([^/]+)(?:\/|$)/);
    if (!match) return null;

    let tenantSlug: string;
    try {
        tenantSlug = decodeURIComponent(match[1]);
    } catch {
        return null;
    }

    if (!tenantSlug || tenantSlug.includes('/') || tenantSlug.includes('\\')) return null;

    return `/tenants/${encodeURIComponent(tenantSlug)}/logs`;
}

export function RuntimeErrorOpenLogsControl({ pathname }: { pathname?: string }) {
    const href = runtimeErrorLogsHref(pathname ?? (typeof window === 'undefined' ? '' : window.location.pathname));
    if (!href) return null;

    return (
        <a className="btn" href={href} data-canonical-operation={RUNTIME_ERROR_OPEN_LOGS_OPERATION_ID}>
            Open logs
        </a>
    );
}
