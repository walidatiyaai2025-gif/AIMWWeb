/**
 * Real Chromium geometry/regression acceptance for Issue #1603. Requires a
 * local Vite dev server, never live WordPress, and records screenshots as CI artifacts.
 * Fixtures exercise real editor controls and CSS, NOT a deployed authenticated site.
 */
import { chromium } from 'playwright';
import assert from 'node:assert/strict';
import { mkdir, writeFile } from 'node:fs/promises';

const url = process.env.AIWM_GEOMETRY_URL || 'http://127.0.0.1:5173/tests/browser/content-editor-geometry.html';
const destination = new URL('./screenshots/', import.meta.url).pathname;
const viewports = [
    { width: 1920, height: 1080 },
    { width: 1440, height: 900 },
    { width: 390, height: 844 },
];
const results = [];
await mkdir(destination, { recursive: true });
const browser = await chromium.launch({ headless: true });

try {
    for (const locale of ['en', 'ar']) {
        for (const viewport of viewports) {
            const context = await browser.newContext({ viewport, deviceScaleFactor: 1, reducedMotion: 'reduce' });
            const page = await context.newPage();
            const trace = `${locale}-${viewport.width}x${viewport.height}`;
            try {
                await page.addInitScript(lang => localStorage.setItem('aiwm.locale', lang), locale);
                await page.goto(url, { waitUntil: 'networkidle' });
                const frame = page.locator('.content-preview-frame');
                await frame.waitFor({ state: 'visible' });
                await page.frameLocator('.content-preview-frame').locator('h1').waitFor();
                assert.equal(await page.locator('html').getAttribute('dir'), locale === 'ar' ? 'rtl' : 'ltr', `${trace}: host direction`);
                assert.equal(await page.locator('html').getAttribute('lang'), locale, `${trace}: host language`);
                assert.equal(await frame.getAttribute('sandbox'), '', `${trace}: sandbox`);
                assert.equal(await frame.getAttribute('referrerpolicy'), 'no-referrer', `${trace}: no referrer`);
                const geometry = await page.evaluate(() => {
                    const panel = document.querySelector('.content-editor-content-card');
                    const preview = document.querySelector('.content-preview');
                    const frame = document.querySelector('.content-preview-frame');
                    const toggle = document.querySelector('.editor-view-toggle');
                    if (!panel || !preview || !frame || !toggle) throw new Error('Missing real editor geometry nodes');
                    const p = panel.getBoundingClientRect();
                    const v = preview.getBoundingClientRect();
                    const f = frame.getBoundingClientRect();
                    const t = toggle.getBoundingClientRect();
                    return {
                        viewport: window.innerWidth,
                        pageOverflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
                        panelWidth: p.width,
                        previewWidth: v.width,
                        frameWidth: f.width,
                        frameHeight: f.height,
                        toggleRight: t.right,
                        panelRight: p.right,
                        toggleLeft: t.left,
                        panelLeft: p.left,
                    };
                });
                assert.ok(geometry.pageOverflow <= 2, `${trace}: page horizontal overflow ${JSON.stringify(geometry)}`);
                assert.ok(geometry.panelWidth >= Math.min(280, viewport.width * .6), `${trace}: content panel too narrow ${JSON.stringify(geometry)}`);
                assert.ok(geometry.previewWidth / geometry.panelWidth >= .88, `${trace}: preview collapsed ${JSON.stringify(geometry)}`);
                assert.ok(geometry.frameWidth / geometry.previewWidth >= .98, `${trace}: iframe collapsed ${JSON.stringify(geometry)}`);
                assert.ok(geometry.frameHeight >= 320, `${trace}: iframe insufficient height ${JSON.stringify(geometry)}`);
                assert.ok(geometry.toggleRight <= geometry.panelRight + 3 && geometry.toggleLeft >= geometry.panelLeft - 3, `${trace}: clipped toggle`);

                const inner = await page.frameLocator('.content-preview-frame').locator('body').evaluate(element => ({
                    direction: document.documentElement.dir,
                    overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
                    imageWidth: element.querySelector('img')?.getBoundingClientRect().width,
                    clientWidth: document.documentElement.clientWidth,
                    imageNaturalWidth: element.querySelector('img')?.naturalWidth,
                    tableWidth: element.querySelector('table')?.getBoundingClientRect().width,
                    preWidth: element.querySelector('pre')?.getBoundingClientRect().width,
                }));
                assert.equal(inner.direction, locale === 'ar' ? 'rtl' : 'ltr', `${trace}: iframe direction`);
                assert.ok(inner.overflow <= 2, `${trace}: iframe page-level overflow ${JSON.stringify(inner)}`);
                assert.ok((inner.imageWidth ?? 0) <= inner.clientWidth, `${trace}: wide image clipping ${JSON.stringify(inner)}`);
                assert.ok((inner.tableWidth ?? 0) <= inner.clientWidth, `${trace}: table clipping ${JSON.stringify(inner)}`);
                assert.ok((inner.preWidth ?? 0) <= inner.clientWidth, `${trace}: pre clipping ${JSON.stringify(inner)}`);
                assert.equal(await page.evaluate(() => window.__untrustedScriptRan === true), false, `${trace}: script escaped sandbox`);

                await page.screenshot({ path: `${destination}/editor-${trace}.png`, fullPage: true, animations: 'disabled' });

                // Keyboard access and unsaved draft parity, without any server mutation.
                const edit = page.getByRole('button', { name: locale === 'ar' ? 'تحرير' : 'Edit' });
                await edit.focus();
                await page.keyboard.press('Enter');
                const textarea = page.getByTestId('html-editor');
                await textarea.waitFor({ state: 'visible' });
                assert.ok((await textarea.boundingBox()).width >= Math.min(250, viewport.width * .60), `${trace}: editor textarea too narrow`);
                await textarea.fill('<h1>Updated unsaved draft</h1><p>Locally updated only</p>');
                await page.getByRole('button', { name: locale === 'ar' ? 'معاينة' : 'Preview' }).click();
                await page.frameLocator('.content-preview-frame').getByText('Updated unsaved draft').waitFor();
                assert.equal(await page.frameLocator('.content-preview-frame').getByText('Real HTML draft preview').count(), 0, `${trace}: stale content`);

                await edit.click();
                await textarea.fill('   ');
                await page.getByRole('button', { name: locale === 'ar' ? 'معاينة' : 'Preview' }).click();
                await page.getByRole('status').waitFor();
                assert.equal(await frame.count(), 0, `${trace}: empty draft must not render iframe`);
                results.push({ locale, ...viewport, result: 'PASS', ...geometry, inner });
            } catch (error) {
                await page.screenshot({ path: `${destination}/FAIL-${trace}.png`, fullPage: true }).catch(() => {});
                throw error;
            } finally {
                await context.close();
            }
        }
    }
    await writeFile(`${destination}/geometry-results.json`, JSON.stringify({ fixture: 'disposable/no WordPress connection', results }, null, 2));
    console.log('CONTENT_EDITOR_PLAYWRIGHT_GEOMETRY=PASS', results.length, 'viewports');
} finally {
    await browser.close();
}
