import React, { useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Link, useLocation } from 'react-router-dom';
import { isPathMatch, resolveCapability, tenantUrl, workspaceRoutes, type FrontendContext, type WorkspaceRoute } from './core';
import { useLocale } from './i18n';
import './main-layout-parity-controls.css';

export const MAIN_LAYOUT_OPERATION_IDS = {
    skipToContent: 'AIMW-AI-672DA063EF',
    aboutBuild: 'AIMW-AI-AE553AB4D0',
    home: 'AIMW-AI-4A3B180ACC',
    closeThemePicker: 'AIMW-AI-3399ECA4F2',
    openCommandPalette: 'AIMW-AI-2C653A870A',
    openRecentPages: 'AIMW-AI-E3FD23F827',
    toggleAppearance: 'AIMW-AI-2E423C956E',
    toggleSidebar: 'AIMW-AI-EEDA94D1D2',
    toggleThemePicker: 'AIMW-AI-91156B1C8B',
    openRecentFromCommand: 'AIMW-AI-F08307E7FD',
    switchLanguage: 'AIMW-BILL-C12CEEC7C6',
} as const;

const RECENT_KEY = 'aiwm-recent-pages';
const FAVORITE_KEY = 'aiwm-favorite-pages';
const THEME_KEY = 'aiwm-color-theme';
const MAX_RECENT = 10;
const MAX_FAVORITES = 10;

const themes = [
    { key: 'gold', en: 'Gold', ar: 'ذهبي', color: '#c5a45d' },
    { key: 'ocean', en: 'Ocean', ar: 'أزرق', color: '#3b82f6' },
    { key: 'emerald', en: 'Emerald', ar: 'زمردي', color: '#10b981' },
    { key: 'violet', en: 'Violet', ar: 'بنفسجي', color: '#8b5cf6' },
    { key: 'rose', en: 'Rose', ar: 'وردي', color: '#f43f5e' },
    { key: 'amber', en: 'Amber', ar: 'كهرماني', color: '#f59e0b' },
    { key: 'cyan', en: 'Cyan', ar: 'سماوي', color: '#06b6d4' },
    { key: 'slate', en: 'Slate', ar: 'رمادي', color: '#94a3b8' },
] as const;

type ThemeKey = typeof themes[number]['key'];

function safeRead(key: string): string[] {
    try {
        const value = JSON.parse(window.localStorage.getItem(key) ?? '[]');
        return Array.isArray(value) ? value.filter((item): item is string => typeof item === 'string') : [];
    } catch {
        return [];
    }
}

function safeWrite(key: string, value: string[]) {
    try {
        window.localStorage.setItem(key, JSON.stringify(value));
    } catch {
        // Browser storage can be unavailable in hardened/private contexts; UI stays functional in-memory.
    }
}

function initialTheme(): ThemeKey {
    try {
        const value = window.localStorage.getItem(THEME_KEY);
        return themes.some((theme) => theme.key === value) ? value as ThemeKey : 'gold';
    } catch {
        return 'gold';
    }
}

function applyTheme(theme: ThemeKey) {
    if (theme === 'gold') delete document.documentElement.dataset.theme;
    else document.documentElement.dataset.theme = theme;
    try {
        window.localStorage.setItem(THEME_KEY, theme);
    } catch {
        // See safeWrite: persistence is best-effort, runtime application is authoritative for this session.
    }
}

function routeTitle(route: WorkspaceRoute, locale: 'en' | 'ar') {
    return route.label[locale];
}

function MainLayoutRuntimeMarkers() {
    useEffect(() => {
        const markers: Array<[string, string]> = [
            ['.skip-link', MAIN_LAYOUT_OPERATION_IDS.skipToContent],
            ['.brand', MAIN_LAYOUT_OPERATION_IDS.home],
            ['.sidebar-toggle', MAIN_LAYOUT_OPERATION_IDS.toggleSidebar],
            ['#command-palette-trigger', MAIN_LAYOUT_OPERATION_IDS.openCommandPalette],
            ['.language-switch', MAIN_LAYOUT_OPERATION_IDS.switchLanguage],
        ];
        for (const [selector, operationId] of markers) {
            document.querySelector<HTMLElement>(selector)?.setAttribute('data-canonical-operation', operationId);
        }
        const appearance = Array.from(document.querySelectorAll<HTMLButtonElement>('.topbar-actions > button.icon-button'))
            .find((button) => /light|dark|الفاتح|الداكن/i.test(button.getAttribute('aria-label') ?? ''));
        appearance?.setAttribute('data-canonical-operation', MAIN_LAYOUT_OPERATION_IDS.toggleAppearance);
    }, []);

    return null;
}

