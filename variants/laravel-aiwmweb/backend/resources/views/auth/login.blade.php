@php
    // Public authentication stays tenant-neutral; never render workspace links before login.
    $isArabic = in_array(request()->query('lang'), ['en', 'ar'], true)
        ? request()->query('lang') === 'ar'
        : session('public_welcome_locale') === 'ar';
    $tr = fn (string $english, string $arabic) => $isArabic ? $arabic : $english;
    $switchLocale = $isArabic ? 'en' : 'ar';
    $localeSwitchUrl = '/login?lang='.$switchLocale.'&returnUrl='.rawurlencode($returnUrl);
@endphp
<!doctype html>
<html lang="{{ $isArabic ? 'ar' : 'en' }}" dir="{{ $isArabic ? 'rtl' : 'ltr' }}" data-mode="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $tr('AI WordPress Manager - Login', 'مدير ووردبريس الذكي - تسجيل الدخول') }}</title>
    <meta name="robots" content="noindex,nofollow">
    @unless (app()->runningUnitTests())
        @vite(['resources/css/app.css'])
    @endunless
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 16px; box-sizing: border-box; font-family: Inter, Tajawal, Segoe UI, sans-serif; background: #0b0f17; color: #f9fafb; }
        .card { width: min(430px, 100%); box-sizing: border-box; padding: clamp(18px, 5vw, 32px); border: 1px solid #243244; border-radius: 20px; background: #111827; }
        .brand { margin-bottom: 6px; font-size: 18.75px; font-weight: 750; }
        .sub { margin-bottom: 22px; color: #9ca3af; }
        .error { margin-bottom: 16px; padding: 11px 12px; border: 1px solid #ef444455; border-radius: 10px; background: #7f1d1d33; color: #fecaca; font-size: 10.15625px; }
        .success { margin-bottom: 16px; padding: 11px 12px; border: 1px solid #10b98155; border-radius: 10px; background: #064e3b55; color: #a7f3d0; font-size: 10.15625px; }
        label { display: block; margin: 14px 0 6px; }
        input { width: 100%; box-sizing: border-box; padding: 12px; border: 1px solid #374151; border-radius: 9px; background: #0b0f17; color: #fff; }
        button { width: 100%; min-height: 44px; margin-top: 20px; padding: 12px; border: 0; border-radius: 9px; background: #10b981; color: #062a1f; font-weight: 800; cursor: pointer; }
        .back { display: block; margin-top: 18px; text-align: center; color: #9ca3af; text-decoration: none; font-size: 10.15625px; }
        .locale-switch { display: inline-flex; align-items: center; justify-content: center; min-height: 44px; margin-bottom: 12px; color: #6ee7b7; text-decoration: underline; text-underline-offset: 3px; }
        :focus-visible { outline: 3px solid #e0bf73; outline-offset: 3px; }
        .card input[name="email"] { direction: ltr; text-align: start; }
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
<form class="card" method="post" action="{{ route('login.store') }}">
    @csrf
    <a class="locale-switch" href="{{ $localeSwitchUrl }}" lang="{{ $switchLocale }}" hreflang="{{ $switchLocale }}">{{ $isArabic ? 'English' : 'العربية' }}</a>
    <div class="brand">AI WordPress Manager</div>
    <div class="sub">{{ $tr('Sign in to continue to your workspace', 'سجّل الدخول للمتابعة إلى مساحة عملك') }}</div>

    @if (request()->boolean('registered'))
        <div class="success" role="status">{{ $tr('Account created. Sign in to continue.', 'تم إنشاء الحساب. سجّل الدخول للمتابعة.') }}</div>
    @endif

    @if ($errors->any() || $error !== '')
        <div class="error" role="alert">{{ $errors->first() ?: $error }}</div>
    @endif

    <input type="hidden" name="returnUrl" value="{{ old('returnUrl', $returnUrl) }}">

    <label for="login-identifier">{{ $tr('Username or email', 'اسم المستخدم أو البريد الإلكتروني') }}</label>
    <input id="login-identifier" type="text" name="email" value="{{ old('email') }}" autocomplete="username" required autofocus>

    <label for="login-password">{{ $tr('Password', 'كلمة المرور') }}</label>
    <input id="login-password" type="password" name="password" autocomplete="current-password" required>

    <button type="submit">{{ $tr('Sign in', 'تسجيل الدخول') }}</button>
    <a class="back" href="/">{{ $tr('Back to product overview', 'العودة إلى نظرة عامة على المنتج') }}</a>
</form>
</body>
</html>
