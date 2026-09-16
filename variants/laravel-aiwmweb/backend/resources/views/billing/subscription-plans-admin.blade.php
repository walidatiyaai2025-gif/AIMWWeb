<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ app()->getLocale() === 'ar' ? 'خطط الاشتراك' : 'Subscription Plans' }}</title>
</head>
<body>
<main data-canonical-operation="AIMW-BILL-B86D339C12" data-tenant="{{ $tenant->slug }}">
    <header>
        <p>{{ app()->getLocale() === 'ar' ? 'الإدارة والفوترة' : 'ADMIN · BILLING' }}</p>
        <h1>{{ app()->getLocale() === 'ar' ? 'خطط الاشتراك' : 'Subscription Plans' }}</h1>
        <p>{{ app()->getLocale() === 'ar'
            ? 'عرض حقيقي للكتالوج المحفوظ. عمليات الإنشاء والتعديل والتعطيل تُغلق بشكل مستقل.'
            : 'Authoritative persisted catalog view. Create, edit, clone and enable/disable controls close independently.' }}</p>
    </header>

    <section aria-label="{{ app()->getLocale() === 'ar' ? 'كتالوج الخطط' : 'Plan catalog' }}">
        @forelse ($plans as $plan)
            <article data-plan-code="{{ $plan->code }}">
                <h2>{{ $plan->localized_name[app()->getLocale()] ?? $plan->name }}</h2>
                <p><code>{{ $plan->code }}</code></p>
                @if (filled($plan->description))
                    <p>{{ $plan->description }}</p>
                @endif
                <dl>
                    <dt>{{ app()->getLocale() === 'ar' ? 'السعر' : 'Price' }}</dt>
                    <dd>{{ $plan->price_minor === null ? '—' : number_format($plan->price_minor / 100, 2) }} {{ $plan->currency }}</dd>
                    <dt>{{ app()->getLocale() === 'ar' ? 'دورة الفوترة' : 'Billing interval' }}</dt>
                    <dd>{{ $plan->billing_interval }}</dd>
                    <dt>{{ app()->getLocale() === 'ar' ? 'أيام التجربة' : 'Trial days' }}</dt>
                    <dd>{{ $plan->trial_period_days }}</dd>
                    <dt>{{ app()->getLocale() === 'ar' ? 'أيام السماح' : 'Grace days' }}</dt>
                    <dd>{{ $plan->grace_period_days }}</dd>
                    <dt>{{ app()->getLocale() === 'ar' ? 'الحالة' : 'Status' }}</dt>
                    <dd>{{ $plan->enabled && $plan->retired_at === null ? (app()->getLocale() === 'ar' ? 'مفعلة' : 'Enabled') : (app()->getLocale() === 'ar' ? 'معطلة' : 'Disabled') }}</dd>
                </dl>
            </article>
        @empty
            <p role="status">{{ app()->getLocale() === 'ar' ? 'لا توجد خطط اشتراك محفوظة.' : 'No persisted subscription plans.' }}</p>
        @endforelse
    </section>
</main>
</body>
</html>
