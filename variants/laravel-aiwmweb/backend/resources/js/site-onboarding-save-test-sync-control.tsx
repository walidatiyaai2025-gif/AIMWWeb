import React, { FormEvent, useRef, useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { ApiError, apiRequest, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const SITE_ONBOARDING_SAVE_TEST_SYNC_OPERATION_ID = 'AIMW-BILL-2EF6B8A27A';

type OnboardingResponse = {
    operation_id: string;
    message?: string;
    site: {
        id: number;
        name: string;
        url: string;
        connection_status: string;
        health_state: string;
        last_verified_at?: string | null;
    };
    credential_configured: boolean;
    sync: {
        id: number;
        status: string;
        processed: number;
        failure?: string | null;
    } | null;
    idempotent_replay: boolean;
};

export function canonicalSiteOnboardingEndpoint(sitesEndpoint: string | undefined): string | null {
    if (!sitesEndpoint || !/^\/api\/tenants\/[^/]+\/sites$/.test(sitesEndpoint)) return null;
    return `${sitesEndpoint}/onboarding`;
}

export function onboardingRetryTokenFromError(error: unknown): string | null {
    if (!(error instanceof ApiError)) return null;
    const token = error.payload.retry_token;
    return typeof token === 'string' && token.trim() !== '' ? token : null;
}

function nextIdempotencyKey(): string {
    if (typeof globalThis.crypto?.randomUUID === 'function') return globalThis.crypto.randomUUID();
    return `site-onboarding-${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

export function SiteOnboardingSaveTestSyncControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const endpoint = canonicalSiteOnboardingEndpoint(context.api.sites);
    const canManage = context.permissions.includes('*') || context.permissions.includes('sites.manage');
    const [name, setName] = useState('');
    const [url, setUrl] = useState('');
    const [username, setUsername] = useState('');
    const [applicationPassword, setApplicationPassword] = useState('');
    const [result, setResult] = useState<OnboardingResponse | null>(null);
    const [retryToken, setRetryToken] = useState<string | null>(null);
    const idempotencyKey = useRef<string | null>(null);

    const resetRequestIdentity = () => {
        idempotencyKey.current = null;
        setResult(null);
    };

    const mutation = useMutation({
        mutationFn: async () => {
            if (!endpoint) throw new Error('Site onboarding endpoint is unavailable.');
            const key = idempotencyKey.current ?? nextIdempotencyKey();
            idempotencyKey.current = key;

            try {
                return await apiRequest<OnboardingResponse>(endpoint, {
                    method: 'POST',
                    headers: { 'Idempotency-Key': key },
                    body: JSON.stringify({
                        name: name.trim(),
                        url: url.trim(),
                        username: username.trim(),
                        application_password: applicationPassword,
                        retry_token: retryToken ?? undefined,
                    }),
                });
            } finally {
                setApplicationPassword('');
            }
        },
        onSuccess: (payload) => {
            setResult(payload);
            setRetryToken(null);
            idempotencyKey.current = null;
        },
        onError: (error) => {
            const issuedRetryToken = onboardingRetryTokenFromError(error);
            if (issuedRetryToken) setRetryToken(issuedRetryToken);
            if (error instanceof ApiError && (error.status === 409 || error.status === 422)) {
                idempotencyKey.current = null;
            }
        },
    });

    if (!canManage || !endpoint) return null;

    const error = mutation.error;
    const errorMessage = error instanceof ApiError
        ? error.message
        : error instanceof Error
            ? error.message
            : null;
    const disabled = mutation.isPending
        || name.trim() === ''
        || url.trim() === ''
        || username.trim() === ''
        || applicationPassword.length < 8;

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        mutation.mutate();
    };

    return (
        <section
            className="workspace-stack site-onboarding-save-test-sync"
            data-canonical-operation={SITE_ONBOARDING_SAVE_TEST_SYNC_OPERATION_ID}
            aria-label={locale === 'ar' ? 'حفظ واختبار ومزامنة موقع WordPress' : 'Save, test and synchronize WordPress site'}
        >
            <section className="hero-panel">
                <div>
                    <span className="workspace-kicker">SITE ONBOARDING</span>
                    <h2>{locale === 'ar' ? 'ربط موقع WordPress' : 'Connect a WordPress site'}</h2>
                    <p>
                        {locale === 'ar'
                            ? 'يُحفظ ملف الموقع أولًا، ثم يتم اختبار REST API، ولا تُحفظ بيانات الاعتماد المشفرة ولا تبدأ المزامنة الأولية إلا بعد نجاح الاختبار.'
                            : 'The site profile is saved first, the REST API is tested next, credentials are encrypted only after verification succeeds, and then initial synchronization starts.'}
                    </p>
                </div>
            </section>

            <form className="toolbar-panel" onSubmit={submit}>
                <label>
                    <span>{locale === 'ar' ? 'اسم الموقع' : 'Site name'}</span>
                    <input value={name} disabled={mutation.isPending} onChange={(event) => { setName(event.target.value); resetRequestIdentity(); }} />
                </label>
                <label>
                    <span>{locale === 'ar' ? 'رابط الموقع' : 'Site URL'}</span>
                    <input type="url" value={url} disabled={mutation.isPending} placeholder="https://example.com" onChange={(event) => { setUrl(event.target.value); resetRequestIdentity(); }} />
                </label>
                <label>
                    <span>{locale === 'ar' ? 'اسم مستخدم WordPress' : 'WordPress username'}</span>
                    <input autoComplete="username" value={username} disabled={mutation.isPending} onChange={(event) => { setUsername(event.target.value); resetRequestIdentity(); }} />
                </label>
                <label>
                    <span>Application Password</span>
                    <input
                        type="password"
                        autoComplete="new-password"
                        value={applicationPassword}
                        disabled={mutation.isPending}
                        onChange={(event) => setApplicationPassword(event.target.value)}
                    />
                </label>
                <p className="settings-note">
                    {locale === 'ar'
                        ? 'لا تتم إعادة عرض Application Password بعد الإرسال.'
                        : 'The Application Password is cleared from the browser after submission and is never echoed back.'}
                </p>
                {errorMessage ? <p role="alert">{errorMessage}</p> : null}
                <button type="submit" className="btn primary" disabled={disabled} data-canonical-operation={SITE_ONBOARDING_SAVE_TEST_SYNC_OPERATION_ID}>
                    {mutation.isPending
                        ? (locale === 'ar' ? 'جارٍ الحفظ والاختبار وبدء المزامنة…' : 'Saving, testing & starting sync…')
                        : (locale === 'ar' ? 'حفظ واختبار ومزامنة' : 'Save, test and synchronize')}
                </button>
            </form>

            {result ? (
                <section className="toolbar-panel" aria-live="polite">
                    <strong>{result.site.name}</strong>
                    <span>{result.site.connection_status} / {result.site.health_state}</span>
                    {result.sync ? <span>{locale === 'ar' ? 'المزامنة' : 'Sync'}: {result.sync.status}</span> : null}
                    {result.message ? <p>{result.message}</p> : null}
                </section>
            ) : null}
        </section>
    );
}
