/**
 * Local-only, bounded, deliberately lossy diagnostic observations.
 *
 * No network telemetry, localStorage, secrets, raw URL/query, request bodies,
 * response bodies, console contents or stack traces. Export is user-initiated.
 */
export type RuntimeObservation = {
    at: string;
    kind: 'api' | 'network_failure' | 'script_error' | 'unhandled_rejection' | 'resource_error';
    route?: string;
    method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE' | 'OTHER';
    status?: number;
    duration_ms?: number;
};

const MAX_EVENTS = 120;
const observations: RuntimeObservation[] = [];
const safeSegments = new Set([
    'api', 'v1', 'tenants', 'context', 'sites', 'connector', 'connection',
    'capabilities', 'diagnostics', 'sync', 'runs', 'posts', 'pages', 'media',
    'content', 'post', 'page', 'taxonomy', 'categories', 'tags', 'comments',
    'global-posts', 'global-pages', 'global-media', 'wp-users', 'operations',
    'seo', 'audits', 'findings', 'health', 'ready', 'live', 'status', 'settings',
    'runtime-inspector', 'report', 'site', 'site-details', 'module',
    'dashboard', 'execution', 'wordpress', 'logs', 'configuration',
]);
const safeMethods = new Set(['GET', 'POST', 'PUT', 'PATCH', 'DELETE']);

/** Returns only a structural route shape. Dynamic segments are never exported. */
export function diagnosticRouteShape(input: string): string {
    try {
        const parsed = new URL(input, 'https://localhost.invalid');
        const segments = parsed.pathname.split('/').filter(Boolean).slice(0, 16);
        return '/' + segments.map((segment, index) => {
            // Tenant slugs and site/user/remote IDs are never copied into exports.
            if (index > 0 && ['tenants', 'sites', 'runs', 'audits', 'findings'].includes(segments[index - 1])) {
                return ':id';
            }
            return safeSegments.has(segment) ? segment : ':value';
        }).join('/');
    } catch {
        return '/:invalid';
    }
}

export function recordApiObservation(
    input: string,
    method: string,
    status: number | null,
    durationMs: number,
): void {
    const normalizedMethod = method.toUpperCase();
    add({
        at: new Date().toISOString(),
        kind: status === null ? 'network_failure' : 'api',
        route: diagnosticRouteShape(input),
        method: (safeMethods.has(normalizedMethod) ? normalizedMethod : 'OTHER') as RuntimeObservation['method'],
        ...(status === null ? {} : { status: Number.isInteger(status) && status >= 100 && status <= 599 ? status : 0 }),
        duration_ms: Math.max(0, Math.min(600000, Math.round(durationMs))),
    });
}

function add(event: RuntimeObservation): void {
    observations.push(event);
    if (observations.length > MAX_EVENTS) observations.splice(0, observations.length - MAX_EVENTS);
}

export function runtimeInspectorEvents(): RuntimeObservation[] {
    return observations.map((event) => ({ ...event }));
}

export function clearRuntimeInspectorEvents(): void {
    observations.length = 0;
}

let installed = false;

/** Register passive listeners once after the SPA bootstrap; do not wrap global fetch. */
export function installRuntimeInspectorCapture(): void {
    if (installed || typeof window === 'undefined') return;
    installed = true;
    window.addEventListener('error', (event: Event) => {
        add({
            at: new Date().toISOString(),
            kind: (event.target && event.target !== window) ? 'resource_error' : 'script_error',
        });
    }, true);
    window.addEventListener('unhandledrejection', () => {
        add({ at: new Date().toISOString(), kind: 'unhandled_rejection' });
    });
}
