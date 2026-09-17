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
            ? 'عرض وحفظ حقيقيان لكتالوج الخطط المخزن.'
            : 'Authoritative persisted catalog view and save path.' }}</p>
        <nav aria-label="{{ app()->getLocale() === 'ar' ? 'روابط الفوترة' : 'Billing navigation' }}">
            <a
                href="{{ route('canonical.workspace.account-billing', ['tenant' => $tenant->slug]) }}"
                data-canonical-operation="AIMW-BILL-0CE205B851"
            >{{ app()->getLocale() === 'ar' ? 'صفحة العميل' : 'Customer billing' }}</a>
        </nav>
    </header>

    @if (session('status'))
        <p role="status">{{ session('status') }}</p>
    @endif
    @if ($errors->any())
        <section role="alert" aria-label="Validation errors">
            <strong>{{ app()->getLocale() === 'ar' ? 'تعذر حفظ الخطة.' : 'Plan could not be saved.' }}</strong>
            <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </section>
    @endif

    <section data-canonical-operation="AIMW-BILL-5AF09ADABA" aria-label="{{ app()->getLocale() === 'ar' ? 'إنشاء خطة' : 'Create plan' }}">
        <h2>{{ app()->getLocale() === 'ar' ? 'إنشاء خطة اشتراك' : 'Create subscription plan' }}</h2>
        <form method="post" action="{{ route('tenant.admin.subscription-plans.save', ['tenant' => $tenant->slug]) }}">
            @csrf
            <label>Plan code <input name="code" required maxlength="64" pattern="[a-z0-9][a-z0-9._-]{0,63}" value="{{ old('code') }}"></label>
            <label>English name <input name="name_en" required maxlength="160" value="{{ old('name_en') }}"></label>
            <label>Arabic name <input name="name_ar" required maxlength="160" dir="rtl" value="{{ old('name_ar') }}"></label>
            <label>English description <textarea name="description_en" maxlength="1000">{{ old('description_en') }}</textarea></label>
            <label>Arabic description <textarea name="description_ar" maxlength="1000" dir="rtl">{{ old('description_ar') }}</textarea></label>
            <label>Billing interval <select name="billing_interval"><option value="Monthly">Monthly</option><option value="Yearly">Yearly</option></select></label>
            <label>Price <input name="price" type="number" min="0" max="1000000" step="0.01" required value="{{ old('price', '0.00') }}"></label>
            <label>Currency <input name="currency" maxlength="3" required value="{{ old('currency', 'USD') }}"></label>
            <label>Trial days <input name="trial_days" type="number" min="0" max="365" required value="{{ old('trial_days', 0) }}"></label>
            <label>Grace days <input name="grace_period_days" type="number" min="0" max="90" required value="{{ old('grace_period_days', 7) }}"></label>
            <label>Sort order <input name="sort_order" type="number" min="0" max="100000" required value="{{ old('sort_order', 100) }}"></label>
            <input type="hidden" name="is_enabled" value="0">
            <label>Customer visible <input name="is_enabled" type="checkbox" value="1" {{ old('is_enabled', '1') === '1' ? 'checked' : '' }}></label>
            <label>PayPal Product ID <input name="gateway_product_id" maxlength="200" autocomplete="off" value=""></label>
            <label>PayPal Plan ID <input name="gateway_plan_id" maxlength="200" autocomplete="off" value=""></label>
            <button type="submit">{{ app()->getLocale() === 'ar' ? 'حفظ الخطة' : 'Save plan' }}</button>
        </form>
    </section>

    <section aria-label="{{ app()->getLocale() === 'ar' ? 'كتالوج الخطط' : 'Plan catalog' }}">
        @forelse ($plans as $plan)
            @php
                $nameEn = $plan->localized_name['en'] ?? $plan->name;
                $nameAr = $plan->localized_name['ar'] ?? $plan->name;
                $descriptionEn = $plan->localized_description['en'] ?? $plan->description ?? '';
                $descriptionAr = $plan->localized_description['ar'] ?? '';
            @endphp
            <article data-plan-code="{{ $plan->code }}">
                <h2>{{ $plan->localized_name[app()->getLocale()] ?? $plan->name }}</h2>
                <p><code>{{ $plan->code }}</code></p>
                @if (filled($plan->localized_description[app()->getLocale()] ?? $plan->description))
                    <p>{{ $plan->localized_description[app()->getLocale()] ?? $plan->description }}</p>
                @endif
                <dl>
                    <dt>{{ app()->getLocale() === 'ar' ? 'السعر' : 'Price' }}</dt>
                    <dd>{{ $plan->price_minor === null ? '—' : number_format($plan->price_minor / 100, 2) }} {{ $plan->currency }}</dd>
                    <dt>{{ app()->getLocale() === 'ar' ? 'دورة الفوترة' : 'Billing interval' }}</dt><dd>{{ $plan->billing_interval }}</dd>
                    <dt>{{ app()->getLocale() === 'ar' ? 'الحالة' : 'Status' }}</dt><dd>{{ $plan->enabled && $plan->retired_at === null ? (app()->getLocale() === 'ar' ? 'مفعلة' : 'Enabled') : (app()->getLocale() === 'ar' ? 'معطلة' : 'Disabled') }}</dd>
                </dl>

                <form method="post" action="{{ route('tenant.admin.subscription-plans.update', ['tenant' => $tenant->slug, 'plan' => $plan->id]) }}" data-canonical-operation="AIMW-BILL-5AF09ADABA">
                    @csrf
                    @method('PATCH')
                    <label>English name <input name="name_en" required maxlength="160" value="{{ $nameEn }}"></label>
                    <label>Arabic name <input name="name_ar" required maxlength="160" dir="rtl" value="{{ $nameAr }}"></label>
                    <label>English description <textarea name="description_en" maxlength="1000">{{ $descriptionEn }}</textarea></label>
                    <label>Arabic description <textarea name="description_ar" maxlength="1000" dir="rtl">{{ $descriptionAr }}</textarea></label>
                    <label>Billing interval <select name="billing_interval"><option value="Monthly" {{ $plan->billing_interval === 'month' ? 'selected' : '' }}>Monthly</option><option value="Yearly" {{ $plan->billing_interval === 'year' ? 'selected' : '' }}>Yearly</option></select></label>
                    <label>Price <input name="price" type="number" min="0" max="1000000" step="0.01" required value="{{ number_format(($plan->price_minor ?? 0) / 100, 2, '.', '') }}"></label>
                    <label>Currency <input name="currency" maxlength="3" required value="{{ $plan->currency }}"></label>
                    <label>Trial days <input name="trial_days" type="number" min="0" max="365" required value="{{ $plan->trial_period_days }}"></label>
                    <label>Grace days <input name="grace_period_days" type="number" min="0" max="90" required value="{{ $plan->grace_period_days }}"></label>
                    <label>Sort order <input name="sort_order" type="number" min="0" max="100000" required value="{{ $plan->display_order }}"></label>
                    <input type="hidden" name="is_enabled" value="0">
                    <label>Customer visible <input name="is_enabled" type="checkbox" value="1" {{ $plan->enabled ? 'checked' : '' }}></label>
                    <p>Existing PayPal identifiers are never rendered. Enter a replacement value or explicitly clear a binding.</p>
                    <label>Replace PayPal Product ID <input name="gateway_product_id" maxlength="200" autocomplete="off" value=""></label>
                    <label><input name="clear_gateway_product_id" type="checkbox" value="1"> Clear PayPal Product ID</label>
                    <label>Replace PayPal Plan ID <input name="gateway_plan_id" maxlength="200" autocomplete="off" value=""></label>
                    <label><input name="clear_gateway_plan_id" type="checkbox" value="1"> Clear PayPal Plan ID</label>
                    <button type="submit">{{ app()->getLocale() === 'ar' ? 'حفظ الخطة' : 'Save plan' }}</button>
                </form>

                <form method="post" action="{{ route('tenant.admin.subscription-plans.enabled', ['tenant' => $tenant->slug, 'plan' => $plan->id]) }}" data-canonical-operation="AIMW-BILL-812D1C53B6">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="expected_enabled" value="{{ $plan->enabled ? '1' : '0' }}">
                    <input type="hidden" name="enabled" value="{{ $plan->enabled ? '0' : '1' }}">
                    <button type="submit">{{ $plan->enabled ? (app()->getLocale() === 'ar' ? 'تعطيل' : 'Disable') : (app()->getLocale() === 'ar' ? 'تفعيل' : 'Enable') }}</button>
                </form>
            </article>
        @empty
            <p role="status">{{ app()->getLocale() === 'ar' ? 'لا توجد خطط اشتراك محفوظة.' : 'No persisted subscription plans.' }}</p>
        @endforelse
    </section>
</main>
</body>
</html>
