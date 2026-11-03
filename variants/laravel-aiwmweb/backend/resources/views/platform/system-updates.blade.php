@php
    $ar = request()->getPreferredLanguage(['ar', 'en']) === 'ar';
    $t = fn (string $en, string $arabic) => $ar ? $arabic : $en;
@endphp
<!doctype html>
<html lang="{{ $ar ? 'ar' : 'en' }}" dir="{{ $ar ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ $t('System updates', 'تحديثات النظام') }} | AIWMWeb</title>
    <style>
        :root { color-scheme: light; --green:#13845f; --navy:#15293c; --muted:#607187; --edge:#dce5eb; --bg:#f3f6f8; }
        * { box-sizing:border-box; }
        body { margin:0; background:var(--bg); font:8px/1.6 system-ui,-apple-system,"Segoe UI",sans-serif; color:var(--navy); }
        main { max-width:920px; padding:32px 20px 65px; margin:auto; }
        .top { display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap; }
        .back { color:var(--green); font-weight:650; text-decoration:none; }
        h1 { margin:12px 0 4px; font-size:clamp(16.25px,2.5vw,23.75px); }
        h2 { font-size:13.125px; margin:0 0 12px; }
        .muted { color:var(--muted); }
        .panel { background:white; padding:24px; border:1px solid var(--edge); border-radius:17px; margin-top:18px; box-shadow:0 2px 7px #192f4510; }
        .grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:12px; }
        .stat { padding:16px; background:#f5f9f8; border-radius:12px; overflow-wrap:anywhere; }
        .stat span { display:block; font-size:8.125px; color:var(--muted); }
        .stat strong { font-size:11.875px; }
        .alert { border-radius:10px; padding:14px; margin-top:18px; background:#fff6e4; border:1px solid #e6c775; }
        .ok { background:#e9f8ef; border-color:#78bd9c; }
        .error { background:#fff1ee; border-color:#e5a49f; }
        button { border:0; border-radius:10px; padding:11px 20px; font:inherit; font-weight:650; color:white; background:var(--green); cursor:pointer; }
        button[disabled] { background:#96a5af; cursor:not-allowed; }
        form { margin:14px 0; }
        .install { background:#293f56; }
        label { display:block; margin:10px 0 4px; font-weight:550; }
        input { padding:11px; border:1px solid #bbcbd3; border-radius:9px; width:min(250px,100%); font:inherit; direction:ltr; }
        small { color:var(--muted); }
        code { overflow-wrap:anywhere; }
        .record { border-top:1px solid var(--edge); padding:12px 0; }
        :focus-visible { outline:3px solid #e1a433; outline-offset:3px; }
    </style>
    <!-- Google Fonts: Inter (Latin) and Tajawal (Arabic); typography 25% larger than previous baseline. -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&amp;family=Tajawal:wght@400;500;700;800;900&amp;display=swap">
    <style data-aiwm-global-typography>
        :root { font-size: 62.5%; --aiwm-google-font: 'Inter', 'Tajawal', sans-serif; --font-sans: var(--aiwm-google-font); }
        html:lang(ar) { --aiwm-google-font: 'Tajawal', 'Inter', sans-serif; }
        body, button, input, textarea, select, option { font-family: var(--aiwm-google-font) !important; }
        @media (min-width: 1140px) { main :is(.cards, .record-grid, .data-cards, .details-grid, .stats-grid, .summary-grid, .info-grid, .settings-grid), main dl:has(> div) { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; } }
        @media (min-width: 701px) and (max-width: 1139px) { main :is(.cards, .record-grid, .data-cards, .details-grid, .stats-grid, .summary-grid, .info-grid, .settings-grid), main dl:has(> div) { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; } }
        @media (max-width: 700px) { main :is(.cards, .record-grid, .data-cards, .details-grid, .stats-grid, .summary-grid, .info-grid, .settings-grid), main dl:has(> div) { display: grid; grid-template-columns: minmax(0, 1fr); gap: 10px; } }
    </style>
</head>
<body>
<main>
    <div class="top">
        <div><strong>AIWMWeb</strong> <span class="muted">/ {{ $t('Platform administration', 'إدارة المنصة') }}</span></div>
        <a class="back" href="/">{{ $t('Home', 'الرئيسية') }}</a>
    </div>
    <h1>{{ $t('System updates', 'تحديثات النظام') }}</h1>
    <p class="muted">{{ $t('Install only signed, approved Laravel releases. Production settings, uploads and the database are preserved.', 'تثبيت إصدارات Laravel المعتمدة والموقّعة فقط، مع الحفاظ على إعدادات الإنتاج والملفات وقاعدة البيانات.') }}</p>
    @if(session('status'))
        <div class="alert ok" role="status">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="alert error" role="alert">{{ $errors->first() }}</div>
    @endif

    <section class="panel" aria-labelledby="installed">
        <h2 id="installed">{{ $t('Installed version', 'الإصدار الحالي') }}</h2>
        <div class="grid">
            <div class="stat"><span>{{ $t('Version', 'الإصدار') }}</span><strong>{{ $state['version'] }}</strong></div>
            <div class="stat"><span>{{ $t('Source commit', 'كود الإصدار') }}</span><strong><code>{{ $state['sha'] }}</code></strong></div>
            <div class="stat"><span>{{ $t('Update channel', 'قناة التحديث') }}</span><strong>{{ $state['enabled'] && $state['configured'] ? $t('Signed stable', 'موقّع ومستقر') : $t('Not configured', 'غير مجهز') }}</strong></div>
        </div>
    </section>
    <section class="panel" aria-labelledby="available">
        <h2 id="available">{{ $t('Find and install an update', 'فحص وتثبيت تحديث') }}</h2>
        <p class="muted">{{ $t('Checks the latest published GitHub release, verifies its Ed25519 signature, and compares it with this installation.', 'يفحص أحدث إصدار منشور على GitHub، ويتحقق من توقيعه الرقمي ثم يقارنه بالإصدار المثبت.') }}</p>
        <form method="post" action="{{ route('platform.updates.check') }}">
            @csrf
            <button type="submit" @disabled(! $state['enabled'] || ! $state['configured'])>{{ $t('Check for updates', 'البحث عن تحديث') }}</button>
        </form>
        @if($candidate)
            <div class="stat">
                <span>{{ $t('Latest verified release', 'أحدث إصدار تم التحقق منه') }}</span>
                <strong>{{ $candidate['version'] }} — {{ substr($candidate['sha'], 0, 12) }}</strong>
                <p>{{ $candidate['available'] ? $t('Update available', 'يوجد تحديث متاح') : $t('Already up to date', 'لا يوجد إصدار أحدث') }}</p>
            </div>
        @endif
        @if($state['enabled'] && $state['configured'])
            <form method="post" action="{{ route('platform.updates.install') }}">
                @csrf
                <label for="confirmation">{{ $t('Type INSTALL to confirm a fresh verification, backup and installation', 'اكتب INSTALL لتأكيد فحص جديد ونسخ احتياطي وتثبيت') }}</label>
                <input id="confirmation" name="confirmation" required autocomplete="off" pattern="INSTALL" placeholder="INSTALL">
                <button class="install" type="submit">{{ $t('Install latest approved release', 'تثبيت أحدث إصدار معتمد') }}</button>
            </form>
        @else
            <div class="alert">{{ $t('Setup is required once: configure the release-signing public key and enable approved updates on the server. Installation is disabled until then.', 'يلزم الإعداد الأول مرة واحدة: وضع المفتاح العام للتحقق من توقيع الإصدار وتفعيل التحديثات على السيرفر. التثبيت معطّل حتى ذلك الوقت.') }}</div>
        @endif
        <hr style="border:0;border-top:1px solid var(--edge);margin:25px 0">
        <h2>{{ $t('Upload a signed update ZIP', 'رفع تحديث ZIP من لوحة الإدارة') }}</h2>
        <p class="muted">{{ $t(
            'Select one signed AIWMWeb release bundle from your computer. The server will verify its digital signature, hashes and paths before installing. Arbitrary ZIP files are rejected.',
            'اختر ملف ZIP واحدًا لحزمة AIWMWeb الموقّعة من جهازك. يتحقق السيرفر من التوقيع والبصمات والمسارات قبل التثبيت، ويرفض ملفات ZIP العشوائية.'
        ) }}</p>
        @if($state['enabled'] && $state['configured'])
            <form method="post" action="{{ route('platform.updates.upload') }}" enctype="multipart/form-data">
                @csrf
                <label for="update_bundle">{{ $t('Signed update bundle (.zip)', 'حزمة التحديث الموقّعة (.zip)') }}</label>
                <input id="update_bundle" type="file" name="update_bundle" accept=".zip,application/zip" required style="width:100%;max-width:480px">
                <label for="upload_confirmation">{{ $t('Type INSTALL to confirm installation', 'اكتب INSTALL لتأكيد التثبيت') }}</label>
                <input id="upload_confirmation" name="confirmation" required autocomplete="off" pattern="INSTALL" placeholder="INSTALL">
                <div style="margin-top:12px">
                    <button type="submit" class="install">{{ $t('Upload ZIP and install update', 'رفع ZIP وتثبيت التحديث') }}</button>
                </div>
            </form>
        @else
            <p class="alert">{{ $t('ZIP installation remains disabled until the one-time signing setup is complete.', 'تثبيت ZIP معطّل حتى اكتمال إعداد مفتاح التوقيع لأول مرة.') }}</p>
        @endif
        <small>{{ $t('Maximum upload: 160 MiB. Hosting PHP upload_max_filesize and post_max_size must be configured accordingly. The ZIP must contain a signed manifest, detached signature and the exact release ZIP.', 'الحجم الأقصى: 160 ميجابايت. يجب تهيئة حدود الرفع في PHP على الاستضافة. الحزمة تحتوي على ملف تعريف الإصدار الموقّع، والتوقيع المنفصل، وZIP الملفات الأصلي.') }}</small>
        <small>{{ $t('Database schema changes are not deployed by this safe first release channel. Requires signed artifacts and writable files; never uploads an arbitrary ZIP.', 'هذه القناة الآمنة لا تنفذ تعديلات قاعدة البيانات. تتطلب حزمًا موقعة وصلاحيات كتابة، ولا تقبل ZIP عشوائيًا.') }}</small>
    </section>
    <section class="panel" aria-labelledby="history">
        <h2 id="history">{{ $t('Recent update history', 'سجل آخر التحديثات') }}</h2>
        @forelse($history as $record)
            <div class="record">
                <strong>{{ $record['status'] ?? 'unknown' }}</strong>
                @isset($record['version']) <code>{{ $record['version'] }}</code> @endisset
                <small>{{ $record['installed_at'] ?? $record['at'] ?? '' }}</small>
                @isset($record['error']) <p>{{ $record['error'] }}</p> @endisset
            </div>
        @empty
            <p class="muted">{{ $t('No recorded update attempts.', 'لا توجد محاولات تحديث مسجلة.') }}</p>
        @endforelse
    </section>
</main>
</body>
</html>
