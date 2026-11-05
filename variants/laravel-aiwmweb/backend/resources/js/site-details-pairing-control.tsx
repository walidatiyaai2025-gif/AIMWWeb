import React, { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { apiRequest, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const SITE_DETAILS_PAIRING_OPERATION_ID = 'AIMW-CONNECTOR-1438-PAIR';

type SiteDetailsPayload = { id?: number; name?: string };
type PairingResponse = { pairing_token: string; expires_in: number };
type IssuedToken = { value: string; expiresAt: number };

export function canonicalExistingSitePairingEndpoint(
    detailEndpoint: string | undefined,
    tenantSlug: string,
    siteKey: string,
): string | null {
    if (!detailEndpoint || !tenantSlug || !/^sites\.detail\.[1-9]\d*$/.test(siteKey)) return null;
    const match = detailEndpoint.match(/^\/api\/tenants\/([^/?#]+)\/sites\/([1-9]\d*)$/);
    if (!match || match[1] !== encodeURIComponent(tenantSlug) || siteKey !== `sites.detail.${match[2]}`) return null;
    return `${detailEndpoint}/pairing`;
}

export function SiteDetailsPairingControl({ context }: { context: FrontendContext }) {
    const permitted = context.permissions.includes('*') || context.permissions.includes('connector.manage');
    const matched = useMemo(() => {
        const entries = Object.entries(context.api).filter(([key]) => key.startsWith('sites.detail.'));
        if (entries.length !== 1) return null;
        const [siteKey, detailEndpoint] = entries[0];
        const pairingEndpoint = canonicalExistingSitePairingEndpoint(detailEndpoint, context.tenant.slug, siteKey);
        return pairingEndpoint && detailEndpoint ? { detailEndpoint, pairingEndpoint, siteId: Number(siteKey.slice(13)) } : null;
    }, [context.api, context.tenant.slug]);

    // Authorization and route validation happen before any query or mutation hooks run.
    if (!permitted || !matched) return null;
    return <AuthorizedSiteDetailsPairingControl key={matched.pairingEndpoint} context={context} {...matched} />;
}

function AuthorizedSiteDetailsPairingControl({
    context,
    detailEndpoint,
    pairingEndpoint,
    siteId,
}: {
    context: FrontendContext;
    detailEndpoint: string;
    pairingEndpoint: string;
    siteId: number;
}) {
    const { locale } = useLocale();
    const [issued, setIssued] = useState<IssuedToken | null>(null);
    const [copyMessage, setCopyMessage] = useState('');
    const details = useQuery({
        queryKey: ['site-details-connector-pairing', context.tenant.slug, detailEndpoint],
        queryFn: () => apiRequest<SiteDetailsPayload>(detailEndpoint),
        retry: false,
    });

    const mutation = useMutation({
        mutationFn: async (): Promise<IssuedToken> => {
            const response = await apiRequest<PairingResponse>(pairingEndpoint, { method: 'POST' });
            if (!response || !/^[A-Za-z0-9]{64}$/.test(response.pairing_token)
                || !Number.isFinite(response.expires_in) || response.expires_in <= 0 || response.expires_in > 600) {
                throw new Error('Server returned an invalid pairing token.');
            }
            return { value: response.pairing_token, expiresAt: Date.now() + response.expires_in * 1000 };
        },
        onSuccess: (token) => setIssued(token),
    });

    useEffect(() => {
        if (!issued) return;
        const timeout = window.setTimeout(() => {
            setIssued((current) => current?.value === issued.value ? null : current);
            setCopyMessage('');
        }, Math.max(0, issued.expiresAt - Date.now()));
        return () => window.clearTimeout(timeout);
    }, [issued]);

    const requestToken = () => {
        if (mutation.isPending || details.data?.id !== siteId) return;
        // Every new issuance invalidates the previous unused token server-side.
        setIssued(null);
        setCopyMessage('');
        mutation.mutate();
    };

    const copyToken = async () => {
        if (!issued || issued.expiresAt <= Date.now()) return;
        try {
            if (!navigator.clipboard?.writeText) throw new Error('Clipboard unavailable');
            await navigator.clipboard.writeText(issued.value);
            setCopyMessage(locale === 'ar' ? 'تم نسخ الرمز.' : 'Token copied.');
        } catch {
            setCopyMessage(locale === 'ar'
                ? 'النسخ التلقائي غير متاح. حدّد الرمز وانسخه يدويًا.'
                : 'Clipboard unavailable. Select the token and copy it manually.');
        }
    };

    const platformUrl = window.location.origin;
    const validSite = details.data?.id === siteId;
    const error = mutation.error instanceof Error ? mutation.error.message : null;

    return (
        <section className="toolbar-panel site-details-pairing" data-canonical-operation={SITE_DETAILS_PAIRING_OPERATION_ID}
            aria-label={locale === 'ar' ? 'إقران AIMW Connector للموقع الحالي' : 'Pair AIMW Connector for existing site'}>
            <div>
                <span className="workspace-kicker">AIMW CONNECTOR</span>
                <h3>{locale === 'ar' ? 'ربط إضافة WordPress بالموقع الحالي' : 'Pair WordPress plugin with this site'}</h3>
                <p>{locale === 'ar'
                    ? 'لا تضف الموقع مرة أخرى. أنشئ رمزًا مؤقتًا هنا، ثم افتح WordPress ← أدوات ← AIMW Connector والصق رابط المنصة والرمز واضغط Connect & Verify.'
                    : 'Do not add this site again. Generate a temporary token, then open WordPress → Tools → AIMW Connector, paste the platform URL and token, and click Connect & Verify.'}</p>
                <p><strong>Platform URL:</strong> <code>{platformUrl}</code></p>
                {details.data?.name ? <small>{details.data.name}</small> : null}
                {details.isError ? <p role="alert">{locale === 'ar'
                    ? 'تعذر التحقق من ملكية الموقع. لا يمكن إصدار رمز إقران.'
                    : 'Could not verify site access. Pairing is unavailable.'}</p> : null}
                {error ? <p role="alert">{error}</p> : null}
                {issued ? (
                    <div role="status" className="panel">
                        <label htmlFor="existing-site-pairing-token">{locale === 'ar'
                            ? 'One-time pairing token — صالح لمدة 10 دقائق بحد أقصى'
                            : 'One-time pairing token — expires within 10 minutes'}</label>
                        <input id="existing-site-pairing-token" type="text" autoComplete="off" spellCheck={false}
                            readOnly value={issued.value} onFocus={(event) => event.currentTarget.select()} />
                        <button type="button" className="btn" onClick={() => void copyToken()}>
                            {locale === 'ar' ? 'نسخ الرمز' : 'Copy token'}
                        </button>
                        <p>{locale === 'ar'
                            ? 'يُستخدم مرة واحدة فقط. لا تشاركه ولا تحفظه؛ عند إنشاء رمز جديد يُلغى السابق.'
                            : 'Single use. Do not share or save it. Issuing a new token invalidates the previous one.'}</p>
                        {copyMessage ? <small role="status">{copyMessage}</small> : null}
                    </div>
                ) : null}
            </div>
            <button type="button" className="btn primary" data-canonical-operation={SITE_DETAILS_PAIRING_OPERATION_ID}
                disabled={!validSite || mutation.isPending || details.isLoading}
                onClick={requestToken}>
                {mutation.isPending
                    ? (locale === 'ar' ? 'جارٍ إنشاء الرمز…' : 'Generating token…')
                    : (locale === 'ar' ? 'إنشاء رمز إقران لمرة واحدة' : 'Generate one-time pairing token')}
            </button>
        </section>
    );
}
