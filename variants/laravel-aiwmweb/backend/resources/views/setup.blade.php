<!doctype html>
<html lang="en" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Laravel AIWMWeb - Owner Activation</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #0b0f17; color: #f9fafb; font-family: Arial, sans-serif; }
        main { width: min(680px, 90vw); padding: 32px; border: 1px solid #243244; border-radius: 18px; background: #111827; box-shadow: 0 24px 70px #0008; }
        h1 { margin-top: 0; } p, li, label { color: #d1d5db; line-height: 1.6; } code { color: #a7f3d0; }
        .status, .error { padding: 14px; border-radius: 10px; background: #1f2937; margin: 20px 0; }
        .error { border: 1px solid #7f1d1d; color: #fecaca; }
        form { display: grid; gap: 14px; margin-top: 24px; }
        label { display: grid; gap: 6px; }
        input { box-sizing: border-box; width: 100%; padding: 11px 12px; border-radius: 9px; border: 1px solid #374151; background: #0b0f17; color: #fff; }
        button { padding: 12px 16px; border: 0; border-radius: 9px; background: #10b981; color: #062a1f; font-weight: 800; cursor: pointer; }
        .hint { font-size: 10.15625px; color: #9ca3af; }
    </style>
    <!-- Google Fonts: Inter (Latin) and Tajawal (Arabic); typography 25% larger than 62.5% baseline. -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&amp;family=Tajawal:wght@400;500;700;800;900&amp;display=swap">
    <style data-aiwm-global-typography>
        :root { font-size: 78.125%; --aiwm-google-font: 'Inter', 'Tajawal', sans-serif; --font-sans: var(--aiwm-google-font); }
        html:lang(ar) { --aiwm-google-font: 'Tajawal', 'Inter', sans-serif; }
        body, button, input, textarea, select, option { font-family: var(--aiwm-google-font) !important; }
    </style>
</head>
<body>
<main>
    <h1>Hosting owner activation required</h1>
    <p>Only the hosting account owner may initialize the first platform administrator. Use cPanel File Manager to read the one-time activation secret stored in the private Laravel application folder; never share it with a visitor.</p>

    <div class="status">
        <strong>Private activation file:</strong> <code>app/storage/app/aiwm-install/owner-activation.secret</code><br>
        <strong>Configured driver:</strong> {{ $status['driver'] }}<br>
        <strong>Database reachable:</strong> {{ $status['database_reachable'] ? 'yes' : 'no' }}<br>
        <strong>Migrations ready:</strong> {{ $status['migrations_ready'] ? 'yes' : 'no' }}<br>
        <strong>Identity ready:</strong> {{ ($status['identity_ready'] ?? false) ? 'yes' : 'no' }}
    </div>

    @if (!empty($error))
        <div class="error">{{ $error }}</div>
    @endif

    @if ($errors->any())
        <div class="error">Setup details are invalid. Correct the highlighted fields and try again.</div>
    @endif

    <p>The database connection remains deployment-owned. Database credentials alone do not authorize a new platform owner.</p>

    <form method="post" action="{{ route('canonical.api.setup.submit') }}" autocomplete="off">
        @csrf
        <label>One-time hosting owner activation secret
            <input type="password" name="owner_activation_secret" minlength="64" maxlength="64" required autocomplete="off">
        </label>
        <label>Workspace name
            <input name="tenant_name" value="{{ old('tenant_name', 'Primary Workspace') }}" maxlength="120" required>
        </label>
        <label>Administrator name
            <input name="admin_name" value="{{ old('admin_name') }}" maxlength="120" required autocomplete="name">
        </label>
        <label>Administrator email
            <input type="email" name="admin_email" value="{{ old('admin_email') }}" maxlength="255" required autocomplete="email">
        </label>
        <label>Administrator password
            <input type="password" name="admin_password" minlength="12" maxlength="255" required autocomplete="new-password">
        </label>
        <label>Confirm administrator password
            <input type="password" name="admin_password_confirmation" minlength="12" maxlength="255" required autocomplete="new-password">
        </label>
        <button type="submit">Activate & initialize Laravel AIWMWeb</button>
    </form>

    <p class="hint">The owner challenge is generated uniquely on the server, is never delivered in this page, and is consumed after successful installation. HTTPS is required outside local development.</p>
</main>
</body>
</html>
