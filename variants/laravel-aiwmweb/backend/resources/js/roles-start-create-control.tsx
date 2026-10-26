import React from 'react';
import {
    capabilityReason,
    resolveCapability,
    type ActionContract,
    type FrontendContext,
    type WorkspaceRoute,
} from './core';
import { useLocale } from './i18n';

export const ROLES_START_CREATE_OPERATION_ID = 'AIMW-CONT-6CB3D0BE1B';

export function RolesStartCreateControl({
    context,
    route,
    busy = false,
    onAvailable,
}: {
    context: FrontendContext;
    route: WorkspaceRoute;
    busy?: boolean;
    onAvailable: (contract: ActionContract) => void;
}) {
    const { locale } = useLocale();
    const state = resolveCapability(context, route, 'roles.create');
    const contract = context.actions['roles.create'];
    const enabled = !busy && state.state === 'enabled' && Boolean(contract);
    const reason = busy
        ? (locale === 'ar' ? 'جارٍ تنفيذ عملية أدوار أخرى…' : 'Another role operation is in progress…')
        : capabilityReason(state, locale);

    return (
        <span className="control-with-reason">
            <button
                type="button"
                className="btn primary"
                data-canonical-operation={ROLES_START_CREATE_OPERATION_ID}
                disabled={!enabled}
                aria-disabled={!enabled}
                aria-busy={busy ? 'true' : 'false'}
                title={enabled ? (locale === 'ar' ? 'دور جديد' : 'New role') : reason}
                onClick={() => {
                    if (enabled && contract) onAvailable(contract);
                }}
            >
                {locale === 'ar' ? '＋ دور جديد' : '＋ New role'}
            </button>
            {!enabled ? <small>{reason}</small> : null}
        </span>
    );
}