function ThemePicker({ onClose }: { onClose: () => void }) {
    const { locale } = useLocale();
    const [selected, setSelected] = useState<ThemeKey>(initialTheme);
    const closeRef = useRef<HTMLButtonElement>(null);

    useEffect(() => {
        const timer = window.setTimeout(() => closeRef.current?.focus(), 0);
        const keyHandler = (event: KeyboardEvent) => {
            if (event.key === 'Escape') onClose();
        };
        document.addEventListener('keydown', keyHandler);
        return () => {
            window.clearTimeout(timer);
            document.removeEventListener('keydown', keyHandler);
        };
    }, [onClose]);

    return (
        <div className="dialog-backdrop main-layout-overlay" role="presentation" onMouseDown={(event) => event.target === event.currentTarget && onClose()}>
            <section className="dialog main-layout-dialog" role="dialog" aria-modal="true" aria-labelledby="main-layout-theme-title">
                <header className="dialog-header">
                    <div>
                        <span className="workspace-kicker">THEME</span>
                        <h2 id="main-layout-theme-title">{locale === 'ar' ? 'ألوان النظام' : 'Application colors'}</h2>
                    </div>
                    <button
                        ref={closeRef}
                        type="button"
                        className="icon-button"
                        data-canonical-operation={MAIN_LAYOUT_OPERATION_IDS.closeThemePicker}
                        aria-label={locale === 'ar' ? 'إغلاق اختيار الألوان' : 'Close color picker'}
                        onClick={onClose}
                    >×</button>
                </header>
                <p>{locale === 'ar' ? 'اختر لوحة الألوان المناسبة لك.' : 'Choose your preferred color palette.'}</p>
                <div className="main-layout-theme-grid" role="group" aria-label={locale === 'ar' ? 'ألوان النظام' : 'Application colors'}>
                    {themes.map((theme) => (
                        <button
                            type="button"
                            key={theme.key}
                            className={`main-layout-theme-option ${selected === theme.key ? 'active' : ''}`}
                            aria-pressed={selected === theme.key}
                            onClick={() => {
                                setSelected(theme.key);
                                applyTheme(theme.key);
                                onClose();
                            }}
                        >
                            <span className="main-layout-theme-dot" style={{ background: theme.color }} aria-hidden="true" />
                            <span>{locale === 'ar' ? theme.ar : theme.en}</span>
                        </button>
                    ))}
                </div>
            </section>
        </div>
    );
}

