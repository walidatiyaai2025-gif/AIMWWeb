import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

// Route markup is composed inside RouteElement which requires TenantBootstrap's
// authenticated React Query outlet. Validate the actual owner-facing route
// composition and breakpoints without synthesizing a separate authentication shell.
const app = readFileSync(new URL('../app.tsx', import.meta.url), 'utf8');
const page = readFileSync(new URL('../pages.tsx', import.meta.url), 'utf8');
const approval = readFileSync(new URL('../ai-center-approval-status-control.tsx', import.meta.url), 'utf8');
const css = readFileSync(new URL('../../css/app.css', import.meta.url), 'utf8');

describe('Sites and AI Center compact route layout contract', () => {
    it('groups the six actual Sites operations before the real resource list', () => {
        const sitesRoute = app.split('\n').find((line) => line.includes("if (route.key === 'sites') return"));
        expect(sitesRoute).toBeDefined();
        expect(sitesRoute).toContain('className="sites-management-grid"');
        expect(sitesRoute).toContain('data-testid="sites-management-grid"');
        for (const control of [
            'SitesSaveControl', 'SitesRetestSelectedControl',
            'SitesBulkOperationalStateControl', 'SitesDeleteControl',
            'SitesBulkDeleteControl', 'SitesDisableSelectedControl',
        ]) {
            expect(sitesRoute).toContain('<' + control + ' context={context} />');
        }
        expect(sitesRoute?.indexOf('</div><WorkspacePage')).toBeGreaterThan(0);
    });

    it('keeps AI session, history, composer and authoritative metadata in their own responsive groups', () => {
        expect(approval).toContain('className="ai-center-controls-grid"');
        expect(approval).toContain('{sessionControls}{outputControls}{submitControls}{historyControls}{approvalControls}');
        expect(page).toContain("'workspace-stack ai-center-resource-grid'");
        expect(page).toContain('data-ai-center-metadata');
        expect(page).toContain('<AiCenterGenerateControl');
    });

    it('provides desktop three columns, tablet two columns and mobile one column', () => {
        expect(css).toMatch(/\.sites-management-grid,\s*\.ai-center-controls-grid\s*\{\s*display:\s*grid;\s*grid-template-columns:\s*repeat\(3,\s*minmax\(0,\s*1fr\)\)/);
        expect(css).toContain('@media (min-width: 761px) and (max-width: 1199px)');
        expect(css).toContain('@media (max-width: 760px)');
        expect(css).toContain('.sites-management-grid, .ai-center-controls-grid, .ai-center-resource-grid { grid-template-columns: minmax(0, 1fr)');
    });
});
