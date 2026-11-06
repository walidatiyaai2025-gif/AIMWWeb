import React, { useMemo, useState } from 'react';
import { tenantUrl, type FrontendContext } from './core';
import { useLocale } from './i18n';
import { workspaceCapabilityCensus, type WorkspaceCensusState } from './workspace-capability-census';

const stateLabels: Record<WorkspaceCensusState, { en: string; ar: string }> = {
    missing_permission: { en: 'Tenant permission missing', ar: 'صلاحية الحساب ناقصة' },
    backend_restricted: { en: 'Backend capability restricted', ar: 'الخادم يرفض القدرة' },
    connector_read_missing: { en: 'Connector read scope absent', ar: 'صلاحية قراءة البلجن ناقصة' },
    contract_not_advertised: { en: 'API contract not advertised (review)', ar: 'عقد API غير معلن (يحتاج مراجعة)' },
    declared_accessible: { en: 'Declared accessible; not runtime-tested', ar: 'معلن كمتاح، لم يُختبر فعليًا' },
};

export function WorkspaceCapabilityCensusPanel({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const ar = locale === 'ar';
    const [showAll, setShowAll] = useState(false);
    const result = useMemo(() => workspaceCapabilityCensus(context), [context]);
    const entries = showAll ? result.workspaces
        : result.workspaces.filter(item => item.view_state !== 'declared_accessible');

    return <section className="panel workspace-stack" data-testid="workspace-capability-census">
        <h3>{ar ? 'جرد وظائف وصلاحيات جميع المساحات' : 'All-workspace capability census'}</h3>
        <p>{ar
            ? 'تحليل محلي آمن لجميع المسارات المعلنة، دون إرسال طلبات AI أو تنفيذ أوامر WordPress. الحالة المعلنة ليست دليلًا على نجاح التشغيل.'
            : 'Safe local scan of every declared workspace; no AI or WordPress requests. Advertised access does not prove actual operation.'}</p>
        <div className="status-grid" role="status">
            <span>{ar ? 'المساحات' : 'Workspaces'}: {result.totals.routes}</span>
            <span>{ar ? 'صلاحيات ناقصة' : 'Missing role permissions'}: {result.totals.missing_permission}</span>
            <span>{ar ? 'قيود الخادم' : 'Backend restrictions'}: {result.totals.backend_restricted}</span>
            <span>{ar ? 'صلاحيات البلجن' : 'Connector read gaps'}: {result.totals.connector_read_missing}</span>
            <span>{ar ? 'عقود غير معلنة' : 'API contracts to review'}: {result.totals.contract_not_advertised}</span>
            <span>{ar ? 'معلنة كمتاحة' : 'Declared accessible'}: {result.totals.declared_accessible}</span>
            <span>{ar ? 'عقود أفعال ظاهرة' : 'Server action contracts'}: {result.totals.write_controls_advertised}/{result.totals.write_controls_declared}</span>
        </div>
        <details>
            <summary>{ar ? 'تفاصيل الجرد والفجوات' : 'Detailed workspace findings'}</summary>
            <button type="button" className="btn" aria-pressed={showAll} onClick={() => setShowAll(value => !value)}>
                {showAll ? (ar ? 'الفجوات فقط' : 'Only findings') : (ar ? 'كل المساحات' : 'All workspaces')}
            </button>
            {!entries.length && <p role="status">{ar ? 'لا توجد فجوات في العقود المعلنة؛ الوظائف الفعلية ما زالت تحتاج اختبارات تشغيل.' :
                'No declared contract gaps; actual workflows still need runtime testing.'}</p>}
            <div className="data-table-scroll">
                <table>
                    <thead><tr>
                        <th>{ar ? 'المساحة' : 'Workspace'}</th>
                        <th>{ar ? 'حالة العقد' : 'Declared state'}</th>
                        <th>{ar ? 'الصلاحية' : 'Permission'}</th>
                        <th>{ar ? 'البلجن' : 'Connector'}</th>
                        <th>API</th>
                        <th>{ar ? 'عقود الكتابة' : 'Write contracts'}</th>
                    </tr></thead>
                    <tbody>{entries.map(item => <tr key={item.key}>
                        <td>{!item.path.includes(':')
                            ? <a href={tenantUrl(context.tenant.slug, item.path)}>{item.key}</a>
                            : <code>{item.key}</code>}</td>
                        <td>{stateLabels[item.view_state][locale]}</td>
                        <td><code>{item.required_permission ?? '—'}</code>{!item.permission_present ? ' ✕' : ''}</td>
                        <td>{item.connector_read}</td>
                        <td>{item.api_contract}</td>
                        <td>{item.write_controls_advertised}/{item.write_controls_declared}</td>
                    </tr>)}</tbody>
                </table>
            </div>
        </details>
    </section>;
}
