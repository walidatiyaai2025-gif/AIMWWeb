import { z } from 'zod';

export type Locale = 'en' | 'ar';
export type CapabilityState =
    | 'enabled'
    | 'disabled_by_owner'
    | 'permission_denied'
    | 'connector_unavailable'
    | 'protocol_upgrade_required'
    | 'site_disconnected'
    | 'pending_integration';

export interface TenantSummary {
    slug: string;
    name: string;
}

export interface ConnectorContract {
    key: string;
    state: 'connected' | 'disconnected' | 'degraded' | 'unknown';
    scopes: string[];
    protocol?: string | null;
    reason?: string | null;
}

export interface CapabilityContract {
    state: CapabilityState;
    reason?: string | null;
    requiredProtocol?: string | null;
}

export interface ActionFieldContract {
    key: string;
    type: 'text' | 'textarea' | 'email' | 'number' | 'select';
    label: { en: string; ar: string };
    required?: boolean;
    options?: Array<{ value: string; label: { en: string; ar: string } }>;
}

export interface ActionContract {
    endpoint: string;
    method: 'POST' | 'PUT' | 'PATCH' | 'DELETE';
    capability?: string;
    fields?: ActionFieldContract[];
}

export interface FrontendContext {
    user: { id: number; name: string; email: string };
    tenant: TenantSummary;
    tenants: TenantSummary[];
    permissions: string[];
    connectors: ConnectorContract[];
    capabilities: Record<string, CapabilityContract>;
    api: Record<string, string>;
    actions: Record<string, ActionContract>;
    locale?: Locale;
}

export interface WorkspaceRoute {
    key: string;
    path: string;
    group: 'overview' | 'content' | 'seo' | 'ai' | 'operations' | 'reports' | 'system';
    icon: string;
    label: { en: string; ar: string };
    description: { en: string; ar: string };
    apiKey?: string;
    permission?: string;
    connectorScope?: string;
    controls?: string[];
    hidden?: boolean;
    kind?: 'dashboard' | 'resource' | 'settings' | 'workspace';
}

export class ApiError extends Error {
    constructor(
        message: string,
        public readonly status: number,
        public readonly code: string,
        public readonly validation: Record<string, string[]> = {},
    ) {
        super(message);
        this.name = 'ApiError';
    }
}

const getCsrfToken = () =>
    document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';

export async function apiRequest<T>(url: string, init: RequestInit = {}): Promise<T> {
    const headers = new Headers(init.headers);
    headers.set('Accept', 'application/json');
    headers.set('X-Requested-With', 'XMLHttpRequest');
    const csrf = getCsrfToken();
    if (csrf) headers.set('X-CSRF-TOKEN', csrf);
    if (init.body && !(init.body instanceof FormData)) headers.set('Content-Type', 'application/json');

    const response = await fetch(url, {
        credentials: 'same-origin',
        ...init,
        headers,
    });

    const contentType = response.headers.get('content-type') ?? '';
    const payload = contentType.includes('application/json')
        ? await response.json().catch(() => ({}))
        : { message: await response.text().catch(() => '') };

    if (!response.ok) {
        const message = typeof payload?.message === 'string' && payload.message
            ? payload.message
            : statusMessage(response.status);
        throw new ApiError(
            message,
            response.status,
            typeof payload?.code === 'string' ? payload.code : `http_${response.status}`,
            typeof payload?.errors === 'object' && payload.errors ? payload.errors : {},
        );
    }

    return payload as T;
}

export const statusMessage = (status: number): string => {
    if (status === 401) return 'Authentication is required.';
    if (status === 403) return 'You do not have permission for this operation.';
    if (status === 404) return 'The requested API contract is not available.';
    if (status === 409) return 'The operation conflicts with the current server state.';
    if (status === 422) return 'The server rejected one or more fields.';
    if (status >= 500) return 'The server could not complete the request.';
    return 'The request could not be completed.';
};

export const groups = {
    overview: { en: 'Overview', ar: 'الرئيسية' },
    content: { en: 'Content', ar: 'المحتوى' },
    seo: { en: 'SEO & Approvals', ar: 'SEO والموافقات' },
    ai: { en: 'AI Workspace', ar: 'الذكاء الاصطناعي' },
    operations: { en: 'Automation & Operations', ar: 'الأتمتة والتشغيل' },
    reports: { en: 'Reports & Insights', ar: 'التقارير والرؤى' },
    system: { en: 'System & Account', ar: 'النظام والحساب' },
} as const;

