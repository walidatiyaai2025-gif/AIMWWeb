import React, { FormEvent, useEffect, useRef, useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { ApiError, apiRequest, tenantUrl, type FrontendContext } from './core';
import { useLocale } from './i18n';
import { SyncFormActionsControl } from './sync-form-actions-control';

export const SITE_ONBOARDING_SAVE_TEST_SYNC_OPERATION_ID = 'AIMW-BILL-2EF6B8A27A';
export const SITE_ONBOARDING_RETRY_SYNC_OPERATION_ID = 'AIMW-SYNC-AE16CF49C6';
export const SITE_ONBOARDING_NEXT_OPERATION_ID = 'AIMW-BILL-5DE86937F7';
export const SITE_ONBOARDING_BACK_TO_SITES_OPERATION_ID = 'AIMW-CONT-6EC0601E18';

type Locale = 'en' | 'ar';

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

export function canonicalOnboardingRetrySyncEndpoint(sitesEndpoint: string | undefined, siteId: number): string | null {
    if (!sitesEndpoint || !/^\/api\/tenants\/[^/?#]+\/sites$/.test(sitesEndpoint)) return null;
    if (!Number.isSafeInteger(siteId) || siteId < 1) return null;
    return `${sitesEndpoint}/${siteId}/sync`;
}

export function onboardingRetryTokenFromError(error: unknown): string | null {
    if (!(error instanceof ApiError)) return null;
    const token = error.payload.retry_token;
    return typeof token === 'string' && token.trim() !== '' ? token : null;
}

export function validateOnboardingStep(
    step: 1 | 2,
    values: { name: string; url: string; username: string; applicationPassword: string },
    locale: Locale,
): string | null {
    if (step === 1) {
        if (values.name.trim() === '') {
            return locale === 'ar' ? 'اسم الموقع مطلوب.' : 'Site name is required.';
        }

        try {
            const parsed = new URL(values.url.trim());
            if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
                throw new Error('Unsupported protocol');
            }
        } catch {
            return locale === 'ar'
                ? 'أدخل رابطًا صحيحًا يبدأ بـ http أو https.'
                : 'Enter a valid URL starting with http or https.';
        }

        return null;
    }

    if (values.username.trim() === '') {
        return locale === 'ar' ? 'اسم مستخدم WordPress مطلوب.' : 'WordPress username is required.';
    }

    if (values.applicationPassword.trim().length < 8) {
        return locale === 'ar'
            ? 'Application Password مطلوب ويجب ألا يقل عن 8 أحرف.'
            : 'Application Password is required and must contain at least 8 characters.';
    }

    return null;
}

export function siteOnboardingBackToSitesHref(tenantSlug: string): string | null {
    if (!tenantSlug.trim() || /[\\/]/.test(tenantSlug)) return null;
    return tenantUrl(tenantSlug, '/sites');
}

function nextIdempotencyKey(): string {
    if (typeof globalThis.crypto?.randomUUID === 'function') return globalThis.crypto.randomUUID();
    return `site-onboarding-${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

export function SiteOnboardingSaveTestSyncControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const endpoint = canonicalSiteOnboardingEndpoint(context.api.sites);
    const canManage = context.permissions.includes('*') || context.permissions.includes('sites.manage');
    const [step, setStep] = useState<1 | 2 | 3>(1);
    const [stepError, setStepError] = useState('');
    const [name, setName] = useState('');
    const [url, setUrl] = useState('');
    const [username, setUsername] = useState('');
    const [applicationPassword, setApplicationPassword] = useState('');
    const [result, setResult] = useState<OnboardingResponse | null>(null);
    const [retryToken, setRetryToken] = useState<string | null>(null);
    const [retryBusy, setRetryBusy] = useState(false);
    const [retryError, setRetryError] = useState('');
    const idempotencyKey = useRef<string | null>(null);

    useEffect(() => {
        const currentRun = result?.sync;
        if (!currentRun || !['queued', 'running'].includes(currentRun.status)) return;
        if (!Number.isSafeInteger(currentRun.id) || currentRun.id < 1) return;
        const match = context.api.sites?.match(/^\/api\/tenants\/([^/?#]+)\/sites$/);
        if (!match) return;
        let active = true;
        const runEndpoint = `/api/tenants/${match[1]}/sync-runs/${currentRun.id}`;
        const interval = window.setInterval(() => {
            void apiRequest<NonNullable<OnboardingResponse['sync']>>(runEndpoint)
                .then((updated) => {
                    if (!active || updated.id !== currentRun.id) return;
                    setResult((previous) => previous?.sync?.id === currentRun.id
                        ? { ...previous, sync: updated }
                        : previous);
                })
                .catch(() => {
                    // Do not infer success from a failed status request.
                });
        }, 1500);
        return () => {
            active = false;
            window.clearInterval(interval);
        };
    }, [context.api.sites, result?.sync?.id, result?.sync?.status]);

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

    const retrySynchronization = async () => {
        if (retryBusy || !result || result.sync?.status !== 'failed') return;
        const syncEndpoint = canonicalOnboardingRetrySyncEndpoint(context.api.sites, result.site.id);
        if (!syncEndpoint) return;
        setRetryBusy(true);
        setRetryError('');
        try {
            const run = await apiRequest<NonNullable<OnboardingResponse['sync']>>(syncEndpoint, {
                method: 'POST',
                headers: { 'Idempotency-Key': nextIdempotencyKey() },
                body: JSON.stringify({}),
            });
            if (run.status === 'failed') throw new Error(run.failure || 'Synchronization failed.');
            setResult((current) => current ? { ...current, sync: run } : current);
        } catch (error) {
            setRetryError(error instanceof Error ? error.message : 'Synchronization failed.');
        } finally {
            setRetryBusy(false);
        }
    };

    if (!canManage || !endpoint) return null;

    const backToSitesHref = siteOnboardingBackToSitesHref(context.tenant.slug);
    if (!backToSitesHref) return null;

    const error = mutation.error;
    const errorMessage = error instanceof ApiError
        ? error.message
        : error instanceof Error
            ? error.message
            : null;

    const values = { name, url, username, applicationPassword };
    const continueStep = () => {
        if (step === 3 || mutation.isPending) return;

        const validationError = validateOnboardingStep(step, values, locale);
        if (validationError) {
            setStepError(validationError);
            return;
        }

        setStepError('');
        setStep((step + 1) as 2 | 3);
    };

    const previousStep = () => {
        if (step === 1 || mutation.isPending) return;
        setStepError('');
        setStep((step - 1) as 1 | 2);
    };

    const finalSubmitDisabled = mutation.isPending
        || validateOnboardingStep(1, values, locale) !== null
        || validateOnboardingStep(2, values, locale) !== null;

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (step < 3) {
            continueStep();
            return;
        }
        if (!finalSubmitDisabled) mutation.mutate();
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
                            ? 'أدخل بيانات الموقع، ثم بيانات اعتماد WordPress، ثم راجعها قبل الحفظ والاختبار والمزامنة.'
                            : 'Enter the site profile, then WordPress credentials, then review them before save, test and synchronization.'}
                    </p>
                </div>
                <a
                    className="btn"
                    href={backToSitesHref}
                    data-canonical-operation={SITE_ONBOARDING_BACK_TO_SITES_OPERATION_ID}
                    data-source-control="Back to sites"
                >
                    ← {locale === 'ar' ? 'العودة للمواقع' : 'Back to sites'}
                </a>
            </section>

            <form className="toolbar-panel" onSubmit={submit}>
                <div aria-live="polite">
                    <strong>{locale === 'ar' ? `الخطوة ${step} من 3` : `Step ${step} of 3`}</strong>
                </div>

                {step === 1 ? (
                    <>
                        <label>
                            <span>{locale === 'ar' ? 'اسم الموقع' : 'Site name'}</span>
                            <input
                                value={name}
                                disabled={mutation.isPending}
                                onChange={(event) => {
                                    setName(event.target.value);
                                    setStepError('');
                                    resetRequestIdentity();
                                }}
                            />
                        </label>
                        <label>
                            <span>{locale === 'ar' ? 'رابط الموقع' : 'Site URL'}</span>
                            <input
                                type="url"
                                value={url}
                                disabled={mutation.isPending}
                                placeholder="https://example.com"
                                onChange={(event) => {
                                    setUrl(event.target.value);
                                    setStepError('');
                                    resetRequestIdentity();
                                }}
                            />
                        </label>
                    </>
                ) : null}

                {step === 2 ? (
                    <>
                        <label>
                            <span>{locale === 'ar' ? 'اسم مستخدم WordPress' : 'WordPress username'}</span>
                            <input
                                autoComplete="username"
                                value={username}
                                disabled={mutation.isPending}
                                onChange={(event) => {
                                    setUsername(event.target.value);
                                    setStepError('');
                                    resetRequestIdentity();
                                }}
                            />
                        </label>
                        <label>
                            <span>Application Password</span>
                            <input
                                type="password"
                                autoComplete="new-password"
                                value={applicationPassword}
                                disabled={mutation.isPending}
                                onChange={(event) => {
                                    setApplicationPassword(event.target.value);
                                    setStepError('');
                                }}
                            />
                        </label>
                        <p className="settings-note">
                            {locale === 'ar'
                                ? 'لا تتم إعادة عرض Application Password بعد الإرسال.'
                                : 'The Application Password is cleared from the browser after submission and is never echoed back.'}
                        </p>
                    </>
                ) : null}

                {step === 3 ? (
                    <section className="panel" aria-label={locale === 'ar' ? 'مراجعة بيانات الربط' : 'Connection review'}>
                        <dl className="contract-details">
                            <div><dt>{locale === 'ar' ? 'الموقع' : 'Site'}</dt><dd>{name.trim()}</dd></div>
                            <div><dt>{locale === 'ar' ? 'الرابط' : 'URL'}</dt><dd>{url.trim()}</dd></div>
                            <div><dt>{locale === 'ar' ? 'المستخدم' : 'Username'}</dt><dd>{username.trim()}</dd></div>
                            <div><dt>{locale === 'ar' ? 'كلمة مرور التطبيق' : 'Application Password'}</dt><dd>••••••••</dd></div>
                        </dl>
                    </section>
                ) : null}

                {stepError ? <p role="alert">{stepError}</p> : null}
                {errorMessage ? <p role="alert">{errorMessage}</p> : null}

                {step < 3 ? (
                    <div className="toolbar-actions">
                        {step > 1 ? (
                            <button type="button" className="btn" disabled={mutation.isPending} onClick={previousStep}>
                                {locale === 'ar' ? 'السابق' : 'Previous'}
                            </button>
                        ) : null}
                        <button
                            type="button"
                            className="btn primary"
                            disabled={mutation.isPending}
                            data-canonical-operation={SITE_ONBOARDING_NEXT_OPERATION_ID}
                            onClick={continueStep}
                        >
                            {locale === 'ar' ? 'التالي' : 'Continue'}
                        </button>
                    </div>
                ) : (
                    <SyncFormActionsControl
                        context={context}
                        busy={mutation.isPending}
                        disabled={finalSubmitDisabled}
                        dirty={!result}
                        hint={locale === 'ar' ? 'سيتم الحفظ والاختبار ثم بدء المزامنة الفعلية.' : 'This will save, test, then start the real synchronization.'}
                        cancelText={locale === 'ar' ? 'السابق' : 'Previous'}
                        saveText={locale === 'ar' ? 'حفظ واختبار ومزامنة' : 'Save, test and synchronize'}
                        savingText={locale === 'ar' ? 'جارٍ الحفظ والاختبار وبدء المزامنة…' : 'Saving, testing & starting sync…'}
                        saveOperationId={SITE_ONBOARDING_SAVE_TEST_SYNC_OPERATION_ID}
                        onCancel={previousStep}
                        onSave={() => mutation.mutateAsync().catch(() => undefined)}
                    />
                )}
            </form>

            {result ? (
                <section className="toolbar-panel" aria-live="polite">
                    <strong>{result.site.name}</strong>
                    <span>{result.site.connection_status} / {result.site.health_state}</span>
                    {result.sync ? <span>{locale === 'ar' ? 'المزامنة' : 'Sync'}: {result.sync.status}</span> : null}
                    {retryError ? <p role="alert">{retryError}</p> : null}
                    {result.sync?.status === 'failed' ? (
                        <button type="button" className="btn"
                            data-canonical-operation={SITE_ONBOARDING_RETRY_SYNC_OPERATION_ID}
                            disabled={retryBusy} onClick={() => void retrySynchronization()}>
                            {retryBusy
                                ? (locale === 'ar' ? 'جارٍ إعادة المزامنة…' : 'Retrying synchronization…')
                                : (locale === 'ar' ? 'إعادة المزامنة' : 'Retry synchronization')}
                        </button>
                    ) : null}
                    {result.message ? <p>{result.message}</p> : null}
                    <a
                        className="btn"
                        href={backToSitesHref}
                        data-canonical-operation={SITE_ONBOARDING_BACK_TO_SITES_OPERATION_ID}
                        data-source-control="View all sites"
                    >
                        {locale === 'ar' ? 'عرض كل المواقع' : 'View all sites'}
                    </a>
                </section>
            ) : null}
        </section>
    );
}
