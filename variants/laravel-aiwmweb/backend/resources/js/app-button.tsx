import React from 'react';

export const APP_BUTTON_CLICK_OPERATION_ID = 'AIMW-PLAT-57E0113F24';
export const APP_BUTTON_LINK_OPERATION_ID = 'AIMW-PLAT-827A8F1C0D';

type AppButtonCommonProps = {
    busy?: boolean;
    ariaPressed?: boolean;
    icon?: React.ReactNode;
    canonicalOperationId?: string;
    className?: string;
    children?: React.ReactNode;
};

type AppButtonActionProps = AppButtonCommonProps
    & Omit<React.ButtonHTMLAttributes<HTMLButtonElement>, 'type' | 'className' | 'children'>
    & {
        href?: undefined;
        type?: 'button' | 'submit' | 'reset';
    };

type AppButtonLinkProps = AppButtonCommonProps
    & Omit<React.AnchorHTMLAttributes<HTMLAnchorElement>, 'href' | 'className' | 'children' | 'onClick' | 'tabIndex'>
    & {
        href: string;
        disabled?: boolean;
    };

export type AppButtonProps = AppButtonActionProps | AppButtonLinkProps;

function AppButtonContent({ busy, icon, children }: Pick<AppButtonCommonProps, 'busy' | 'icon' | 'children'>) {
    return (
        <>
            {busy ? <span className="app-button__spinner" aria-hidden="true" /> : icon ? <span className="app-button__icon" aria-hidden="true">{icon}</span> : null}
            {children}
        </>
    );
}

export function AppButton(props: AppButtonProps) {
    if (typeof props.href === 'string' && props.href.trim().length > 0) {
        const {
            href,
            target,
            disabled = false,
            busy = false,
            ariaPressed,
            icon,
            canonicalOperationId,
            className = '',
            children,
            ...anchorProps
        } = props as AppButtonLinkProps;

        return (
            <a
                {...anchorProps}
                className={`btn app-button ${className}`.trim()}
                href={disabled ? undefined : href}
                target={target}
                tabIndex={disabled ? -1 : undefined}
                aria-disabled={disabled}
                aria-busy={busy}
                aria-pressed={ariaPressed}
                data-canonical-operation={canonicalOperationId ?? APP_BUTTON_LINK_OPERATION_ID}
                data-canonical-component-operation={APP_BUTTON_LINK_OPERATION_ID}
            >
                <AppButtonContent busy={busy} icon={icon} children={children} />
            </a>
        );
    }

    const {
        href: _href,
        type = 'button',
        disabled = false,
        busy = false,
        ariaPressed,
        icon,
        canonicalOperationId,
        className = '',
        children,
        ...buttonProps
    } = props as AppButtonActionProps & { href?: string };

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
            <AppButtonContent busy={busy} icon={icon} children={children} />
        </button>
    );
}
