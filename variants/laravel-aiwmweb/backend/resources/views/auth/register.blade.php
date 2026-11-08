@php
    // Public registration mirrors the tenant-neutral login locale without changing identity or trial logic.
    $isArabic = in_array(request()->query('lang'), ['ar', 'en'], true)
        ? request()->query('lang') === 'ar'
        : session('public_welcome_locale') === 'ar';
    $tr = fn (string $en, string $ar) => $isArabic ? $ar : $en;
    $switchLocale = $isArabic ? 'en' : 'ar';
@endphp
<!doctype html>
<html lang="{{ $isArabic ? 'ar' : 'en' }}" dir="{{ $isArabic ? 'rtl' : 'ltr' }}" data-mode="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $tr('Create account - AI WordPress Manager', 'إنشاء حساب - مدير ووردبريس الذكي') }}</title>
    <meta name="robots" content="noindex,nofollow">
    @unless (app()->runningUnitTests())
        @vite(['resources/css/app.css'])
    @endunless
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 16px; box-sizing: border-box; font-family: Inter, Tajawal, Segoe UI, sans-serif; background: #0b0f17; color: #f9fafb; }
        .card { width: min(470px, 100%); box-sizing: border-box; padding: clamp(18px, 5vw, 32px); border: 1px solid #243244; border-radius: 20px; background: #111827; }
        .brand { margin-bottom: 8px; font-size: 18.75px; font-weight: 750; }
        .trial { display: inline-block; margin-bottom: 14px; padding: 5px 9px; border-radius: 999px; background: #064e3b; color: #a7f3d0; font-size: 8.59375px; font-weight: 800; letter-spacing: .04em; }
        h1 { margin: 0 0 8px; font-size: 20.3125px; }
        .sub { margin: 0 0 20px; color: #9ca3af; line-height: 1.45; }
        .alert { margin-bottom: 16px; padding: 11px 12px; border-radius: 10px; font-size: 10.15625px; }
        .error { border: 1px solid #ef444455; background: #7f1d1d33; color: #fecaca; }
        .success { border: 1px solid #10b98155; background: #064e3b55; color: #a7f3d0; }
        label { display: block; margin: 14px 0 6px; }
        input { width: 100%; box-sizing: border-box; padding: 12px; border: 1px solid #374151; border-radius: 9px; background: #0b0f17; color: #fff; }
        small { display: block; margin-top: 6px; color: #9ca3af; }
        button { width: 100%; min-height: 44px; margin-top: 20px; padding: 12px; border: 0; border-radius: 9px; background: #10b981; color: #062a1f; font-weight: 800; cursor: pointer; }
        .limits { display: grid; gap: 6px; margin: 20px 0; color: #d1d5db; font-size: 10.15625px; }
        .footer { text-align: center; color: #9ca3af; font-size: 10.15625px; }
        a { color: #6ee7b7; }
        .locale-switch { display: inline-flex; align-items: center; justify-content: center; min-height: 44px; margin-bottom: 12px; text-underline-offset: 3px; }
        :focus-visible { outline: 3px solid #e0bf73; outline-offset: 3px; }
        .card input[name="username"] { direction: ltr; text-align: start; }
        @media (max-width: 480px) { .card { padding: 18px; } .limits { line-height: 1.6; } }
    </style>
    <!-- Google Fonts: Inter (Latin) and Tajawal (Arabic); typography 25% larger than the 62.5% baseline. -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&amp;family=Tajawal:wght@400;500;700;800;900&amp;display=swap">
    <style data-aiwm-global-typography>
        :root { font-size: 78.125%; --aiwm-google-font: 'Inter', 'Tajawal', sans-serif; --font-sans: var(--aiwm-google-font); }
        html:lang(ar) { --aiwm-google-font: 'Tajawal', 'Inter', sans-serif; }
        body, button, input, textarea, select, option { font-family: var(--aiwm-google-font) !important; }
    </style>
</head>
<body data-tenant-neutral="true" data-auth-shell="aiwmweb">
<section class="card">
    <a class="locale-switch" href="{{ '/register?lang='.$switchLocale }}" lang="{{ $switchLocale }}" hreflang="{{ $switchLocale }}">{{ $isArabic ? 'English' : 'العربية' }}</a>
    <div class="brand">AI WordPress Manager</div>
    <div class="trial">{{ $tr('14-DAY FREE TRIAL · NO CARD REQUIRED', 'تجربة مجانية لمدة 14 يومًا · دون بطاقة بنكية') }}</div>
    <h1>{{ $tr('Create your account', 'أنشئ حسابك') }}</h1>
    <p class="sub">{{ $tr('Start with one WordPress site, limited AI usage and core automation. Upgrade only when you need more capacity.', 'ابدأ بموقع ووردبريس واحد واستخدام محدود للذكاء الاصطناعي والأتمتة الأساسية. يمكنك ترقية الخطة عند الحاجة.') }}</p>

    @if ($error !== '')
        <div class="alert error" role="alert">{{ $error }}</div>
    @endif

    @if ($accountCreated)
        <div class="alert success">
            {{ $tr('Account', 'تم إنشاء الحساب') }} <strong>{{ $registeredUserName }}</strong> {{ $tr('was created. You can sign in while an administrator resolves the trial assignment.', 'بنجاح. يمكنك تسجيل الدخول بينما يعالج المسؤول تعيين الاشتراك التجريبي.') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="alert error" role="alert">
            {{ $errors->first() }}
        </div>
    @endif

    <form method="post" action="/register" data-canonical-operation="{{ \App\Http\Controllers\RegisterController::OPERATION_ID }}">
        @csrf

        <label for="register-user">{{ $tr('Username', 'اسم المستخدم') }}</label>
        <input id="register-user" name="username" value="{{ old('username') }}" autocomplete="username" required maxlength="64">

        <label for="register-password">{{ $tr('Password', 'كلمة المرور') }}</label>
        <input id="register-password" type="password" name="password" autocomplete="new-password" required>
        <small>{{ $tr('At least 8 characters with uppercase, lowercase, and a number.', 'ثمانية أحرف على الأقل، تتضمن حرفًا كبيرًا وحرفًا صغيرًا ورقمًا.') }}</small>

        <label for="register-confirm">{{ $tr('Confirm password', 'تأكيد كلمة المرور') }}</label>
        <input id="register-confirm" type="password" name="password_confirmation" autocomplete="new-password" required>

        <button type="submit" data-canonical-operation="{{ \App\Http\Controllers\RegisterController::REGISTER_SUBMIT_VISIBLE_OPERATION_ID }}">{{ $tr('Start free trial', 'ابدأ التجربة المجانية') }}</button>
    </form>

    <div class="limits" aria-label="{{ $tr('Free trial limits', 'حدود التجربة المجانية') }}">
        <span>{{ $tr('✓ 1 WordPress site', '✓ موقع ووردبريس واحد') }}</span>
        <span>{{ $tr('✓ 50 AI requests / month', '✓ 50 طلب ذكاء اصطناعي شهريًا') }}</span>
        <span>{{ $tr('✓ 1 automation schedule', '✓ جدول أتمتة واحد') }}</span>
        <span>{{ $tr('✓ 3-day backup retention', '✓ الاحتفاظ بالنسخ الاحتياطية لمدة 3 أيام') }}</span>
    </div>

    <div class="footer">{{ $tr('Already registered?', 'لديك حساب بالفعل؟') }} <a href="{{ $isArabic ? '/login?lang=ar' : '/login' }}" data-canonical-operation="{{ \App\Http\Controllers\RegisterController::LOGIN_NAVIGATION_OPERATION_ID }}">{{ $tr('Sign in', 'تسجيل الدخول') }}</a></div>
</section>
</body>
</html>
