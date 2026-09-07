import React from 'react';
import type { FrontendContext } from './core';
import { useLocale } from './i18n';

export const AI_WORKSPACE_OPERATION_ID = 'AIMW-AI-8EE4F9F6FC';

type WorkspaceCard = {
    key: 'ai-center' | 'ai-providers' | 'ai-prompts' | 'approvals';
    icon: string;
    label: { en: string; ar: string };
    description: { en: string; ar: string };
    path: string;
};

const cards: WorkspaceCard[] = [
    {
        key: 'ai-center',
        icon: '✦',
        label: { en: 'AI Center', ar: 'مركز الذكاء الاصطناعي' },
        description: { en: 'Open the operational AI workspace.', ar: 'افتح مساحة الذكاء الاصطناعي التشغيلية.' },
        path: '/ai-center',
    },
    {
        key: 'ai-providers',
        icon: '◈',
        label: { en: 'AI Providers', ar: 'مزودو الذكاء الاصطناعي' },
        description: { en: 'Manage provider connections, keys, and models.', ar: 'إدارة اتصالات المزودين والمفاتيح والنماذج.' },
        path: '/settings/ai-providers',
    },
    {
        key: 'ai-prompts',
        icon: '▤',
        label: { en: 'Prompt Templates', ar: 'قوالب الأوامر' },
        description: { en: 'Manage persisted templates through the real prompt store.', ar: 'إدارة القوالب المحفوظة عبر مخزن القوالب الفعلي.' },
        path: '/settings/ai-prompts',
    },
    {
        key: 'approvals',
        icon: '✓',
        label: { en: 'Approvals', ar: 'الموافقات' },
        description: { en: 'Review execution requests that require approval.', ar: 'مراجعة طلبات التنفيذ التي تتطلب موافقة.' },
        path: '/approvals',
    },
];

const tenantPath = (tenant: string, path: string): string =>
    `/tenants/${encodeURIComponent(tenant)}${path}`;

export function AiWorkspaceHub({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const isArabic = locale === 'ar';

    return (
        <div
            className="workspace-stack"
            data-canonical-operation={AI_WORKSPACE_OPERATION_ID}
            data-testid="ai-workspace-hub"
        >
            <div className="page-heading workspace-heading">
                <div>
                    <span className="workspace-kicker">AI</span>
                    <h1>{isArabic ? 'مساحة عمل الذكاء الاصطناعي' : 'AI Workspace'}</h1>
                    <p>
                        {isArabic
                            ? 'إدارة مركز الذكاء الاصطناعي والمزودين والقوالب من مساحات التشغيل الفعلية.'
                            : 'Manage the AI center, providers, and prompt templates in their real workspaces.'}
                    </p>
                </div>
                <a className="btn primary" href={tenantPath(context.tenant.slug, '/sites')}>
                    {isArabic ? 'اختيار موقع' : 'Select Site'}
                </a>
            </div>

            <section className="panel" data-testid="workspace-real-data-notice">
                <strong>{isArabic ? 'مساحات تشغيل حقيقية فقط' : 'Real workspaces only'}</strong>
                <p>
                    {isArabic
                        ? 'كل بطاقة أدناه تفتح وظيفة فعلية موجودة في النظام. تمت إزالة مؤشرات الجاهزية والبطاقات الوصفية غير القابلة للتنفيذ.'
                        : 'Every card below opens an implemented workspace. Static readiness badges and non-actionable prototype cards have been removed.'}
                </p>
            </section>

            <div className="workspace-grid">
                {cards.map((item) => (
                    <a
                        className="card workspace-card"
                        href={tenantPath(context.tenant.slug, item.path)}
                        key={item.key}
                        data-testid="workspace-link"
                        data-workspace-key={item.key}
                    >
                        <div className="workspace-card-icon">{item.icon}</div>
                        <div>
                            <h3>{isArabic ? item.label.ar : item.label.en}</h3>
                            <p>{isArabic ? item.description.ar : item.description.en}</p>
                        </div>
                        <span className="badge success">{isArabic ? 'فتح' : 'Open'} →</span>
                    </a>
                ))}
            </div>
        </div>
    );
}
