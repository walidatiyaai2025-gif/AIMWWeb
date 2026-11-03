<!doctype html>
<html lang="en" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Laravel AIWMWeb - Database Setup</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #0b0f17; color: #f9fafb; font-family: Arial, sans-serif; }
        main { width: min(680px, 90vw); padding: 32px; border: 1px solid #243244; border-radius: 18px; background: #111827; box-shadow: 0 24px 70px #0008; }
        h1 { margin-top: 0; }
        p, li, label { color: #d1d5db; line-height: 1.6; }
        code { color: #a7f3d0; }
        .status, .error { padding: 14px; border-radius: 10px; background: #1f2937; margin: 20px 0; }
        .error { border: 1px solid #7f1d1d; color: #fecaca; }
        form { display: grid; gap: 14px; margin-top: 24px; }
        label { display: grid; gap: 6px; }
        input { box-sizing: border-box; width: 100%; padding: 11px 12px; border-radius: 9px; border: 1px solid #374151; background: #0b0f17; color: #fff; }
        button { padding: 12px 16px; border: 0; border-radius: 9px; background: #10b981; color: #062a1f; font-weight: 800; cursor: pointer; }
        .hint { font-size: 8.125px; color: #9ca3af; }
    </style>
    <!-- Google Fonts: Inter (Latin) and Tajawal (Arabic); typography 25% larger than previous baseline. -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&amp;family=Tajawal:wght@400;500;700;800;900&amp;display=swap">
    <style data-aiwm-global-typography>
        :root { font-size: 62.5%; --aiwm-google-font: 'Inter', 'Tajawal', sans-serif; --font-sans: var(--aiwm-google-font); }
        html:lang(ar) { --aiwm-google-font: 'Tajawal', 'Inter', sans-serif; }
        body, button, input, textarea, select, option { font-family: var(--aiwm-google-font) !important; }
    </style>
</head>
<body>
<main>
    <h1>Database setup required</h1>
    <p>Laravel AIWMWeb cannot enter the application workspace until its configured database, migrations, and first tenant owner are initialized.</p>

    <div class="status">
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

    <p>The database connection remains deployment-owned. This form never accepts or persists a database password or connection string.</p>

    <form method="post" action="{{ route('canonical.api.setup.submit') }}">
        @csrf
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
        <button type="submit">Initialize Laravel AIWMWeb</button>
    </form>

    <p class="hint">Initialization runs repository migrations and creates the first tenant owner only when no prior identity state exists. Existing installations are never claimed or overwritten.</p>
</main>
</body>
</html>
