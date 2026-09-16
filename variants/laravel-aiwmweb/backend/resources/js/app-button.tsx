import React from 'react';

export const APP_BUTTON_CLICK_OPERATION_ID = 'AIMW-PLAT-57E0113F24';

type AppButtonProps = Omit<React.ButtonHTMLAttributes<HTMLButtonElement>, 'type'> & {
    type?: 'button' | 'submit' | 'reset';
    busy?: boolean;
    ariaPressed?: boolean;
    icon?: React.ReactNode;
    canonicalOperationId?: string;
};

export function AppButton({
    type = 'button',
    disabled = false,
    busy = false,
    ariaPressed,
    icon,
    canonicalOperationId,
    className = '',
    children,
    ...buttonProps
}: AppButtonProps) {
    return (
        <button
            {...buttonProps}
            className={`btn app-button ${className}`.trim()}
            type={type}
            disabled={disabled}
            aria-busy={busy}
            aria-pressed={ariaPressed}
            data-canonical-operation={canonicalOperationId ?? APP_BUTTON_CLICK_OPERATION_ID}
            data-canonical-component-operation={APP_BUTTON_CLICK_OPERATION_ID}
        >
            {busy ? <span className="app-button__spinner" aria-hidden="true" /> : icon ? <span className="app-button__icon" aria-hidden="true">{icon}</span> : null}
            {children}
        </button>
    );
}