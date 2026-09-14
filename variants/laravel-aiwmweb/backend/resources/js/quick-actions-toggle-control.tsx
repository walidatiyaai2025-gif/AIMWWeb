import React, { useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Link } from 'react-router-dom';
import {
    resolveCapability,
    tenantUrl,
    workspaceRoutes,
    type FrontendContext,
    type WorkspaceRoute,
} from './core';
import { useLocale } from './i18n';

export const QUICK_ACTIONS_TOGGLE_OPERATION = 'AIMW-PLAT-4C37AC806E';

const SOURCE_QUICK_ACTION_PATHS = [
    '/sites/connect',
    '/module/posts',
    '/ai-center',
    '/content-planner',
    '/automation-center',
    '/module/execution',
    '/module/sync',
    '/module/seo-audit',
    '/notifications',
    '/module/reports',
] as const;

function availableSourceActions(context: FrontendContext): WorkspaceRoute[] {
    const allowedPaths = new Set<string>(SOURCE_QUICK_ACTION_PATHS);

    return workspaceRoutes.filter((route) => (
        allowedPaths.has(route.path)
        && resolveCapability(context, route).state === 'enabled'
    ));
}

export function QuickActionsToggleControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const [target, setTarget] = useState<HTMLElement | null>(null);
    const [open, setOpen] = useState(false);
    const triggerRef = useRef<HTMLButtonElement>(null);
    const actions = useMemo(() => availableSourceActions(context), [context]);

    useEffect(() => {
        setTarget(document.querySelector<HTMLElement>('.topbar-actions'));
    }, []);

    useEffect(() => {
        if (!open) return undefined;

        const handleKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setOpen(false);
                window.setTimeout(() => triggerRef.current?.focus(), 0);
            }
        };

        document.addEventListener('keydown', handleKeyDown);
        return () => document.removeEventListener('keydown', handleKeyDown);
    }, [open]);

    const toggle = () => setOpen((value) => !value);
    const close = () => {
        setOpen(false);
        window.setTimeout(() => triggerRef.current?.focus(), 0);
    };

    if (!target) return null;

    const trigger = createPortal(
        <button
            ref={triggerRef}
            type="button"
            className="btn quick-actions-trigger"
            data-canonical-operation={QUICK_ACTIONS_TOGGLE_OPERATION}
            aria-expanded={open}
            aria-haspopup="dialog"
            aria-controls="laravel-quick-actions-dialog"
            aria-label={locale === 'ar' ? 'فتح الإجراءات السريعة' : 'Open quick actions'}
            title={locale === 'ar' ? 'إجراءات سريعة' : 'Quick actions'}
            onClick={toggle}
        >
            <span aria-hidden="true">＋</span>{' '}
            {locale === 'ar' ? 'إجراء سريع' : 'Quick action'}
        </button>,
        target,
    );

    if (!open) return trigger;

    const dialog = createPortal(
        <div
            className="dialog-backdrop main-layout-overlay"
            role="presentation"
            onMouseDown={(event) => event.target === event.currentTarget && close()}
        >
            <section
                id="laravel-quick-actions-dialog"
                className="dialog main-layout-dialog"
                role="dialog"
                aria-modal="true"
                aria-labelledby="laravel-quick-actions-title"
            >
                <header className="dialog-header">
                    <div>
                        <span className="workspace-kicker">QUICK ACTIONS</span>
                        <h2 id="laravel-quick-actions-title">
                            {locale === 'ar' ? 'إجراءات سريعة' : 'Quick actions'}
                        </h2>
                        <p>
                            {locale === 'ar'
                                ? 'افتح فقط مسارات العمل المتاحة لحسابك وصلاحياتك الحالية.'
                                : 'Open only workflows available to your current tenant and permissions.'}
                        </p>
                    </div>
                    <button
                        type="button"
                        className="icon-button"
                        aria-label={locale === 'ar' ? 'إغلاق الإجراءات السريعة' : 'Close quick actions'}
                        onClick={close}
                    >×</button>
                </header>

                {actions.length > 0 ? (
                    <nav
                        className="main-layout-theme-grid"
                        aria-label={locale === 'ar' ? 'وجهات الإجراءات السريعة' : 'Quick action destinations'}
                    >
                        {actions.map((route) => (
                            <Link
                                key={route.key}
                                className="main-layout-theme-option"
                                to={tenantUrl(context.tenant.slug, route.path)}
                                onClick={close}
                            >
                                <span aria-hidden="true">{route.icon}</span>
                                <span>
                                    <strong>{route.label[locale]}</strong>
                                    <small>{route.description[locale]}</small>
                                </span>
                            </Link>
                        ))}
                    </nav>
                ) : (
                    <p role="status">
                        {locale === 'ar'
                            ? 'لا توجد إجراءات سريعة متاحة بصلاحيات الحساب الحالية.'
                            : 'No quick actions are available with the current tenant permissions.'}
                    </p>
                )}
            </section>
        </div>,
        document.body,
    );

    return <>{trigger}{dialog}</>;
}
