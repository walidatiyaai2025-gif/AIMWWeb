<!doctype html>
<html lang="en" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AI WordPress Manager - Login</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; font-family: Segoe UI, Arial, sans-serif; background: #0b0f17; color: #f9fafb; }
        .card { width: min(410px, 90vw); box-sizing: border-box; padding: 32px; border: 1px solid #243244; border-radius: 20px; background: #111827; }
        .brand { margin-bottom: 6px; font-size: 24px; font-weight: 750; }
        .sub { margin-bottom: 22px; color: #9ca3af; }
        .error { margin-bottom: 16px; padding: 11px 12px; border: 1px solid #ef444455; border-radius: 10px; background: #7f1d1d33; color: #fecaca; font-size: 13px; }
        label { display: block; margin: 14px 0 6px; }
        input { width: 100%; box-sizing: border-box; padding: 12px; border: 1px solid #374151; border-radius: 9px; background: #0b0f17; color: #fff; }
        button { width: 100%; margin-top: 20px; padding: 12px; border: 0; border-radius: 9px; background: #10b981; color: #062a1f; font-weight: 800; cursor: pointer; }
        .back { display: block; margin-top: 18px; text-align: center; color: #9ca3af; text-decoration: none; font-size: 13px; }
    </style>
</head>
<body>
<form class="card" method="post" action="/api/login">
    @csrf
    <div class="brand">AI WordPress Manager</div>
    <div class="sub">Sign in to continue to your workspace</div>

    @if ($error !== '')
        <div class="error" role="alert">{{ $error }}</div>
    @endif

    <input type="hidden" name="returnUrl" value="{{ $returnUrl }}">

    <label for="login-email">Email</label>
    <input id="login-email" type="email" name="email" autocomplete="username" required autofocus>

    <label for="login-password">Password</label>
    <input id="login-password" type="password" name="password" autocomplete="current-password" required>

    <button type="submit">Sign in</button>
    <a class="back" href="/">Back to product overview</a>
</form>
</body>
</html>
