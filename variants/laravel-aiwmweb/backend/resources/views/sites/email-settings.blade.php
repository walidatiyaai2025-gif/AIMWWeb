@php
    $isArabic = app()->getLocale() === 'ar';
    $copy = $isArabic ? [
        'title' => 'إعدادات البريد للموقع',
        'subtitle' => 'أضف مستلمين لإشعارات هذا الموقع. يتم تطبيق حد الخطة عند الحفظ.',
        'choose' => 'اختر موقعاً',
        'open' => 'فتح',
        'email' => 'عنوان البريد الإلكتروني',
        'name' => 'اسم العرض (اختياري)',
        'add' => 'إضافة المستلم',
        'profile' => 'إعداد الإرسال',
        'use_account_profile' => 'استخدام إعداد بريد الحساب',
        'smtp_host' => 'SMTP Host',
        'smtp_port' => 'SMTP Port',
        'username' => 'اسم المستخدم',
        'password' => 'كلمة المرور',
        'saved_password' => 'يوجد سر محفوظ ومشفر. اترك الحقل فارغاً للاحتفاظ به.',
        'no_password' => 'لا يوجد سر محفوظ.',
        'from_address' => 'بريد المرسل',
        'from_name' => 'اسم المرسل',
        'reply_to' => 'الرد إلى',
        'ssl' => 'TLS / SSL',
        'enabled_mail' => 'تفعيل إرسال البريد لهذا الموقع',
        'save_profile' => 'حفظ إعداد البريد',
        'save_note' => 'الحفظ لا يعني أن اتصال SMTP تم اختباره.',
        'recipients' => 'المستلمون الحاليون',
        'empty' => 'لا يوجد مستلمون لهذا الموقع.',
        'status' => 'الحالة',
        'enabled' => 'مفعّل',
        'back' => 'العودة إلى تفاصيل الموقع',
    ] : [
        'title' => 'Site email settings',
        'subtitle' => 'Add recipients for this site’s notifications. The account plan recipient limit is enforced when you save.',
        'choose' => 'Choose a site',
        'open' => 'Open',
        'email' => 'Email address',
        'name' => 'Display name (optional)',
        'add' => 'Add recipient',
        'profile' => 'Outbound mail profile',
        'use_account_profile' => 'Use dashboard/account mail profile',
        'smtp_host' => 'SMTP Host',
        'smtp_port' => 'SMTP Port',
        'username' => 'Username',
        'password' => 'Password',
        'saved_password' => 'An encrypted password is saved. Leave this blank to keep it.',
        'no_password' => 'No password is currently stored.',
        'from_address' => 'From address',
        'from_name' => 'From name',
        'reply_to' => 'Reply-to address',
        'ssl' => 'TLS / SSL',
        'enabled_mail' => 'Enable outbound email for this site',
        'save_profile' => 'Save mail profile',
        'save_note' => 'Saving does not claim SMTP connectivity has been verified.',
        'recipients' => 'Current recipients',
        'empty' => 'No recipients are configured for this site.',
        'status' => 'Status',
        'enabled' => 'Enabled',
        'back' => 'Back to site details',
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ $isArabic ? 'ar' : 'en' }}" dir="{{ $isArabic ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $copy['title'] }}</title>
    <style>
        :root { color-scheme: light dark; font-family: Inter, system-ui, sans-serif; }
        body { margin: 0; background: Canvas; color: CanvasText; }
        main { width: min(960px, calc(100% - 32px)); margin: 40px auto; }
        header, section { border: 1px solid color-mix(in srgb, CanvasText 18%, transparent); border-radius: 14px; padding: 20px; margin-bottom: 18px; }
        h1, h2 { margin-top: 0; }
        form { display: grid; gap: 12px; }
        label { display: grid; gap: 6px; font-weight: 600; }
        input, select, button { font: inherit; padding: 10px 12px; border-radius: 8px; border: 1px solid color-mix(in srgb, CanvasText 28%, transparent); }
        button { cursor: pointer; font-weight: 700; }
        .grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
        .notice { padding: 12px; border-radius: 8px; margin-bottom: 14px; }
        .errors { border: 1px solid currentColor; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px; text-align: start; border-bottom: 1px solid color-mix(in srgb, CanvasText 14%, transparent); }
        @media (max-width: 640px) { .grid { grid-template-columns: 1fr; } main { margin-top: 20px; } }
    </style>