const r = (
    key: string,
    path: string,
    group: WorkspaceRoute['group'],
    icon: string,
    en: string,
    ar: string,
    enDescription: string,
    arDescription: string,
    options: Partial<WorkspaceRoute> = {},
): WorkspaceRoute => ({
    key,
    path,
    group,
    icon,
    label: { en, ar },
    description: { en: enDescription, ar: arDescription },
    apiKey: key,
    kind: 'resource',
    ...options,
});

export const workspaceRoutes: WorkspaceRoute[] = [
    r('dashboard', '/', 'overview', '⌂', 'Dashboard', 'لوحة التحكم', 'Cross-site operational summary from real tenant aggregates.', 'ملخص تشغيلي حقيقي لكل مواقع الحساب.', { kind: 'dashboard' }),
    r('welcome', '/welcome', 'overview', '✦', 'Welcome', 'مرحبًا', 'Product orientation and first steps.', 'التعريف بالمنتج والخطوات الأولى.', { kind: 'workspace' }),
    r('sites', '/sites', 'overview', '◉', 'Sites', 'المواقع', 'Manage connected WordPress sites.', 'إدارة مواقع WordPress المتصلة.', { permission: 'sites.view', controls: ['sites.connect', 'sites.refresh'] }),
    r('site-connect', '/sites/connect', 'overview', '＋', 'Connect Site', 'إضافة موقع', 'Pair a WordPress site using the available connector contract.', 'ربط موقع WordPress باستخدام عقد الموصل المتاح.', { permission: 'sites.manage', connectorScope: 'pairing', controls: ['sites.connect'], hidden: true, kind: 'settings' }),
    r('site-details', '/sites/:siteId', 'overview', '◉', 'Site Details', 'تفاصيل الموقع', 'Inspect connection, capability, scope and site state.', 'فحص الاتصال والقدرات والنطاق وحالة الموقع.', { permission: 'sites.view', hidden: true }),
    r('explorer', '/explorer', 'overview', '▦', 'Explorer', 'المستكشف', 'Explore tenant-scoped WordPress resources.', 'استكشاف موارد WordPress الخاصة بالحساب.', { permission: 'sites.view' }),
    r('system-overview', '/module/overview', 'overview', '▦', 'System Overview', 'مركز النظام', 'High-level platform modules and runtime state.', 'نظرة عامة على وحدات المنصة وحالة التشغيل.'),

    r('content-hub', '/content', 'content', '▦', 'Content Hub', 'مركز المحتوى', 'Central content workspace.', 'مساحة العمل المركزية للمحتوى.', { permission: 'content.view', kind: 'workspace' }),
    r('posts', '/module/posts', 'content', '▤', 'Posts', 'المقالات', 'Create, edit, filter and publish posts.', 'إنشاء وتحرير وتصفية ونشر المقالات.', { permission: 'content.view', connectorScope: 'posts.read', controls: ['posts.create', 'posts.bulk', 'posts.publish'] }),
    r('pages', '/module/pages', 'content', '▧', 'Pages', 'الصفحات', 'Create and manage WordPress pages.', 'إنشاء وإدارة صفحات WordPress.', { permission: 'content.view', connectorScope: 'pages.read', controls: ['pages.create', 'pages.bulk', 'pages.publish'] }),
    r('media', '/module/media', 'content', '▣', 'Media', 'الوسائط', 'Manage the WordPress media library.', 'إدارة مكتبة وسائط WordPress.', { permission: 'content.view', connectorScope: 'media.read', controls: ['media.upload', 'media.bulk'] }),
    r('comments', '/module/comments', 'content', '◌', 'Comments', 'التعليقات', 'Review and moderate comments.', 'مراجعة وإدارة التعليقات.', { permission: 'content.view', connectorScope: 'comments.read', controls: ['comments.moderate', 'comments.bulk'] }),
    r('taxonomy', '/module/taxonomy', 'content', '#', 'Categories & Tags', 'التصنيفات والوسوم', 'Manage content taxonomy.', 'إدارة تصنيفات ووسوم المحتوى.', { permission: 'content.view', connectorScope: 'taxonomy.read', controls: ['taxonomy.manage'] }),
    r('categories', '/module/categories', 'content', '#', 'Categories', 'التصنيفات', 'Manage WordPress categories.', 'إدارة تصنيفات WordPress.', { permission: 'content.view', connectorScope: 'taxonomy.read', controls: ['categories.create'] }),
    r('tags', '/module/tags', 'content', '#', 'Tags', 'الوسوم', 'Manage WordPress tags.', 'إدارة وسوم WordPress.', { permission: 'content.view', connectorScope: 'taxonomy.read', controls: ['tags.create'] }),
    r('wp-users', '/module/users', 'content', '◎', 'WordPress Users', 'مستخدمو WordPress', 'Manage users exposed by connected WordPress sites.', 'إدارة مستخدمي مواقع WordPress المتصلة.', { permission: 'sites.view', connectorScope: 'users.read' }),

    r('seo-audit', '/module/seo-audit', 'seo', '◈', 'SEO Audit', 'تدقيق SEO', 'Run and inspect real SEO audits.', 'تشغيل ومراجعة تدقيقات SEO الحقيقية.', { permission: 'seo.view', controls: ['seo.audit.run'] }),
    r('seo-findings', '/module/seo-findings', 'seo', '!', 'SEO Findings', 'نتائج SEO', 'Inspect persisted audit findings.', 'مراجعة نتائج التدقيق المحفوظة.', { permission: 'seo.view' }),
    r('seo-suggestions', '/module/seo-suggestions', 'seo', '✦', 'SEO Recommendations', 'توصيات SEO', 'Review prioritized optimization recommendations.', 'مراجعة توصيات التحسين حسب الأولوية.', { permission: 'seo.view', controls: ['seo.recommend.approve'] }),
    r('approvals', '/module/approvals', 'seo', '✓', 'Approval Queue', 'قائمة الموافقات', 'Review governed changes before execution.', 'مراجعة التغييرات المحكومة قبل التنفيذ.', { permission: 'approvals.view', controls: ['approvals.approve', 'approvals.reject'] }),
    r('evidence', '/module/evidence', 'seo', '▥', 'Evidence & Receipts', 'الأدلة والإيصالات', 'Inspect execution evidence and receipts.', 'مراجعة أدلة وإيصالات التنفيذ.', { permission: 'execution.view' }),

    r('ai-center', '/ai-center', 'ai', '✦', 'AI Center', 'مركز الذكاء الاصطناعي', 'Generate reviewable AI-assisted work through configured providers.', 'إنشاء عمل مدعوم بالذكاء قابل للمراجعة عبر المزودين المهيئين.', { permission: 'ai.use', controls: ['ai.generate'] }),
    r('content-planner', '/content-planner', 'ai', '◫', 'Content Planner', 'مخطط المحتوى', 'Move ideas through brief, draft and review.', 'تحويل الأفكار إلى ملخصات ومسودات ومراجعة.', { permission: 'content.view', controls: ['planner.create'] }),
    r('ai-providers', '/module/ai-providers', 'ai', '◈', 'AI Providers', 'مزودو الذكاء', 'Review provider configuration and real runtime readiness.', 'مراجعة إعداد المزودين وحالة التشغيل الحقيقية.', { permission: 'ai.configure', kind: 'settings' }),
    r('prompts', '/module/prompts', 'ai', '⌘', 'Prompt Templates', 'قوالب الأوامر', 'Manage reusable prompt templates.', 'إدارة قوالب الأوامر القابلة لإعادة الاستخدام.', { permission: 'ai.configure', controls: ['prompts.create'] }),
    r('ai-usage', '/module/ai-usage', 'ai', '▥', 'AI Usage & Cost', 'استخدام وتكلفة الذكاء', 'Inspect tenant-scoped usage and cost telemetry.', 'مراجعة استخدام الذكاء والتكلفة الخاصة بالحساب.', { permission: 'ai.viewUsage' }),

    r('automation', '/automation-center', 'operations', '⚡', 'Automation Center', 'مركز الأتمتة', 'Create and manage controlled automation workflows.', 'إنشاء وإدارة تدفقات الأتمتة المحكومة.', { permission: 'automation.view', controls: ['automation.create', 'automation.toggle'] }),
    r('operations', '/operations', 'operations', '▦', 'Operations Hub', 'مركز العمليات', 'Operational overview across connected sites.', 'نظرة تشغيلية شاملة على المواقع المتصلة.', { permission: 'execution.view', kind: 'workspace' }),
    r('site-operations', '/site-operations', 'operations', '≣', 'Site Operations', 'عمليات المواقع', 'Inspect site operation history.', 'مراجعة سجل عمليات المواقع.', { permission: 'execution.view' }),
    r('site-reliability', '/site-reliability', 'operations', '◒', 'Site Reliability', 'موثوقية المواقع', 'Compare connectivity and synchronization reliability.', 'مقارنة موثوقية الاتصال والمزامنة.', { permission: 'sites.view' }),
    r('execution', '/module/execution', 'operations', '▶', 'Execution Center', 'مركز التنفيذ', 'Review queued, running, failed and completed jobs.', 'مراجعة المهام المنتظرة والجارية والفاشلة والمكتملة.', { permission: 'execution.view', controls: ['execution.retry', 'execution.cancel'] }),
    r('sync', '/module/sync', 'operations', '↻', 'Synchronization', 'المزامنة', 'Refresh local WordPress data with conflict visibility.', 'تحديث بيانات WordPress المحلية مع إظهار التعارضات.', { permission: 'sync.view', controls: ['sync.run'] }),
    r('schedules', '/module/schedules', 'operations', '◷', 'Schedules', 'الجدولة', 'Manage scheduled operations.', 'إدارة العمليات المجدولة.', { permission: 'automation.view', controls: ['schedules.create', 'schedules.toggle'] }),
    r('notifications', '/notifications', 'operations', '●', 'Notification Inbox', 'صندوق الإشعارات', 'Review operational and workflow notifications.', 'مراجعة إشعارات التشغيل وسير العمل.', { permission: 'notifications.view' }),
    r('email-history', '/email/history', 'operations', '✉', 'Email Delivery History', 'سجل إرسال البريد', 'Inspect application email delivery history.', 'مراجعة سجل إرسال البريد من التطبيق.', { permission: 'diagnostics.view' }),

    r('reports', '/module/reports', 'reports', '▥', 'Reports & Exports', 'التقارير والتصدير', 'Build reports from real tenant data.', 'إنشاء تقارير من بيانات الحساب الحقيقية.', { permission: 'reports.view', controls: ['reports.export'] }),
    r('import-export', '/module/import-export', 'reports', '⇄', 'Import / Export', 'الاستيراد والتصدير', 'Run governed import and export workflows.', 'تشغيل عمليات الاستيراد والتصدير المحكومة.', { permission: 'content.manage', controls: ['import.run', 'export.run'] }),

    r('system-health', '/system-health', 'system', '♥', 'System Health', 'صحة النظام', 'Check application, database, WordPress and provider health.', 'فحص صحة التطبيق وقاعدة البيانات وWordPress والمزودين.', { permission: 'diagnostics.view' }),
    r('logs', '/module/logs', 'system', '≡', 'Logs & Errors', 'السجلات والأخطاء', 'Inspect actionable diagnostics and failures.', 'مراجعة التشخيصات والأخطاء القابلة للتنفيذ.', { permission: 'diagnostics.view' }),
    r('diagnostics', '/module/diagnostics', 'system', '⌁', 'Diagnostics', 'التشخيصات', 'Inspect connector, runtime and protocol diagnostics.', 'مراجعة تشخيصات الموصل والتشغيل والبروتوكول.', { permission: 'diagnostics.view' }),
    r('backups', '/module/backups', 'system', '⬡', 'Backup & Restore', 'النسخ الاحتياطي والاستعادة', 'Protect and restore tenant-scoped application data.', 'حماية واستعادة بيانات التطبيق الخاصة بالحساب.', { permission: 'backups.view', controls: ['backups.create', 'backups.restore'] }),
    r('settings', '/settings', 'system', '⚙', 'Settings', 'الإعدادات', 'Configure language, appearance and tenant preferences.', 'ضبط اللغة والمظهر وتفضيلات الحساب.', { permission: 'tenant.view', kind: 'settings' }),
    r('workspace', '/workspace', 'system', '▦', 'Workspace Hubs', 'مراكز العمل', 'Navigate capability-oriented workspaces.', 'التنقل بين مساحات العمل حسب القدرات.', { permission: 'tenant.view', kind: 'workspace' }),
    r('account-profile', '/account/profile', 'system', '◎', 'My Account', 'حسابي', 'Review profile and account security.', 'مراجعة الملف الشخصي وأمان الحساب.', { apiKey: 'account.profile', kind: 'settings' }),
    r('account-billing', '/account/billing', 'system', '◇', 'Subscription & Billing', 'الاشتراك والفوترة', 'Review plan, subscription and billing lifecycle.', 'مراجعة الخطة والاشتراك ودورة الفوترة.', { apiKey: 'account.billing' }),
    r('application-users', '/admin/users', 'system', '◎', 'Application Users', 'مستخدمو التطبيق', 'Manage tenant/application users.', 'إدارة مستخدمي الحساب والتطبيق.', { permission: 'users.view', controls: ['users.invite', 'users.disable'] }),
    r('roles', '/admin/roles', 'system', '⌘', 'Roles & Permissions', 'الأدوار والصلاحيات', 'Manage tenant-scoped roles and permissions.', 'إدارة الأدوار والصلاحيات الخاصة بالحساب.', { permission: 'roles.view', controls: ['roles.create', 'roles.assign'] }),
    r('sessions', '/account/sessions', 'system', '◉', 'Sessions', 'الجلسات', 'Inspect and revoke authenticated sessions.', 'مراجعة وإلغاء جلسات تسجيل الدخول.', { permission: 'sessions.view', controls: ['sessions.revoke'] }),
];

