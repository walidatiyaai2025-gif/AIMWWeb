import React, { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import type { Locale } from './core';

type LocalizedText = { en: string; ar: string };

interface LocaleContextValue {
    locale: Locale;
    direction: 'ltr' | 'rtl';
    setLocale: (locale: Locale) => void;
    toggleLocale: () => void;
    text: (value: LocalizedText) => string;
}

const LocaleContext = createContext<LocaleContextValue | null>(null);

export function LocaleProvider({ children }: { children: React.ReactNode }) {
    const [locale, setLocaleState] = useState<Locale>(() => {
        const stored = window.localStorage.getItem('aiwm.locale');
        return stored === 'ar' ? 'ar' : 'en';
    });

    const setLocale = useCallback((next: Locale) => {
        setLocaleState(next);
        window.localStorage.setItem('aiwm.locale', next);
    }, []);

    const toggleLocale = useCallback(() => setLocale(locale === 'ar' ? 'en' : 'ar'), [locale, setLocale]);

    useEffect(() => {
        document.documentElement.lang = locale;
        document.documentElement.dir = locale === 'ar' ? 'rtl' : 'ltr';
    }, [locale]);

    const value = useMemo<LocaleContextValue>(() => ({
        locale,
        direction: locale === 'ar' ? 'rtl' : 'ltr',
        setLocale,
        toggleLocale,
        text: (localized) => localized[locale],
    }), [locale, setLocale, toggleLocale]);

    return <LocaleContext.Provider value={value}>{children}</LocaleContext.Provider>;
}

export function useLocale(): LocaleContextValue {
    const value = useContext(LocaleContext);
    if (!value) throw new Error('useLocale must be used inside LocaleProvider.');
    return value;
}

export const commonText = {
    loading: { en: 'Loading live data…', ar: 'جارٍ تحميل البيانات الحية…' },
    empty: { en: 'No records were returned.', ar: 'لم يتم إرجاع سجلات.' },
    retry: { en: 'Retry', ar: 'إعادة المحاولة' },
    refresh: { en: 'Refresh', ar: 'تحديث' },
    search: { en: 'Search', ar: 'بحث' },
    unavailable: { en: 'Capability unavailable', ar: 'القدرة غير متاحة' },
    pending: { en: 'Pending backend integration', ar: 'تكامل الخادم قيد الانتظار' },
    previous: { en: 'Previous', ar: 'السابق' },
    next: { en: 'Next', ar: 'التالي' },
    close: { en: 'Close', ar: 'إغلاق' },
    submit: { en: 'Submit', ar: 'تنفيذ' },
    required: { en: 'This field is required.', ar: 'هذا الحقل مطلوب.' },
    noPermission: { en: 'You do not have permission to view this workspace.', ar: 'ليس لديك صلاحية لعرض مساحة العمل هذه.' },
    apiError: { en: 'The server returned an error.', ar: 'أعاد الخادم خطأ.' },
    tenant: { en: 'Tenant', ar: 'الحساب' },
    quickSearch: { en: 'Quick search', ar: 'بحث سريع' },
    dashboard: { en: 'Dashboard', ar: 'لوحة التحكم' },
} as const satisfies Record<string, LocalizedText>;