function RecentPagesDialog({
    context,
    routes,
    favorites,
    recent,
    setFavorites,
    onClose,
}: {
    context: FrontendContext;
    routes: WorkspaceRoute[];
    favorites: string[];
    recent: string[];
    setFavorites: (next: string[]) => void;
    onClose: () => void;
}) {
    const { locale } = useLocale();
    const closeRef = useRef<HTMLButtonElement>(null);
    const byPath = useMemo(() => new Map(routes.map((route) => [route.path, route])), [routes]);
    const favoriteRoutes = favorites.map((path) => byPath.get(path)).filter((route): route is WorkspaceRoute => Boolean(route));
    const recentRoutes = recent.filter((path) => !favorites.includes(path)).map((path) => byPath.get(path)).filter((route): route is WorkspaceRoute => Boolean(route));

    useEffect(() => {
        const timer = window.setTimeout(() => closeRef.current?.focus(), 0);
        const keyHandler = (event: KeyboardEvent) => {
            if (event.key === 'Escape') onClose();
        };
        document.addEventListener('keydown', keyHandler);
        return () => {
            window.clearTimeout(timer);
            document.removeEventListener('keydown', keyHandler);
        };
    }, [onClose]);

    const toggleFavorite = (path: string) => {
        const next = favorites.includes(path)
            ? favorites.filter((candidate) => candidate !== path)
            : [path, ...favorites.filter((candidate) => candidate !== path)].slice(0, MAX_FAVORITES);
        setFavorites(next);
        safeWrite(FAVORITE_KEY, next);
    };

    const renderRoute = (route: WorkspaceRoute) => {
        const favorite = favorites.includes(route.path);
        const title = routeTitle(route, locale);
        return (
            <div className="main-layout-recent-item" key={route.key}>
                <Link to={tenantUrl(context.tenant.slug, route.path)} onClick={onClose}>
                    <span aria-hidden="true">{route.icon}</span><strong>{title}</strong>
                </Link>
                <button
                    type="button"
                    className="icon-button"
                    aria-label={favorite
                        ? (locale === 'ar' ? `إزالة ${title} من المفضلة` : `Remove ${title} from favorites`)
                        : (locale === 'ar' ? `إضافة ${title} إلى المفضلة` : `Add ${title} to favorites`)}
                    aria-pressed={favorite}
                    onClick={() => toggleFavorite(route.path)}
                >{favorite ? '★' : '☆'}</button>
            </div>
        );
    };

    return (
        <div className="dialog-backdrop main-layout-overlay" role="presentation" onMouseDown={(event) => event.target === event.currentTarget && onClose()}>
            <section className="dialog main-layout-dialog" role="dialog" aria-modal="true" aria-labelledby="main-layout-recent-title">
                <header className="dialog-header">
                    <div>
                        <span className="workspace-kicker">QUICK ACCESS</span>
                        <h2 id="main-layout-recent-title">{locale === 'ar' ? 'المفضلة والصفحات الأخيرة' : 'Favorites and recent pages'}</h2>
                    </div>
                    <button ref={closeRef} type="button" className="icon-button" aria-label={locale === 'ar' ? 'إغلاق الوصول السريع' : 'Close quick access'} onClick={onClose}>×</button>
                </header>
                <div className="main-layout-recent-section">
                    <h3>{locale === 'ar' ? 'المفضلة' : 'Favorites'}</h3>
                    {favoriteRoutes.length ? favoriteRoutes.map(renderRoute) : <p>{locale === 'ar' ? 'لم تضف صفحات للمفضلة بعد.' : 'No favorite pages yet.'}</p>}
                    <h3>{locale === 'ar' ? 'الصفحات الأخيرة' : 'Recent pages'}</h3>
                    {recentRoutes.length ? recentRoutes.map(renderRoute) : <p>{locale === 'ar' ? 'لم تزر صفحات مسجلة بعد.' : 'No recent pages yet.'}</p>}
                </div>
            </section>
        </div>
    );
}

