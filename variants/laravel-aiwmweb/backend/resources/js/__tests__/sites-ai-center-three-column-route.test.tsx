import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

// Validate the actual authenticated route composition and the visual contracts.
// AI Center remains a grid; Sites is deliberately a tabbed, full-width table.
const jsPath = resolve(process.cwd(), 'resources/js');
const app = readFileSync(resolve(jsPath, 'app.tsx'), 'utf8');
const tabs = readFileSync(resolve(jsPath, 'sites-management-tabs.tsx'), 'utf8');
const page = readFileSync(resolve(jsPath, 'pages.tsx'), 'utf8');
const approval = readFileSync(resolve(jsPath, 'ai-center-approval-status-control.tsx'), 'utf8');
const css = readFileSync(resolve(process.cwd(), 'resources/css/app.css'), 'utf8');

describe('Sites tabs and AI Center route layout contract', () => {
    it('renders a real Sites table and six original operations behind accessible tabs', () => {
        const sitesRoute = app.split('\n').find((line) => line.includes("if (route.key === 'sites') return"));
        expect(sitesRoute).toContain('<SitesManagementTabs context={context}><WorkspacePage context={context} route={route} /></SitesManagementTabs>');
        expect(tabs).toContain('role="tablist"');
        expect(tabs).toContain('role="tab"');
        expect(tabs).toContain('role="tabpanel"');
        expect(tabs).toContain("params.get('sites_tab')");
        for (const operation of [
            'SitesSaveControl', 'SitesRetestSelectedControl',
            'SitesBulkOperationalStateControl', 'SitesDeleteControl',
            'SitesBulkDeleteControl', 'SitesDisableSelectedControl',
        ]) {
            expect(tabs).toContain('<' + operation + ' context={context} />');
        }
        expect(tabs).toContain('activeId === tab.id ? tab.content : null');
        expect(page).toContain('layout="table"');
        expect(page).toMatch(/const pageSize = rawSize && .* : 10;/);
    });

    it('keeps AI session, history, composer and authoritative metadata in their own responsive groups', () => {
        expect(approval).toContain('className="ai-center-controls-grid"');
        expect(approval).toContain('{sessionControls}{outputControls}{submitControls}{historyControls}{approvalControls}');
        expect(page).toContain("'workspace-stack ai-center-resource-grid'");
        expect(page).toContain('data-ai-center-metadata');
        expect(page).toContain('<AiCenterGenerateControl');
    });

    it('gives Sites full-width, horizontal-scrollable tabs while retaining the AI Center grid breakpoints', () => {
        expect(css).toContain('.sites-tab-list {');
        expect(css).toContain('overflow-x: auto');
        expect(css).toContain('.sites-tab-panel[hidden] { display: none; }');
        expect(css).not.toContain('.sites-management-grid');
        expect(css).toMatch(/\.ai-center-controls-grid\s*\{\s*display:\s*grid;\s*grid-template-columns:\s*repeat\(3/);
        expect(css).toContain('@media (min-width: 761px) and (max-width: 1199px)');
        expect(css).toContain('@media (max-width: 760px)');
        expect(css).toContain('.ai-center-controls-grid, .ai-center-resource-grid { grid-template-columns: minmax(0, 1fr)');
    });
});
