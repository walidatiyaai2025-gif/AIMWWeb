import React from 'react';
import { Link } from 'react-router-dom';
import { tenantUrl, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const SETTINGS_DATABASE_ANCHOR_OPERATION_ID = 'AIMW-CONT-932A09E4A9';
export const SETTINGS_DATABASE_TARGET_ID = 'database';

export function SettingsDatabaseAnchorControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const canViewSettings = context.permissions.includes('*') || context.permissions.includes('tenant.view');
    const canViewDiagnostics = context.permissions.includes('*') || context.permissions.includes('diagnostics.view');

    if (!canViewSettings) return null;

    return (
        <>
            <nav className="toolbar-panel" aria-label={locale === 'ar' ? 'أقسام الإعدادات' : 'Settings sections'}>
                <div>
                    <span className="workspace-kicker">DATABASE</span>
                    <strong>{locale === 'ar' ? 'قاعدة البيانات' : 'Database'}</strong>
                    <small>{locale === 'ar' ? 'موفر قاعدة بيانات التطبيق' : 'Application database provider'}</small>
                </div>
                <div className="toolbar-actions">
                    <a
                        className="btn"
                        href={'#' + SETTINGS_DATABASE_TARGET_ID}
                        aria-controls={SETTINGS_DATABASE_TARGET_ID}
                        data-canonical-operation={SETTINGS_DATABASE_ANCHOR_OPERATION_ID}
                    >
                        <span aria-hidden="true">DB</span>
                        {locale === 'ar' ? 'قاعدة البيانات' : 'Database'}
                    </a>
                </div>
            </nav>

            <section
                id={SETTINGS_DATABASE_TARGET_ID}
                className="toolbar-panel"
                aria-labelledby="settings-database-heading"
                data-settings-section="database"
            >
                <div>
                    <span className="workspace-kicker">DATABASE</span>
                    <strong id="settings-database-heading">{locale === 'ar' ? 'إعداد قاعدة البيانات' : 'Database configuration'}</strong>
                    <small>
                        {locale === 'ar'
                            ? 'تُقرأ حالة قاعدة البيانات الفعلية من تشخيصات تشغيل Laravel ولا يتم اختلاق موفر أو اتصال من الواجهة.'
                            : 'Authoritative database state is reported by Laravel runtime diagnostics; this screen does not fabricate provider or connection values.'}
                    </small>
                </div>
                {canViewDiagnostics ? (
                    <div className="toolbar-actions">
                        <Link className="btn" to={tenantUrl(context.tenant.slug, '/system-health')}>
                            {locale === 'ar' ? 'فتح صحة النظام' : 'Open System Health'}
                        </Link>
                    </div>
                ) : null}
            </section>
        </>
    );
}