export function MainLayoutParityControls({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const location = useLocation();
    const [topbarTarget, setTopbarTarget] = useState<HTMLElement | null>(null);
    const [footerTarget, setFooterTarget] = useState<HTMLElement | null>(null);
    const [commandDialogTarget, setCommandDialogTarget] = useState<HTMLElement | null>(null);
    const [themeOpen, setThemeOpen] = useState(false);
    const [recentOpen, setRecentOpen] = useState(false);
    const [favorites, setFavorites] = useState<string[]>(() => safeRead(FAVORITE_KEY).slice(0, MAX_FAVORITES));
    const [recent, setRecent] = useState<string[]>(() => safeRead(RECENT_KEY).slice(0, MAX_RECENT));

    const availableRoutes = useMemo(
        () => workspaceRoutes.filter((route) => !route.hidden && resolveCapability(context, route).state === 'enabled'),
        [context],
    );
    const availablePaths = useMemo(() => new Set(availableRoutes.map((route) => route.path)), [availableRoutes]);

    useEffect(() => {
        setTopbarTarget(document.querySelector<HTMLElement>('.topbar-actions'));
        setFooterTarget(document.querySelector<HTMLElement>('.sidebar-footer'));

        const syncCommandDialog = () => setCommandDialogTarget(document.querySelector<HTMLElement>('#command-palette-dialog'));
        syncCommandDialog();
        const observer = new MutationObserver(syncCommandDialog);
        observer.observe(document.body, { childList: true, subtree: true });
        return () => observer.disconnect();
    }, []);

    useEffect(() => {
        applyTheme(initialTheme());
    }, []);

    useEffect(() => {
        const nextFavorites = favorites.filter((path) => availablePaths.has(path)).slice(0, MAX_FAVORITES);
        const nextRecent = recent.filter((path) => availablePaths.has(path)).slice(0, MAX_RECENT);
        if (nextFavorites.join('\n') !== favorites.join('\n')) {
            setFavorites(nextFavorites);
            safeWrite(FAVORITE_KEY, nextFavorites);
        }
        if (nextRecent.join('\n') !== recent.join('\n')) {
            setRecent(nextRecent);
            safeWrite(RECENT_KEY, nextRecent);
        }
    }, [availablePaths, favorites, recent]);

    useEffect(() => {
        const match = availableRoutes
            .slice()
            .sort((a, b) => b.path.length - a.path.length)
            .find((route) => isPathMatch(route.path, location.pathname, context.tenant.slug));
        if (!match) return;
        setRecent((current) => {
            const next = [match.path, ...current.filter((path) => path !== match.path && availablePaths.has(path))].slice(0, MAX_RECENT);
            safeWrite(RECENT_KEY, next);
            return next;
        });
    }, [availablePaths, availableRoutes, context.tenant.slug, location.pathname]);

    useEffect(() => {
        const handler = (event: KeyboardEvent) => {
            if ((event.ctrlKey || event.metaKey) && event.shiftKey && event.key.toLowerCase() === 'p') {
                event.preventDefault();
                setThemeOpen(false);
                setRecentOpen(true);
            }
            if ((event.ctrlKey || event.metaKey) && !event.shiftKey && event.key.toLowerCase() === 'k') {
                setThemeOpen(false);
            }
        };
        document.addEventListener('keydown', handler);
        return () => document.removeEventListener('keydown', handler);
    }, []);

    useEffect(() => {
        const trigger = document.querySelector<HTMLElement>('#command-palette-trigger');
        const closeTheme = () => setThemeOpen(false);
        trigger?.addEventListener('click', closeTheme);
        return () => trigger?.removeEventListener('click', closeTheme);
    }, [topbarTarget]);

    const openRecentFromCommand = () => {
        const close = document.querySelector<HTMLButtonElement>(`#command-palette-dialog [data-canonical-operation="AIMW-AI-D3A8A100B4"]`);
        close?.click();
        setThemeOpen(false);
        setRecentOpen(true);
    };

    return (
        <>
            <MainLayoutRuntimeMarkers />
            {footerTarget ? createPortal(
                <Link
                    className="main-layout-build-link"
                    data-canonical-operation={MAIN_LAYOUT_OPERATION_IDS.aboutBuild}
                    to={tenantUrl(context.tenant.slug, '/about-build')}
                >{locale === 'ar' ? 'معلومات الإصدار' : 'Build information'}</Link>,
                footerTarget,
            ) : null}
            {topbarTarget ? createPortal(
                <>
                    <button
                        type="button"
                        className="icon-button"
                        data-canonical-operation={MAIN_LAYOUT_OPERATION_IDS.openRecentPages}
                        aria-label={locale === 'ar' ? 'فتح المفضلة والصفحات الأخيرة' : 'Open favorites and recent pages'}
                        aria-keyshortcuts="Control+Shift+P"
                        aria-expanded={recentOpen}
                        onClick={() => { setThemeOpen(false); setRecentOpen(true); }}
                    >★</button>
                    <button
                        type="button"
                        className="icon-button"
                        data-canonical-operation={MAIN_LAYOUT_OPERATION_IDS.toggleThemePicker}
                        aria-label={locale === 'ar' ? 'تغيير ألوان النظام' : 'Change application colors'}
                        aria-expanded={themeOpen}
                        onClick={() => { setRecentOpen(false); setThemeOpen((current) => !current); }}
                    >◈</button>
                </>,
                topbarTarget,
            ) : null}
            {commandDialogTarget ? createPortal(
                <button
                    type="button"
                    className="btn command-recent-link"
                    data-canonical-operation={MAIN_LAYOUT_OPERATION_IDS.openRecentFromCommand}
                    onClick={openRecentFromCommand}
                >★ {locale === 'ar' ? 'المفضلة والأخيرة' : 'Favorites & recent'}</button>,
                commandDialogTarget,
            ) : null}
            {themeOpen ? <ThemePicker onClose={() => setThemeOpen(false)} /> : null}
            {recentOpen ? (
                <RecentPagesDialog
                    context={context}
                    routes={availableRoutes}
                    favorites={favorites}
                    recent={recent}
                    setFavorites={setFavorites}
                    onClose={() => setRecentOpen(false)}
                />
            ) : null}
        </>
    );
}
