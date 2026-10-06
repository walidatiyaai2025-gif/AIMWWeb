<!doctype html>
<html lang="en" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Create account - AI WordPress Manager</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; font-family: Segoe UI, Arial, sans-serif; background: #0b0f17; color: #f9fafb; }
        .card { width: min(470px, 92vw); box-sizing: border-box; padding: 32px; border: 1px solid #243244; border-radius: 20px; background: #111827; }
        .brand { margin-bottom: 8px; font-size: 24px; font-weight: 750; }
        .trial { display: inline-block; margin-bottom: 14px; padding: 5px 9px; border-radius: 999px; background: #064e3b; color: #a7f3d0; font-size: 11px; font-weight: 800; letter-spacing: .04em; }
        h1 { margin: 0 0 8px; font-size: 26px; }
        .sub { margin: 0 0 20px; color: #9ca3af; line-height: 1.45; }
        .alert { margin-bottom: 16px; padding: 11px 12px; border-radius: 10px; font-size: 13px; }
        .error { border: 1px solid #ef444455; background: #7f1d1d33; color: #fecaca; }
        .success { border: 1px solid #10b98155; background: #064e3b55; color: #a7f3d0; }
        label { display: block; margin: 14px 0 6px; }
        input { width: 100%; box-sizing: border-box; padding: 12px; border: 1px solid #374151; border-radius: 9px; background: #0b0f17; color: #fff; }
        small { display: block; margin-top: 6px; color: #9ca3af; }
        button { width: 100%; margin-top: 20px; padding: 12px; border: 0; border-radius: 9px; background: #10b981; color: #062a1f; font-weight: 800; cursor: pointer; }
        .limits { display: grid; gap: 6px; margin: 20px 0; color: #d1d5db; font-size: 13px; }
        .footer { text-align: center; color: #9ca3af; font-size: 13px; }
        a { color: #6ee7b7; }
    </style>
</head>
<body>
<section class="card">
    <div class="brand">AI WordPress Manager</div>
    <div class="trial">14-DAY FREE TRIAL · NO CARD REQUIRED</div>
    <h1>Create your account</h1>
    <p class="sub">Start with one WordPress site, limited AI usage and core automation. Upgrade only when you need more capacity.</p>

    @if ($error !== '')
        <div class="alert error" role="alert">{{ $error }}</div>
    @endif

    @if ($accountCreated)
        <div class="alert success">
            Account <strong>{{ $registeredUserName }}</strong> was created. You can sign in while an administrator resolves the trial assignment.
        </div>
    @endif

    @if ($errors->any())
        <div class="alert error" role="alert">
            {{ $errors->first() }}
        </div>
    @endif

    <form method="post" action="/register" data-canonical-operation="{{ \App\Http\Controllers\RegisterController::OPERATION_ID }}">
        @csrf

        <label for="register-user">Username</label>
        <input id="register-user" name="username" value="{{ old('username') }}" autocomplete="username" required maxlength="64">

        <label for="register-password">Password</label>
        <input id="register-password" type="password" name="password" autocomplete="new-password" required>
        <small>At least 8 characters with uppercase, lowercase, and a number.</small>

        <label for="register-confirm">Confirm password</label>
        <input id="register-confirm" type="password" name="password_confirmation" autocomplete="new-password" required>

        <button type="submit">Start free trial</button>
    </form>

    <div class="limits" aria-label="Free trial limits">
        <span>✓ 1 WordPress site</span>
        <span>✓ 50 AI requests / month</span>
        <span>✓ 1 automation schedule</span>
        <span>✓ 3-day backup retention</span>
    </div>

    <div class="footer">Already registered? <a href="/login">Sign in</a></div>
</section>
</body>
</html>
