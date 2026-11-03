<!doctype html>
<html lang="en" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AI WordPress Manager - Login</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; font-family: Segoe UI, Arial, sans-serif; background: #0b0f17; color: #f9fafb; }
        .card { width: min(410px, 90vw); box-sizing: border-box; padding: 32px; border: 1px solid #243244; border-radius: 20px; background: #111827; }
        .brand { margin-bottom: 6px; font-size: 12px; font-weight: 750; }
        .sub { margin-bottom: 22px; color: #9ca3af; }
        .error { margin-bottom: 16px; padding: 11px 12px; border: 1px solid #ef444455; border-radius: 10px; background: #7f1d1d33; color: #fecaca; font-size: 6.5px; }
        .success { margin-bottom: 16px; padding: 11px 12px; border: 1px solid #10b98155; border-radius: 10px; background: #064e3b55; color: #a7f3d0; font-size: 6.5px; }
        label { display: block; margin: 14px 0 6px; }
        input { width: 100%; box-sizing: border-box; padding: 12px; border: 1px solid #374151; border-radius: 9px; background: #0b0f17; color: #fff; }
        button { width: 100%; margin-top: 20px; padding: 12px; border: 0; border-radius: 9px; background: #10b981; color: #062a1f; font-weight: 800; cursor: pointer; }
        .back { display: block; margin-top: 18px; text-align: center; color: #9ca3af; text-decoration: none; font-size: 6.5px; }
    </style>
    <!-- Google Fonts: Inter (Latin) and Tajawal (Arabic); typography scale 50%. -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&amp;family=Tajawal:wght@400;500;700;800;900&amp;display=swap">
    <style data-aiwm-global-typography>
        :root { font-size: 50%; --aiwm-google-font: 'Inter', 'Tajawal', sans-serif; --font-sans: var(--aiwm-google-font); }
        html:lang(ar) { --aiwm-google-font: 'Tajawal', 'Inter', sans-serif; }
        body, button, input, textarea, select, option { font-family: var(--aiwm-google-font) !important; }
    </style>
</head>
<body>
<form class="card" method="post" action="{{ route('login.store') }}">
    @csrf
    <div class="brand">AI WordPress Manager</div>
    <div class="sub">Sign in to continue to your workspace</div>

    @if (request()->boolean('registered'))
        <div class="success" role="status">Account created. Sign in to continue.</div>
    @endif

    @if ($errors->any() || $error !== '')
        <div class="error" role="alert">{{ $errors->first() ?: $error }}</div>
    @endif

    <input type="hidden" name="returnUrl" value="{{ old('returnUrl', $returnUrl) }}">

    <label for="login-identifier">Username or email</label>
    <input id="login-identifier" type="text" name="email" value="{{ old('email') }}" autocomplete="username" required autofocus>

    <label for="login-password">Password</label>
    <input id="login-password" type="password" name="password" autocomplete="current-password" required>

    <button type="submit">Sign in</button>
    <a class="back" href="/">Back to product overview</a>
</form>
</body>
</html>
