import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

const app = readFileSync(new URL('../app.tsx', import.meta.url), 'utf8');

describe('Comments back-to-sites production wiring', () => {
    it('wires only the comments route to the claimed control', () => {
        expect(app).toContain("import { CommentsBackToSitesControl } from './comments-back-to-sites-control';");
        expect(app).toContain("if (route.key === 'comments')");
        expect(app).toContain('<CommentsBackToSitesControl context={context} />');
    });
});
