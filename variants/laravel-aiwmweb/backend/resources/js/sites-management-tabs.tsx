import React, { type KeyboardEvent, type ReactNode } from 'react';
import { useSearchParams } from 'react-router-dom';
import type { FrontendContext } from './core';
import { useLocale } from './i18n';
import { SitesSaveControl } from './sites-save-control';
import { SitesRetestSelectedControl } from './sites-retest-selected-control';
import { SitesBulkOperationalStateControl } from './sites-bulk-operational-state-control';
import { SitesDisableSelectedControl } from './sites-disable-selected-control';
import { SitesDeleteControl } from './sites-delete-control';
import { SitesBulkDeleteControl } from './sites-bulk-delete-control';

type SiteTab = {
    id: string;
    ar: string;
    en: string;
    content: ReactNode;
    destructive?: boolean;
};

/**
 * Sites keeps the authoritative list front and centre, while each management
 * operation lives in its own accessible tab. Unselected operations are not
 * mounted, preventing six concurrent background site-list requests.
 */
export function SitesManagementTabs({ context, children }: { context: FrontendContext; children: ReactNode }) {
    const { locale } = useLocale();
    const [params, setParams] = useSearchParams();
    const can = (permission: string) => context.permissions.includes('*') || context.permissions.includes(permission);
    const collection = `/api/tenants/${encodeURIComponent(context.tenant.slug)}/sites`;
    const hasCollection = context.api.sites === collection;
    const canManage = hasCollection && can('sites.manage');
    const canManageState = canManage && can('sites.view');
    const canRetest = hasCollection && can('connector.manage');

    const tabs: SiteTab[] = [
        { id: 'sites', ar: 'المواقع', en: 'Sites', content: children },
        ...(canManage ? [{ id: 'profile', ar: 'إضافة / تعديل', en: 'Add / edit', content: <SitesSaveControl context={context} /> }] : []),
        ...(canRetest ? [{ id: 'retest', ar: 'اختبار الاتصال', en: 'Connection test', content: <SitesRetestSelectedControl context={context} /> }] : []),
        ...(canManageState ? [
            { id: 'enable', ar: 'تفعيل المواقع', en: 'Enable sites', content: <SitesBulkOperationalStateControl context={context} /> },
            { id: 'disable', ar: 'تعطيل المواقع', en: 'Disable sites', content: <SitesDisableSelectedControl context={context} /> },
        ] : []),
        ...(canManage ? [
            { id: 'delete', ar: 'حذف موقع', en: 'Delete site', content: <SitesDeleteControl context={context} />, destructive: true },
            { id: 'bulk-delete', ar: 'حذف جماعي', en: 'Bulk delete', content: <SitesBulkDeleteControl context={context} />, destructive: true },
        ] : []),
    ];

    const requestedTab = params.get('sites_tab');
    const activeId = tabs.find((tab) => tab.id === requestedTab)?.id ?? 'sites';
    const selectTab = (id: string) => {
        // Sites pagination also updates query params via window.history. Read
        // the live URL so switching tabs never discards page/search/filters.
        const next = new URLSearchParams(window.location.search);
        if (id === 'sites') next.delete('sites_tab');
        else next.set('sites_tab', id);
        setParams(next);
    };
    const onKeyDown = (event: KeyboardEvent<HTMLButtonElement>, index: number) => {
        let nextIndex: number;
        if (event.key === 'Home') nextIndex = 0;
        else if (event.key === 'End') nextIndex = tabs.length - 1;
        else if (event.key === 'ArrowRight') nextIndex = (index + (locale === 'ar' ? -1 : 1) + tabs.length) % tabs.length;
        else if (event.key === 'ArrowLeft') nextIndex = (index + (locale === 'ar' ? 1 : -1) + tabs.length) % tabs.length;
        else return;
        event.preventDefault();
        selectTab(tabs[nextIndex].id);
        const buttons = event.currentTarget.parentElement?.querySelectorAll<HTMLButtonElement>('[role="tab"]');
        buttons?.[nextIndex]?.focus();
    };

    return (
        <section className="sites-management-tabs" data-testid="sites-management-tabs"
            aria-label={locale === 'ar' ? 'إدارة المواقع' : 'Site management'}>
            <div className="sites-tab-list" role="tablist" aria-label={locale === 'ar' ? 'أقسام إدارة المواقع' : 'Site management sections'}>
                {tabs.map((tab, index) => (
                    <button key={tab.id} id={`sites-tab-${tab.id}`} type="button" role="tab"
                        className={`sites-tab-button${tab.destructive ? ' sites-tab-button-danger' : ''}`}
                        aria-selected={activeId === tab.id}
                        aria-controls={`sites-panel-${tab.id}`}
                        tabIndex={activeId === tab.id ? 0 : -1}
                        onClick={() => selectTab(tab.id)}
                        onKeyDown={(event) => onKeyDown(event, index)}>
                        {locale === 'ar' ? tab.ar : tab.en}
                    </button>
                ))}
            </div>
            {tabs.map((tab) => (
                <div key={tab.id} id={`sites-panel-${tab.id}`} role="tabpanel"
                    aria-labelledby={`sites-tab-${tab.id}`}
                    className="sites-tab-panel" hidden={activeId !== tab.id}
                    tabIndex={0}>
                    {activeId === tab.id ? tab.content : null}
                </div>
            ))}
        </section>
    );
}
