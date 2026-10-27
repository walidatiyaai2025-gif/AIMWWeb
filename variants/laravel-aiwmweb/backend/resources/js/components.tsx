import { AppDialogCloseControl } from './app-dialog-close-control';
import React, {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import { previousAppDataGridPage } from './app-data-grid-previous-page-control';
import { Link, useLocation, useNavigate, useParams } from 'react-router-dom';
import {
    capabilityReason,
    groups,
    isPathMatch,
    resolveCapability,
    switchTenantPath,
    tenantUrl,
    workspaceRoutes,
    type ActionContract,
    type FrontendContext,
    type WorkspaceRoute,
} from './core';
import { commonText, useLocale } from './i18n';
import { WordPressUsersWorkingStateControl } from './wordpress-users-working-state-control';

interface Toast {
    id: number;
    tone: 'success' | 'error' | 'info';
    message: string;
}

export const GLOBAL_NOTIFICATION_LOGS_OPERATION_ID = 'AIMW-EMAI-9EB09C490D';

const ToastContext = createContext<{ notify: (message: string, tone?: Toast['tone']) => void } | null>(null);

export function ToastProvider({ children, logsHref }: { children: React.ReactNode; logsHref?: string }) {
    const { locale } = useLocale();
    const [toasts, setToasts] = useState<Toast[]>([]);
    const nextId = useRef(0);

    const notify = useCallback((message: string, tone: Toast['tone'] = 'info') => {
        const id = ++nextId.current;
        setToasts((current) => [...current, { id, tone, message }]);
        window.setTimeout(() => setToasts((current) => current.filter((toast) => toast.id !== id)), 4500);
    }, []);

    return (
        <ToastContext.Provider value={{ notify }}>
            {children}
            <div className="toast-stack" aria-live="polite" aria-atomic="false">
                {toasts.map((toast) => (
                    <div key={toast.id} className={`toast toast-${toast.tone}`} role={toast.tone === 'error' ? 'alert' : 'status'}>
                        <span>{toast.message}</span>
                        {toast.tone === 'error' && toast.message.trim() && logsHref ? (
                            <a
                                className="toast-log-link"
                                href={logsHref}
                                data-canonical-operation={GLOBAL_NOTIFICATION_LOGS_OPERATION_ID}
                            >
                                {locale === 'ar' ? 'عرض التفاصيل في السجلات' : 'View details in logs'}
                            </a>
                        ) : null}
                    </div>
                ))}
            </div>
        </ToastContext.Provider>
    );
}

export function useToast() {
    const value = useContext(ToastContext);
    if (!value) throw new Error('useToast must be used inside ToastProvider.');
    return value;
}

export const STATE_PANEL_RETRY_OPERATION_ID = 'AIMW-SYNC-AE544B4684';

export function StatePanel({
    tone = 'neutral',
    title,
    children,
    action,
    onRetry,
    retryBusy = false,
    retryText,
    retryingText,
    retryAriaLabel,
}: {
    tone?: 'neutral' | 'warning' | 'danger';
    title: string;
    children: React.ReactNode;
    action?: React.ReactNode;
    onRetry?: () => void;
    retryBusy?: boolean;
    retryText?: string;
    retryingText?: string;
    retryAriaLabel?: string;
}) {
    const { locale } = useLocale();
    const effectiveRetryText = retryText ?? (locale === 'ar' ? 'حاول مرة أخرى' : 'Try again');
    const effectiveRetryingText = retryingText ?? (locale === 'ar' ? 'جارٍ المحاولة مرة أخرى…' : 'Trying again...');
    const retryControl = onRetry ? (
        <button
            type="button"
            className="btn"
            data-canonical-operation={STATE_PANEL_RETRY_OPERATION_ID}
            aria-label={retryAriaLabel ?? effectiveRetryText}
            aria-busy={retryBusy ? 'true' : 'false'}
            disabled={retryBusy}
            onClick={() => {
                if (!retryBusy) onRetry();
            }}
        >
            <span aria-hidden="true">↻</span>
            <span>{retryBusy ? effectiveRetryingText : effectiveRetryText}</span>
        </button>
    ) : null;

    return (
        <section
            className={`state-panel state-${tone}`}
            role={tone === 'danger' ? 'alert' : 'status'}
            aria-busy={retryBusy ? 'true' : undefined}
        >
            <div className="state-icon" aria-hidden="true">{tone === 'danger' ? '!' : tone === 'warning' ? '◷' : '◇'}</div>
            <div className="state-copy">
                <h2>{title}</h2>
                <div>{children}</div>
            </div>
            {(retryControl || action) ? (
                <div className="state-action">
                    {retryControl}
                    {action}
                </div>
            ) : null}
        </section>
    );
}


export const STATE_BANNER_RETRY_OPERATION_ID = 'AIMW-SYNC-8719C812DC';

type StateBannerKind = 'info' | 'success' | 'warning' | 'error' | 'offline' | 'cached' | 'partial';

function normalizeStateBannerKind(kind: string): StateBannerKind {
    const normalized = kind.trim().toLowerCase();
    if (normalized === 'success') return 'success';
    if (normalized === 'warning') return 'warning';
    if (normalized === 'error' || normalized === 'danger') return 'error';
    if (normalized === 'offline') return 'offline';
    if (normalized === 'cached' || normalized === 'stale') return 'cached';
    if (normalized === 'partial') return 'partial';
    return 'info';
}

export function StateBanner({
    title,
    kind = 'info',
    description,
    freshnessText,
    icon,
    busy = false,
    assertive = false,
    retryText,
    retryingText,
    retryAriaLabel,
    onRetry,
    action,
}: {
    title: string;
    kind?: string;
    description?: string;
    freshnessText?: string;
    icon?: string;
    busy?: boolean;
    assertive?: boolean;
    retryText?: string;
    retryingText?: string;
    retryAriaLabel?: string;
    onRetry?: () => void;
    action?: React.ReactNode;
}) {
    const { locale } = useLocale();
    const normalizedKind = normalizeStateBannerKind(kind);
    const effectiveRetryText = retryText ?? (locale === 'ar' ? 'إعادة المحاولة' : 'Retry');
    const effectiveRetryingText = retryingText ?? (locale === 'ar' ? 'جارٍ إعادة المحاولة…' : 'Retrying...');
    const effectiveIcon = icon ?? ({
        success: '✓',
        warning: '!',
        error: '×',
        offline: '⌁',
        cached: '◷',
        partial: '◐',
        info: 'i',
    } satisfies Record<StateBannerKind, string>)[normalizedKind];
    const isAssertive = assertive || normalizedKind === 'error';

    const retryControl = onRetry ? (
        <button
            type="button"
            className="btn"
            data-canonical-operation={STATE_BANNER_RETRY_OPERATION_ID}
            aria-label={retryAriaLabel ?? effectiveRetryText}
            aria-busy={busy ? 'true' : 'false'}
            disabled={busy}
            onClick={() => {
                if (!busy) onRetry();
            }}
        >
            <span aria-hidden="true">↻</span>
            <span>{busy ? effectiveRetryingText : effectiveRetryText}</span>
        </button>
    ) : null;

    return (
        <aside
            className={`state-banner state-banner-${normalizedKind}`}
            role={isAssertive ? 'alert' : 'status'}
            aria-live={isAssertive ? 'assertive' : 'polite'}
            aria-atomic="true"
            aria-busy={busy ? 'true' : 'false'}
            data-state={normalizedKind}
            data-retains-content="true"
        >
            <span className="state-banner-icon" aria-hidden="true">{effectiveIcon}</span>
            <div className="state-banner-content">
                <strong>{title}</strong>
                {description ? <span>{description}</span> : null}
                {freshnessText ? <small>{freshnessText}</small> : null}
            </div>
            {(retryControl || action) ? (
                <div className="state-banner-actions">
                    {retryControl}
                    {action}
                </div>
            ) : null}
        </aside>
    );
}

export function LoadingState() {
    const { text } = useLocale();
    return (
        <section className="loading-grid" role="status" aria-busy="true" aria-label={text(commonText.loading)}>
            {Array.from({ length: 6 }, (_, index) => <div className="skeleton-card" key={index} />)}
        </section>
    );
}

export function DataTable({
    rows,
    renderActions,
}: {
    rows: Array<Record<string, unknown>>;
    renderActions?: (row: Record<string, unknown>) => React.ReactNode;
}) {
    const { locale } = useLocale();
    const preferred = ['id', 'title', 'name', 'status', 'type', 'site', 'updated_at', 'updatedAt', 'created_at'];
    const available = new Set(rows.flatMap((row) => Object.keys(row)));
    const columns = preferred.filter((column) => available.has(column)).slice(0, 6);
    const fallbackColumns = columns.length ? columns : Array.from(available).slice(0, 6);

    const format = (value: unknown) => {
        if (value === null || value === undefined || value === '') return '—';
        if (typeof value === 'boolean') return value ? (locale === 'ar' ? 'نعم' : 'Yes') : (locale === 'ar' ? 'لا' : 'No');
        if (typeof value === 'object') return JSON.stringify(value);
        return String(value);
    };

    return (
        <div className="table-scroll" tabIndex={0} role="region" aria-label={locale === 'ar' ? 'جدول البيانات' : 'Data table'}>
            <table className="data-table">
                <thead>
                    <tr>
                        {fallbackColumns.map((column) => <th key={column} scope="col">{column.replaceAll('_', ' ')}</th>)}
                        {renderActions ? <th scope="col">{locale === 'ar' ? 'إجراءات' : 'Actions'}</th> : null}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row, index) => (
                        <tr key={String(row.id ?? index)}>
                            {fallbackColumns.map((column) => <td key={column}>{format(row[column])}</td>)}
                            {renderActions ? <td>{renderActions(row)}</td> : null}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

export function Pagination({
    page,
    lastPage,
    onPage,
    previousOperationId,
}: {
    page: number;
    lastPage: number;
    onPage: (page: number) => void;
    previousOperationId?: string;
}) {
    const { text, locale } = useLocale();
    if (lastPage <= 1) return null;
    return (
        <nav className="pagination" aria-label={locale === 'ar' ? 'ترقيم الصفحات' : 'Pagination'}>
            <button type="button" className="btn" data-canonical-operation={previousOperationId} onClick={() => onPage(previousAppDataGridPage(page))} disabled={page <= 1}>{text(commonText.previous)}</button>
            <span aria-live="polite">{locale === 'ar' ? `صفحة ${page} من ${lastPage}` : `Page ${page} of ${lastPage}`}</span>
            <button type="button" className="btn" onClick={() => onPage(page + 1)} disabled={page >= lastPage}>{text(commonText.next)}</button>
        </nav>
    );
}

export const WORDPRESS_USERS_START_CREATE_OPERATION_ID = 'AIMW-CONT-BCE1211CE3';
export const TAXONOMY_START_CREATE_OPERATION_ID = 'AIMW-TAXO-005925568C';
export const TAXONOMY_SAVE_SUBMIT_OPERATION_ID = 'AIMW-TAXO-39CDD7EF81';
export const TAXONOMY_RESET_FORM_OPERATION_ID = 'AIMW-TAXO-5D39DA68B7';

export function ActionButton({
    route,
    actionKey,
    context,
    onAvailable,
}: {
    route: WorkspaceRoute;
    actionKey: string;
    context: FrontendContext;
    onAvailable: (action: ActionContract) => void;
}) {
    const { locale } = useLocale();
    const state = resolveCapability(context, route, actionKey);
    const contract = context.actions[actionKey];
    const enabled = state.state === 'enabled' && Boolean(contract);
    const isWordPressUsersStartCreate = route.key === 'wp-users' && actionKey === 'wordpress-users.save';
    const isTaxonomyStartCreate = route.key === 'taxonomy' && actionKey === 'taxonomy.manage';
    const label = isTaxonomyStartCreate
        ? (locale === 'ar' ? 'إضافة جديد' : 'Add new')
        : isWordPressUsersStartCreate
            ? (locale === 'ar' ? 'إضافة مستخدم' : 'Add user')
            : actionKey.split('.').slice(-1)[0].replaceAll('-', ' ').replaceAll('_', ' ');
    const canonicalOperation = isTaxonomyStartCreate
        ? TAXONOMY_START_CREATE_OPERATION_ID
        : isWordPressUsersStartCreate
            ? WORDPRESS_USERS_START_CREATE_OPERATION_ID
            : undefined;
    const reason = enabled ? '' : capabilityReason(state, locale);

    return (
        <span className="control-with-reason">
            <button
                type="button"
                className="btn"
                disabled={!enabled}
                aria-disabled={!enabled}
                title={reason || label}
                data-canonical-operation={canonicalOperation}
                onClick={() => contract && onAvailable(contract)}
            >
                {label.charAt(0).toUpperCase() + label.slice(1)}
            </button>
            {!enabled ? <small>{reason}</small> : null}
        </span>
    );
}

export function ActionDialog({
    open,
    actionKey,
    contract,
    onClose,
    onSubmit,
    busy,
    serverErrors,
    showCloseButton = true,
}: {
    open: boolean;
    actionKey: string;
    contract: ActionContract | null;
    onClose: () => void;
    onSubmit: (values: Record<string, string | number>) => void;
    busy: boolean;
    serverErrors: Record<string, string[]>;
    showCloseButton?: boolean;
}) {
    const { locale, text } = useLocale();
    const [values, setValues] = useState<Record<string, string>>({});
    const [errors, setErrors] = useState<Record<string, string>>({});
    const dialogRef = useRef<HTMLDivElement>(null);
    const firstFieldRef = useRef<HTMLInputElement>(null);
    const isTaxonomySaveSubmit = actionKey === 'taxonomy.manage';
    const isTaxonomyResetForm = actionKey === 'taxonomy.manage';
    const isWordPressUsersResetForm = actionKey === 'wordpress-users.save';
    const isResetForm = isTaxonomyResetForm || isWordPressUsersResetForm;

    const closeAndReset = () => {
        setValues({});
        setErrors({});
        onClose();
    };

    useEffect(() => {
        if (!open) return;
        setValues({});
        setErrors({});
        const previous = document.activeElement as HTMLElement | null;
        window.setTimeout(() => firstFieldRef.current?.focus() ?? dialogRef.current?.focus(), 0);
        const handleKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') onClose();
        };
        document.addEventListener('keydown', handleKey);
        return () => {
            document.removeEventListener('keydown', handleKey);
            previous?.focus?.();
        };
    }, [open, onClose]);

    if (!open || !contract) return null;
    const fields = contract.fields ?? [];

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        const nextErrors: Record<string, string> = {};
        for (const field of fields) {
            if (field.required && !values[field.key]?.trim()) nextErrors[field.key] = text(commonText.required);
            if (field.type === 'email' && values[field.key] && !/^\S+@\S+\.\S+$/.test(values[field.key])) {
                nextErrors[field.key] = locale === 'ar' ? 'أدخل بريدًا إلكترونيًا صالحًا.' : 'Enter a valid email address.';
            }
        }
        setErrors(nextErrors);
        if (Object.keys(nextErrors).length) return;
        onSubmit(Object.fromEntries(Object.entries(values).map(([key, value]) => {
            const field = fields.find((candidate) => candidate.key === key);
            return [key, field?.type === 'number' ? Number(value) : value];
        })));
    };

    return (
        <div className="dialog-backdrop" role="presentation" onMouseDown={(event) => event.target === event.currentTarget && onClose()}>
            <div ref={dialogRef} className="dialog" role="dialog" aria-modal="true" aria-labelledby="action-dialog-title" tabIndex={-1}>
                <header className="dialog-header">
                    <div>
                        <span className="workspace-kicker">ACTION</span>
                        <h2 id="action-dialog-title">{actionKey}</h2>
                    </div>
                    <AppDialogCloseControl onClose={onClose} showCloseButton={showCloseButton} ariaLabel={text(commonText.close)} />
                </header>
                <form onSubmit={submit} noValidate>
                    {fields.length === 0 ? (
                        <StatePanel title={locale === 'ar' ? 'لا توجد حقول مطلوبة' : 'No fields required'}>
                            {locale === 'ar' ? 'سيتم إرسال الإجراء إلى عقد الخادم كما هو.' : 'The action will be sent to the server contract as-is.'}
                        </StatePanel>
                    ) : fields.map((field, index) => (
                        <label className="form-field" key={field.key}>
                            <span>{field.label[locale]}{field.required ? ' *' : ''}</span>
                            {field.type === 'textarea' ? (
                                <textarea value={values[field.key] ?? ''} onChange={(event) => setValues((current) => ({ ...current, [field.key]: event.target.value }))} />
                            ) : field.type === 'select' ? (
                                <select value={values[field.key] ?? ''} onChange={(event) => setValues((current) => ({ ...current, [field.key]: event.target.value }))}>
                                    <option value="">—</option>
                                    {field.options?.map((option) => <option key={option.value} value={option.value}>{option.label[locale]}</option>)}
                                </select>
                            ) : (
                                <input
                                    ref={index === 0 ? firstFieldRef : undefined}
                                    type={field.type}
                                    value={values[field.key] ?? ''}
                                    onChange={(event) => setValues((current) => ({ ...current, [field.key]: event.target.value }))}
                                    aria-invalid={Boolean(errors[field.key] || serverErrors[field.key])}
                                    aria-describedby={`${field.key}-error`}
                                />
                            )}
                            {(errors[field.key] || serverErrors[field.key]?.[0]) ? <small id={`${field.key}-error`} className="field-error">{errors[field.key] ?? serverErrors[field.key]?.[0]}</small> : null}
                        </label>
                    ))}
                    <footer className="dialog-actions">
                        <button
                            type="button"
                            className="btn"
                            onClick={isResetForm ? closeAndReset : onClose}
                            disabled={busy}
                            data-canonical-operation={isWordPressUsersResetForm ? WORDPRESS_USERS_RESET_FORM_OPERATION_ID : isTaxonomyResetForm ? TAXONOMY_RESET_FORM_OPERATION_ID : undefined}
                        >
                            {isResetForm ? (locale === 'ar' ? 'إلغاء' : 'Cancel') : text(commonText.close)}
                        </button>
                        {actionKey === 'wordpress-users.save' ? (
                            <WordPressUsersWorkingStateControl busy={busy} locale={locale} />
                        ) : (
                            <button
                                type="submit"
                                className="btn primary"
                                disabled={busy}
                                aria-busy={busy ? 'true' : 'false'}
                                data-canonical-operation={isTaxonomySaveSubmit ? TAXONOMY_SAVE_SUBMIT_OPERATION_ID : undefined}
                            >
                                {isTaxonomySaveSubmit
                                    ? (busy
                                        ? (locale === 'ar' ? 'جارٍ الحفظ' : 'Saving')
                                        : (locale === 'ar' ? 'حفظ' : 'Save'))
                                    : (busy ? text(commonText.loading) : text(commonText.submit))}
                            </button>
                        )}
                    </footer>
                </form>
            </div>
        </div>
    );
}

function CommandPalette({
    context,
    open,
    onClose,
    triggerRef,
}: {
    context: FrontendContext;
    open: boolean;
    onClose: () => void;
    triggerRef: React.RefObject<HTMLButtonElement | null>;
}) {
    const { locale, text } = useLocale();
    const navigate = useNavigate();
    const [query, setQuery] = useState('');
    const inputRef = useRef<HTMLInputElement>(null);
    const onCloseRef = useRef(onClose);
    const allowedRoutes = useMemo(
        () => workspaceRoutes.filter((route) => !route.hidden && resolveCapability(context, route).state === 'enabled'),
        [context],
    );

    useEffect(() => {
        onCloseRef.current = onClose;
    }, [onClose]);

    useEffect(() => {
        if (!open) return;

        setQuery('');
        const trigger = triggerRef.current;
        const focusTimer = window.setTimeout(() => inputRef.current?.focus(), 0);
        const handleKey = (event: KeyboardEvent) => {
            if (event.key !== 'Escape') return;
            event.preventDefault();
            event.stopPropagation();
            onCloseRef.current();
        };

        document.addEventListener('keydown', handleKey);
        return () => {
            window.clearTimeout(focusTimer);
            document.removeEventListener('keydown', handleKey);
            window.setTimeout(() => trigger?.focus(), 0);
        };
    }, [open, triggerRef]);
    if (!open) return null;

    const normalizedQuery = query.trim().toLowerCase();
    const matches = allowedRoutes.filter((route) => [
        route.label.en,
        route.label.ar,
        route.description.en,
        route.description.ar,
        route.path,
        route.key,
    ].join(' ').toLowerCase().includes(normalizedQuery)).slice(0, 12);

    const runCommand = (route: WorkspaceRoute) => {
        if (resolveCapability(context, route).state !== 'enabled') return;
        navigate(tenantUrl(context.tenant.slug, route.path));
        onClose();
    };

    return (
        <div className="dialog-backdrop command-backdrop" role="presentation" onMouseDown={(event) => event.target === event.currentTarget && onClose()}>
            <section id="command-palette-dialog" className="command-dialog" role="dialog" aria-modal="true" aria-labelledby="command-title">
                <header>
                    <h2 id="command-title" className="sr-only">{text(commonText.quickSearch)}</h2>
                    <input ref={inputRef} value={query} onChange={(event) => setQuery(event.target.value)} placeholder={text(commonText.quickSearch)} aria-label={text(commonText.quickSearch)} aria-controls="command-palette-results" />
                    <button
                        type="button"
                        className="btn"
                        data-canonical-operation="AIMW-AI-D3A8A100B4"
                        aria-label={locale === 'ar' ? 'إغلاق البحث' : 'Close search'}
                        onClick={onClose}
                    >Esc</button>
                </header>
                <div id="command-palette-results" className="command-results" aria-live="polite" aria-atomic="false">
                    {matches.length === 0 ? (
                        <div className="command-empty" role="status">
                            <strong>{locale === 'ar' ? 'لا توجد وجهة متاحة مطابقة' : 'No matching available destination'}</strong>
                            <small>{locale === 'ar' ? 'جرّب اسم صفحة أو مسارًا تسمح به صلاحيات الحساب الحالية.' : 'Try a page name or route available to your current tenant permissions.'}</small>
                        </div>
                    ) : matches.map((route) => (
                        <button type="button" key={route.key} className="command-item" onClick={() => runCommand(route)}>
                            <span aria-hidden="true">{route.icon}</span>
                            <span><strong>{route.label[locale]}</strong><small>{route.description[locale]}</small></span>
                            <code>{route.path}</code>
                        </button>
                    ))}
                </div>
            </section>
        </div>
    );
}

export function AppShell({ context, children }: { context: FrontendContext; children: React.ReactNode }) {
    const { locale, direction, toggleLocale } = useLocale();
    const location = useLocation();
    const navigate = useNavigate();
    const params = useParams();
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const [collapsed, setCollapsed] = useState(() => window.localStorage.getItem('aiwm.sidebar') === 'collapsed');
    const [commandOpen, setCommandOpen] = useState(false);
    const commandTriggerRef = useRef<HTMLButtonElement>(null);
    const [mode, setMode] = useState<'dark' | 'light'>(() => window.localStorage.getItem('aiwm.mode') === 'light' ? 'light' : 'dark');

    const currentRoute = useMemo(
        () => workspaceRoutes.slice().sort((a, b) => b.path.length - a.path.length).find((route) => isPathMatch(route.path, location.pathname, context.tenant.slug)) ?? workspaceRoutes[0],
        [context.tenant.slug, location.pathname],
    );

    useEffect(() => {
        document.documentElement.dataset.mode = mode;
        window.localStorage.setItem('aiwm.mode', mode);
    }, [mode]);

    useEffect(() => {
        const syncAppearanceMode = (event: Event) => {
            const next = (event as CustomEvent<{ mode?: unknown }>).detail?.mode;
            if (next === 'dark' || next === 'light') setMode(next);
        };
        window.addEventListener('aiwm:appearance-mode', syncAppearanceMode);
        return () => window.removeEventListener('aiwm:appearance-mode', syncAppearanceMode);
    }, []);

    useEffect(() => {
        const handler = (event: KeyboardEvent) => {
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
                event.preventDefault();
                setCommandOpen(true);
            }
        };
        document.addEventListener('keydown', handler);
        return () => document.removeEventListener('keydown', handler);
    }, []);

    const toggleCollapse = () => {
        const next = !collapsed;
        setCollapsed(next);
        window.localStorage.setItem('aiwm.sidebar', next ? 'collapsed' : 'expanded');
        setSidebarOpen(true);
    };

    return (
        <div className={`app-shell ${collapsed ? 'sidebar-collapsed' : ''} ${sidebarOpen ? 'mobile-nav-open' : ''}`} dir={direction}>
            <a className="skip-link" href="#main-content">{locale === 'ar' ? 'انتقل إلى المحتوى الرئيسي' : 'Skip to main content'}</a>
            <aside className="sidebar" aria-label={locale === 'ar' ? 'التنقل الرئيسي' : 'Primary navigation'}>
                <div className="brand-row">
                    <Link className="brand" to={tenantUrl(context.tenant.slug, '/')} aria-label={locale === 'ar' ? 'لوحة التحكم' : 'Dashboard'}>
                        <span className="brand-mark" aria-hidden="true">AI</span>
                        <span className="brand-copy"><strong>AI WordPress Manager</strong><small>Laravel AIWMWeb</small></span>
                    </Link>
                    <button type="button" className="mobile-close" onClick={() => setSidebarOpen(false)} aria-label={locale === 'ar' ? 'إغلاق القائمة' : 'Close navigation'}>×</button>
                </div>
                <nav className="main-nav">
                    {(Object.keys(groups) as Array<keyof typeof groups>).map((groupKey) => {
                        const routes = workspaceRoutes.filter((route) => route.group === groupKey && !route.hidden);
                        if (!routes.length) return null;
                        const active = routes.some((route) => route.key === currentRoute.key);
                        return (
                            <details className="nav-section" key={groupKey} open={active || undefined}>
                                <summary><span>{groups[groupKey][locale]}</span><span aria-hidden="true">⌄</span></summary>
                                <div className="nav-links">
                                    {routes.map((route) => (
                                        <Link
                                            to={tenantUrl(context.tenant.slug, route.path)}
                                            key={route.key}
                                            className={route.key === currentRoute.key ? 'active' : ''}
                                            title={route.description[locale]}
                                            onClick={() => setSidebarOpen(false)}
                                        >
                                            <span className="nav-icon" aria-hidden="true">{route.icon}</span><span>{route.label[locale]}</span>
                                        </Link>
                                    ))}
                                </div>
                            </details>
                        );
                    })}
                </nav>
                <div className="sidebar-footer"><strong>Issue #257</strong><small>Frontend parity worker</small></div>
            </aside>
            <button className="nav-backdrop" type="button" tabIndex={-1} aria-label={locale === 'ar' ? 'إغلاق القائمة' : 'Close navigation'} onClick={() => setSidebarOpen(false)} />
            <div className="main-area">
                <header className="topbar">
                    <div className="topbar-title">
                        <button type="button" className="icon-button sidebar-toggle" onClick={() => { if (window.matchMedia('(max-width: 700px)').matches) setSidebarOpen(true); else toggleCollapse(); }} aria-label={locale === 'ar' ? 'تبديل القائمة الجانبية' : 'Toggle sidebar'}>☰</button>
                        <div className="heading-block">
                            <nav className="breadcrumb" aria-label={locale === 'ar' ? 'مسار الصفحة' : 'Breadcrumb'}><span>{groups[currentRoute.group][locale]}</span><b aria-hidden="true">›</b><strong>{currentRoute.label[locale]}</strong></nav>
                            <h1 id="page-title">{currentRoute.label[locale]}</h1>
                            <small>{currentRoute.description[locale]}</small>
                        </div>
                    </div>
                    <div className="topbar-actions">
                        <button
                            ref={commandTriggerRef}
                            id="command-palette-trigger"
                            type="button"
                            className="command-trigger"
                            onClick={() => setCommandOpen(true)}
                            aria-label={locale === 'ar' ? 'فتح البحث السريع' : 'Open quick search'}
                            aria-haspopup="dialog"
                            aria-controls="command-palette-dialog"
                            aria-expanded={commandOpen}
                            aria-keyshortcuts="Control+K"
                        ><span aria-hidden="true">⌕</span><span>{locale === 'ar' ? 'بحث سريع' : 'Quick search'}</span><kbd>Ctrl K</kbd></button>
                        <label className="tenant-picker">
                            <span className="sr-only">{locale === 'ar' ? 'تغيير الحساب' : 'Switch tenant'}</span>
                            <select
                                aria-label={locale === 'ar' ? 'تغيير الحساب' : 'Switch tenant'}
                                value={params.tenantSlug ?? context.tenant.slug}
                                onChange={(event) => navigate(switchTenantPath(location.pathname, event.target.value))}
                            >
                                {context.tenants.map((tenant) => <option value={tenant.slug} key={tenant.slug}>{tenant.name}</option>)}
                            </select>
                        </label>
                        <button type="button" className="icon-button" onClick={() => setMode(mode === 'dark' ? 'light' : 'dark')} aria-label={locale === 'ar' ? 'تبديل الوضع الفاتح والداكن' : 'Toggle light and dark mode'}>{mode === 'dark' ? '☾' : '☀'}</button>
                        <button type="button" className="language-switch" onClick={toggleLocale} aria-label={locale === 'ar' ? 'Switch to English' : 'التبديل إلى العربية'}>🌐 <span>{locale === 'ar' ? 'EN' : 'العربية'}</span></button>
                        <div className="user-chip" title={context.user.email}><span aria-hidden="true">{context.user.name.slice(0, 1).toUpperCase()}</span><div><strong>{context.user.name}</strong><small>{context.tenant.name}</small></div></div>
                    </div>
                </header>
                <main id="main-content" className="content" tabIndex={-1} aria-labelledby="page-title">{children}</main>
            </div>
            <CommandPalette context={context} open={commandOpen} onClose={() => setCommandOpen(false)} triggerRef={commandTriggerRef} />
        </div>
    );
}