export const actionSchema = z.record(z.string(), z.union([z.string(), z.number()]));

export function resolveCapability(
    context: FrontendContext,
    route: WorkspaceRoute,
    operation: 'view' | string = 'view',
): CapabilityContract {
    const explicit = context.capabilities[`${route.key}.${operation}`] ?? context.capabilities[route.key];
    if (explicit && explicit.state !== 'enabled') return explicit;

    if (route.permission && !context.permissions.includes(route.permission) && !context.permissions.includes('*')) {
        return { state: 'permission_denied', reason: `Missing tenant permission: ${route.permission}` };
    }

    if (route.connectorScope) {
        const supportingConnector = context.connectors.find(
            (connector) => connector.state === 'connected' && connector.scopes.includes(route.connectorScope!),
        );
        if (!supportingConnector) {
            const ownerDisabled = context.connectors.find((connector) => connector.scopes.includes(route.connectorScope!));
            return {
                state: ownerDisabled?.reason === 'disabled_by_owner' ? 'disabled_by_owner' : 'connector_unavailable',
                reason: ownerDisabled?.reason ?? `Connector scope ${route.connectorScope} is not currently advertised.`,
            };
        }
    }

    if (operation === 'view' && route.apiKey && !context.api[route.apiKey]) {
        return {
            state: 'pending_integration',
            reason: `Backend API contract '${route.apiKey}' has not been integrated on this Laravel head.`,
        };
    }

    if (operation !== 'view' && !context.actions[operation]) {
        return {
            state: 'pending_integration',
            reason: `Action contract '${operation}' has not been integrated on this Laravel head.`,
        };
    }

    return explicit ?? { state: 'enabled' };
}