</head>
<body>
<main>
    <header>
        <p>Email / {{ $selectedSite?->name ?? $copy['choose'] }}</p>
        <h1>{{ $copy['title'] }}</h1>
        <p>{{ $copy['subtitle'] }}</p>
    </header>

    <section aria-labelledby="site-selection-heading">
        <h2 id="site-selection-heading">{{ $copy['choose'] }}</h2>
        <form method="get" action="{{ route('canonical.workspace.site-email-settings.module', ['tenant' => $tenant]) }}">
            <label>
                {{ $copy['choose'] }}
                <select name="site" required>
                    <option value="">—</option>
                    @foreach ($sites as $siteOption)
                        <option value="{{ $siteOption->getKey() }}" @selected($selectedSite?->getKey() === $siteOption->getKey())>{{ $siteOption->name }}</option>
                    @endforeach
                </select>
            </label>
            <button type="submit">{{ $copy['open'] }}</button>
        </form>
    </section>

    @if ($selectedSite)
        <section aria-labelledby="mail-profile-heading" data-canonical-operation="AIMW-BILL-609F15BFCA">
            <h2 id="mail-profile-heading">{{ $copy['profile'] }}</h2>
            @php($profile = $mailProfile ?? [])
            <form
                method="post"
                action="{{ route('canonical.workspace.site-email-settings.mail-profile-save', ['tenant' => $tenant, 'site' => $selectedSite->getKey()]) }}"
                data-canonical-operation="AIMW-BILL-609F15BFCA"
                data-source-handler="SaveMailProfileAsync"
                onsubmit="this.querySelector('button[type=submit]').disabled = true"
            >
                @csrf
                <input type="hidden" name="use_account_profile" value="0">
                <label>
                    <span>
                        <input type="checkbox" name="use_account_profile" value="1" @checked(old('use_account_profile', (bool)($profile['use_account_profile'] ?? true)))>
                        {{ $copy['use_account_profile'] }}
                    </span>
                </label>
                <div class="grid">
                    <label>{{ $copy['smtp_host'] }}<input type="text" name="host" value="{{ old('host', $profile['host'] ?? '') }}" maxlength="255"></label>
                    <label>{{ $copy['smtp_port'] }}<input type="number" name="port" min="1" max="65535" value="{{ old('port', $profile['port'] ?? 587) }}"></label>
                    <label>{{ $copy['username'] }}<input type="text" name="username" value="{{ old('username', $profile['username'] ?? '') }}" maxlength="320" autocomplete="username"></label>
                    <label>
                        {{ $copy['password'] }}
                        <input type="password" name="password" value="" maxlength="2048" autocomplete="new-password">
                        <small>{{ ($profile['has_saved_password'] ?? false) ? $copy['saved_password'] : $copy['no_password'] }}</small>
                    </label>
                    <label>{{ $copy['from_address'] }}<input type="email" name="from_address" value="{{ old('from_address', $profile['from_address'] ?? '') }}" maxlength="320"></label>
                    <label>{{ $copy['from_name'] }}<input type="text" name="from_name" value="{{ old('from_name', $profile['from_name'] ?? '') }}" maxlength="160"></label>
                    <label>{{ $copy['reply_to'] }}<input type="email" name="reply_to" value="{{ old('reply_to', $profile['reply_to'] ?? '') }}" maxlength="320"></label>
                </div>
                <input type="hidden" name="enable_ssl" value="0">
                <label><span><input type="checkbox" name="enable_ssl" value="1" @checked(old('enable_ssl', (bool)($profile['enable_ssl'] ?? true)))> {{ $copy['ssl'] }}</span></label>
                <input type="hidden" name="enabled" value="0">
                <label><span><input type="checkbox" name="enabled" value="1" @checked(old('enabled', (bool)($profile['enabled'] ?? false)))> {{ $copy['enabled_mail'] }}</span></label>
                <button type="submit" data-canonical-operation="AIMW-BILL-609F15BFCA">{{ $copy['save_profile'] }}</button>
                <p>{{ $copy['save_note'] }}</p>
            </form>
        </section>

        <section aria-labelledby="add-recipient-heading">
            <h2 id="add-recipient-heading">{{ $copy['add'] }}</h2>

            @if (session('success'))
                <p class="notice" role="status">{{ session('success') }}</p>
            @endif
            @if ($errors->any())
                <div class="notice errors" role="alert">
                    <ul>
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form
                method="post"
                action="{{ route('canonical.workspace.site-email-settings.recipient-add', ['tenant' => $tenant, 'site' => $selectedSite->getKey()]) }}"
                data-canonical-operation="AIMW-BILL-07134347F9"
                data-source-handler="AddAsync"
                onsubmit="this.querySelector('button[type=submit]').disabled = true"
            >
                @csrf
                <div class="grid">
                    <label>
                        {{ $copy['email'] }}
                        <input type="email" name="email_address" value="{{ old('email_address') }}" maxlength="320" autocomplete="email" required>
                    </label>
                    <label>
                        {{ $copy['name'] }}
                        <input type="text" name="display_name" value="{{ old('display_name') }}" maxlength="120" autocomplete="name">
                    </label>
                </div>
                <button type="submit" data-canonical-operation="AIMW-BILL-07134347F9">{{ $copy['add'] }}</button>
            </form>
        </section>

        <section aria-labelledby="recipient-list-heading">
            <h2 id="recipient-list-heading">{{ $copy['recipients'] }}</h2>
            @if ($recipients->isEmpty())
                <p>{{ $copy['empty'] }}</p>
            @else
                <div style="overflow-x:auto">
                    <table>
                        <thead><tr><th>{{ $copy['email'] }}</th><th>{{ $copy['name'] }}</th><th>{{ $copy['status'] }}</th></tr></thead>
                        <tbody>
                        @foreach ($recipients as $recipient)
                            <tr>
                                <td>{{ $recipient->email_address }}</td>
                                <td>{{ $recipient->display_name ?: '—' }}</td>
                                <td>{{ $recipient->is_enabled ? $copy['enabled'] : '—' }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            <p><a href="/tenants/{{ rawurlencode($tenant) }}/sites/{{ $selectedSite->getKey() }}">{{ $copy['back'] }}</a></p>
        </section>
    @endif
</main>
</body>
</html>
