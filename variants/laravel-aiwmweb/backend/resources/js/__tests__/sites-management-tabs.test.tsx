import React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { BrowserRouter } from 'react-router-dom';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import { SitesManagementTabs } from '../sites-management-tabs';

// Mock only the six existing operation surfaces. This tests real navigation,
// permission filtering and lazy mounting without issuing tenant API mutations.
vi.mock('../sites-save-control', () => ({ SitesSaveControl: () => <div data-testid="site-profile-operation">Profile</div> }));
vi.mock('../sites-retest-selected-control', () => ({ SitesRetestSelectedControl: () => <div data-testid="site-retest-operation">Retest</div> }));
vi.mock('../sites-bulk-operational-state-control', () => ({ SitesBulkOperationalStateControl: () => <div data-testid="site-enable-operation">Enable</div> }));
vi.mock('../sites-disable-selected-control', () => ({ SitesDisableSelectedControl: () => <div data-testid="site-disable-operation">Disable</div> }));
vi.mock('../sites-delete-control', () => ({ SitesDeleteControl: () => <div data-testid="site-delete-operation">Delete</div> }));
vi.mock('../sites-bulk-delete-control', () => ({ SitesBulkDeleteControl: () => <div data-testid="site-bulk-delete-operation">Bulk delete</div> }));

const context = (permissions: string[]) => ({
    tenant: { slug: 'wordpress' },
    api: { sites: '/api/tenants/wordpress/sites' },
    permissions,
} as unknown as FrontendContext);

function renderTabs(permissions = ['sites.view', 'sites.manage', 'connector.manage']) {
    return render(
        <BrowserRouter>
            <LocaleProvider>
                <SitesManagementTabs context={context(permissions)}>
                    <div data-testid="authoritative-sites-list">Authoritative sites table</div>
                </SitesManagementTabs>
            </LocaleProvider>
        </BrowserRouter>,
    );
}

beforeEach(() => {
    window.localStorage.clear();
    window.history.replaceState({}, '', '/tenants/wordpress/sites?page=2&search=example');
});

describe('SitesManagementTabs', () => {
    it('opens the real list by default and does not mount the six inactive controls', () => {
        renderTabs();
        expect(screen.getByRole('tab', { name: 'Sites' }).getAttribute('aria-selected')).toBe('true');
        expect(screen.getByTestId('authoritative-sites-list')).toBeTruthy();
        expect(screen.queryByTestId('site-profile-operation')).toBeNull();
        expect(screen.queryByTestId('site-delete-operation')).toBeNull();
        expect(screen.getAllByRole('tab')).toHaveLength(7);
    });

    it('switches to one existing operation and preserves server pagination filters in the URL', () => {
        renderTabs();
        fireEvent.click(screen.getByRole('tab', { name: 'Add / edit' }));
        expect(screen.getByTestId('site-profile-operation')).toBeTruthy();
        expect(screen.queryByTestId('authoritative-sites-list')).toBeNull();
        expect(window.location.search).toContain('page=2');
        expect(window.location.search).toContain('search=example');
        expect(window.location.search).toContain('sites_tab=profile');
        fireEvent.click(screen.getByRole('tab', { name: 'Sites' }));
        expect(screen.getByTestId('authoritative-sites-list')).toBeTruthy();
        expect(window.location.search).not.toContain('sites_tab');
    });

    it('does not reveal mutation tabs to read-only users', () => {
        renderTabs(['sites.view']);
        expect(screen.getAllByRole('tab')).toHaveLength(1);
        expect(screen.queryByRole('tab', { name: 'Delete site' })).toBeNull();
        expect(screen.queryByRole('tab', { name: 'Add / edit' })).toBeNull();
    });

    it('supports Arabic labels and right-to-left arrow navigation', () => {
        window.localStorage.setItem('aiwm.locale', 'ar');
        renderTabs();
        const sites = screen.getByRole('tab', { name: 'المواقع' });
        fireEvent.keyDown(sites, { key: 'ArrowLeft' });
        expect(screen.getByRole('tab', { name: 'إضافة / تعديل' }).getAttribute('aria-selected')).toBe('true');
        expect(screen.getByTestId('site-profile-operation')).toBeTruthy();
    });

    it('honors a valid deep-linked operation tab without loading the Sites list', () => {
        window.history.replaceState({}, '', '/tenants/wordpress/sites?sites_tab=delete&page=3');
        renderTabs();
        expect(screen.getByTestId('site-delete-operation')).toBeTruthy();
        expect(screen.queryByTestId('authoritative-sites-list')).toBeNull();
    });
});