export function capabilityReason(state: CapabilityContract, locale: Locale): string {
    if (state.reason) return state.reason;
    const reasons: Record<CapabilityState, { en: string; ar: string }> = {
        enabled: { en: 'Available', ar: 'متاح' },
        disabled_by_owner: { en: 'Disabled by site owner', ar: 'معطل بواسطة مالك الموقع' },
        permission_denied: { en: 'Your tenant role does not allow this action.', ar: 'دورك في الحساب لا يسمح بهذا الإجراء.' },
        connector_unavailable: { en: 'The connected site does not advertise the required capability.', ar: 'الموقع المتصل لا يعلن القدرة المطلوبة.' },
        protocol_upgrade_required: { en: 'A connector protocol upgrade is required.', ar: 'يلزم تحديث بروتوكول الموصل.' },
        site_disconnected: { en: 'The site is currently disconnected.', ar: 'الموقع غير متصل حاليًا.' },
        pending_integration: { en: 'The backend integration for this capability is pending.', ar: 'تكامل الخادم لهذه القدرة لم يكتمل بعد.' },
    };
    return reasons[state.state][locale];
}

export function tenantUrl(tenantSlug: string, path: string): string {
    const normalized = path === '/' ? '' : path.startsWith('/') ? path : `/${path}`;
    return `/tenants/${encodeURIComponent(tenantSlug)}${normalized}`;
}

export function switchTenantPath(pathname: string, nextTenant: string): string {
    const replaced = pathname.replace(/^\/tenants\/[^/]+/, `/tenants/${encodeURIComponent(nextTenant)}`);
    return replaced === pathname ? tenantUrl(nextTenant, '/') : replaced;
}

export function isPathMatch(routePath: string, pathname: string, tenantSlug: string): boolean {
    const prefix = `/tenants/${tenantSlug}`;
    const relative = pathname.startsWith(prefix) ? pathname.slice(prefix.length) || '/' : pathname;
    if (routePath.includes(':siteId')) return /^\/sites\/[^/]+\/?$/.test(relative);
    return routePath === '/' ? relative === '/' : relative === routePath || relative.startsWith(`${routePath}/`);
}
