import React from 'react';

export const APP_BUTTON_CLICK_OPERATION_ID = 'AIMW-PLAT-57E0113F24';
export const APP_BUTTON_LINK_OPERATION_ID = 'AIMW-PLAT-827A8F1C0D';

type AppButtonProps = Omit<React.ButtonHTMLAttributes<HTMLButtonElement>, 'type'> & {
    type?: 'button' | 'submit' | 'reset';
    href?: string | null;
    target?: React.HTMLAttributeAnchorTarget;
    busy?: boolean;
    ariaPressed?: boolean;
    icon?: React.ReactNode;
    canonicalOperationId?: string;
};

export function AppButton({
    type = 'button',
    href,
    target,
    disabled = false,
    busy = false,
    ariaPressed,
    icon,
    canonicalOperationId,
    className = '',
    children,
    onClick,
    tabIndex,
    ...sharedProps
}: AppButtonProps) {
    const hasEffectiveHref = typeof href === 'string' && href.trim().length > 0;
    const content = (
        <>
            {busy ? <span className="app-button__spinner" aria-hidden="true" /> : icon ? <span className="app-button__icon" aria-hidden="true">{icon}</span> : null}
            {children}
        </>
    );

    if (hasEffectiveHref) {
        const anchorProps = sharedProps as React.AnchorHTMLAttributes<HTMLAnchorElement>;

        return (
            <a
                {...anchorProps}
                className={`btn app-button ${className}`.trim()}
                href={disabled ? undefined : href ?? undefined}
                target={target}
                tabIndex={disabled ? -1 : tabIndex}
                aria-disabled={disabled}
                aria-busy={busy}
                aria-pressed={ariaPressed}
                data-canonical-operation={canonicalOperationId ?? APP_BUTTON_LINK_OPERATION_ID}
                data-canonical-component-operation={APP_BUTTON_LINK_OPERATION_ID}
            >
                {content}
            </a>
        );
    }

    return (
        <button
            {...sharedProps}
            className={`btn app-button ${className}`.trim()}
            type={type}
            disabled={disabled}
            tabIndex={tabIndex}
            aria-busy={busy}
            aria-pressed={ariaPressed}
            onClick={onClick}
            data-canonical-operation={canonicalOperationId ?? APP_BUTTON_CLICK_OPERATION_ID}
            data-canonical-component-operation={APP_BUTTON_CLICK_OPERATION_ID}
        >
            {content}
        </button>
    );
}
