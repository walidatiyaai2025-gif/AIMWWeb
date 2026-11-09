@php
    $ar = request()->getPreferredLanguage(['ar', 'en']) === 'ar';
    $isArabic = $ar;
    $t = fn (string $en, string $arabic) => $ar ? $arabic : $en;
@endphp
<!doctype html>
<html lang="{{ $ar ? 'ar' : 'en' }}" dir="{{ $ar ? 'rtl' : 'ltr' }}" data-mode="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ $t('System updates', 'تحديثات النظام') }} | AIWMWeb</title>
    @unless (app()->runningUnitTests())
        @vite(['resources/css/app.css'])
    @endunless
    <style>
        :root { color-scheme: light; --green:#13845f; --navy:#15293c; --muted:#607187; --edge:#dce5eb; --bg:#f3f6f8; }
        * { box-sizing:border-box; }
        body { margin:0; background:var(--bg); font:10px/1.6 system-ui,-apple-system,"Segoe UI",sans-serif; color:var(--navy); }
        main { max-width:920px; padding:32px 20px 65px; margin:auto; }
        .top { display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap; }
        .back { color:var(--green); font-weight:650; text-decoration:none; }
        h1 { margin:12px 0 4px; font-size:clamp(20.3125px,3.125vw,29.6875px); }
        h2 { font-size:16.40625px; margin:0 0 12px; }
        .muted { color:var(--muted); }
        .panel { background:white; padding:24px; border:1px solid var(--edge); border-radius:17px; margin-top:18px; box-shadow:0 2px 7px #192f4510; }
        .grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:12px; }
        .stat { padding:16px; background:#f5f9f8; border-radius:12px; overflow-wrap:anywhere; }
        .stat span { display:block; font-size:10.15625px; color:var(--muted); }
        .stat strong { font-size:14.84375px; }
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
    <!-- Google Fonts: Inter (Latin) and Tajawal (Arabic); typography 25% larger than 62.5% baseline. -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&amp;family=Tajawal:wght@400;500;700;800;900&amp;display=swap">
    <style data-aiwm-global-typography>
        :root { font-size: 78.125%; --aiwm-google-font: 'Inter', 'Tajawal', sans-serif; --font-sans: var(--aiwm-google-font); }
        html:lang(ar) { --aiwm-google-font: 'Tajawal', 'Inter', sans-serif; }
        body, button, input, textarea, select, option { font-family: var(--aiwm-google-font) !important; }
        @media (min-width: 1140px) { main :is(.cards, .record-grid, .data-cards, .details-grid, .stats-grid, .summary-grid, .info-grid, .settings-grid), main dl:has(> div) { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; } }
        @media (min-width: 701px) and (max-width: 1139px) { main :is(.cards, .record-grid, .data-cards, .details-grid, .stats-grid, .summary-grid, .info-grid, .settings-grid), main dl:has(> div) { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; } }
        @media (max-width: 700px) { main :is(.cards, .record-grid, .data-cards, .details-grid, .stats-grid, .summary-grid, .info-grid, .settings-grid), main dl:has(> div) { display: grid; grid-template-columns: minmax(0, 1fr); gap: 10px; } }
            /* Three-column forms, plan cards and history records; full-width actions retained. */
        @media (min-width: 1140px) { main .grid, main form:has(> label ~ label ~ label), main section:has(> article ~ article), main section:has(> .record ~ .record) { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; } }
        @media (min-width: 701px) and (max-width: 1139px) { main .grid, main form:has(> label ~ label ~ label), main section:has(> article ~ article), main section:has(> .record ~ .record) { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; } }
        @media (max-width: 700px) { main .grid, main form:has(> label ~ label ~ label), main section:has(> article ~ article), main section:has(> .record ~ .record) { display: grid; grid-template-columns: minmax(0, 1fr); gap: 10px; } }
        main form:has(> label ~ label ~ label) > :not(label), main section:has(> article ~ article) > :not(article), main section:has(> .record ~ .record) > :not(.record) { grid-column: 1 / -1; }
        main :is(.grid, .cards, .record-grid) > * { min-width: 0; }
</style>
    @include('layouts.partials.workspace-read-styles')
    <style data-aiwm-update-center-shell-styles>
        .workspace-read-shell .workspace-read-content { max-width: 1180px; padding: 24px; }
        .workspace-read-shell .workspace-read-content .panel {
            background: var(--surface); color: var(--text); border: 1px solid var(--border);
            border-radius: 16px; box-shadow: none; padding: clamp(16px, 2vw, 24px);
        }
        .workspace-read-shell .workspace-read-content .grid { grid-template-columns: repeat(auto-fit, minmax(185px, 1fr)); }
        .workspace-read-shell .workspace-read-content .stat {
            background: var(--surface2); color: var(--text); border: 1px solid var(--border);
        }
        .workspace-read-shell .workspace-read-content :is(.muted, small, .stat span) { color: #c1ccdb; }
        .workspace-read-shell .workspace-read-content .alert {
            background: #4b341b; border: 1px solid #bd8d45; color: #fff2d4;
        }
        .workspace-read-shell .workspace-read-content .alert.ok {
            background: #173c2d; border-color: #3da57c; color: #e5fff0;
        }
        .workspace-read-shell .workspace-read-content .alert.error {
            background: #4c252a; border-color: #d27577; color: #fff0f0;
        }
        .workspace-read-shell .workspace-read-content button {
            color: #fff; background: #13845f; min-height: 44px;
        }
        .workspace-read-shell .workspace-read-content button.install { background: #44658a; }
        .workspace-read-shell .workspace-read-content button[disabled] { background: #687687; }
        .workspace-read-shell .workspace-read-content input {
            max-width: 100%; background: #f8fafc; color: #15293c;
        }
        .workspace-read-shell .workspace-read-content .record { border-color: var(--border); }
        .workspace-read-shell .workspace-read-content hr { border-color: var(--border) !important; }
        .workspace-read-shell .workspace-read-content .back { color: #e0bf73; }
        .workspace-read-shell .workspace-read-content .top { align-items: center; }
        @media (max-width: 760px) {
            .workspace-read-shell .workspace-read-content { padding: 12px; }
            .workspace-read-shell .workspace-read-content .grid { grid-template-columns: minmax(0, 1fr); }
            .workspace-read-shell .workspace-read-content form > input { width: 100%; }
        }
    </style>
</head>
<body>
@php
    $shellTitle = $t('System updates', 'تحديثات النظام');
    $shellScope = $t('Platform administration', 'إدارة المنصة');
    $shellHomeUrl = '/admin/system-updates';
    $shellLinks = [
        ['url' => $shellHomeUrl, 'label' => $shellTitle, 'active' => true],
        ['url' => '#installed', 'label' => $t('Installed version', 'الإصدار الحالي')],
        ['url' => '#runtime-activation', 'label' => $t('Queues and AI', 'الطوابير والذكاء الاصطناعي')],
        ['url' => '#available', 'label' => $t('Find and install an update', 'فحص وتثبيت تحديث')],
        ['url' => '#backup-now', 'label' => $t('Backup & Recovery', 'النسخ الاحتياطي والاسترجاع')],
        ['url' => '#history', 'label' => $t('Recent update history', 'سجل آخر التحديثات')],
    ];
@endphp
<div class="app-shell workspace-read-shell" data-dashboard-shell="aiwmweb">
    <a class="skip-link" href="#workspace-read-content">{{ $t('Skip to content', 'تخط إلى المحتوى') }}</a>
    @include('layouts.partials.workspace-read-sidebar')
    <div class="main-area">
        @include('layouts.partials.workspace-read-topbar')
        <main id="workspace-read-content" class="content workspace-read-content">
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
    <section class="panel" aria-labelledby="runtime-activation" data-testid="cpanel-runtime-activation">
        <h2 id="runtime-activation">{{ $t('Queues, Cron and AI activation', 'تفعيل الطوابير وCron والذكاء الاصطناعي') }}</h2>
        <p class="muted">{{ $t(
            'Database Queue preserves jobs. The private Cron runner is included in the Setup ZIP and must be registered once through cPanel Cron Jobs. An enabled switch alone never proves work is running.',
            'تحفظ Database Queue المهام. ملف Cron الخاص موجود داخل Setup ZIP ويحتاج إضافته مرة واحدة من Cron Jobs في cPanel. مجرد التفعيل لا يثبت تشغيل المهام.'
        ) }}</p>
        <p class="muted">{{ $t(
            'Every minute: use your hosting PHP 8.3+ CLI executable to run PRIVATE_APP_ROOT/aiwm-cpanel-cron.php (not public/, queue:work or schedule:run). The host must permit CLI PHP and child processes. No SSH or Composer is needed.',
            'كل دقيقة: اختر PHP CLI 8.3+ الخاص بالاستضافة وشغّل PRIVATE_APP_ROOT/aiwm-cpanel-cron.php وليس داخل public أو باستخدام queue:work أو schedule:run مباشرة. يجب أن تسمح الاستضافة بعمليات PHP CLI الفرعية. لا يلزم SSH أو Composer.'
        ) }}</p>
        <div class="grid">
            <div class="stat"><span>{{ $t('Queue driver', 'نوع الطابور') }}</span><strong>{{ $runtimeActivation['queue_driver'] }}</strong></div>
            <div class="stat"><span>{{ $t('Background queue', 'الطابور الخلفي') }}</span><strong>{{ $runtimeActivation['queue_enabled'] ? 'Enabled' : 'Disabled' }}</strong></div>
            <div class="stat"><span>{{ $t('AI admissions', 'طلبات الذكاء الاصطناعي') }}</span><strong>{{ $runtimeActivation['ai_enabled'] ? 'Enabled' : 'Disabled' }}</strong></div>
            <div class="stat"><span>{{ $t('Real Cron invocation', 'استدعاء Cron الفعلي') }}</span><strong>{{ $runtimeActivation['cron_invocation']['status'] ?? 'unavailable' }}</strong></div>
            <div class="stat"><span>{{ $t('Verified worker cycle', 'دورة العامل المثبتة') }}</span><strong>{{ $runtimeActivation['worker_cycle']['status'] ?? 'unavailable' }}</strong></div>
            <div class="stat"><span>{{ $t('Scheduler heartbeat', 'نبض المجدول') }}</span><strong>{{ $runtimeActivation['scheduler_heartbeat']['status'] ?? 'unavailable' }}</strong></div>
        </div>
        @if($runtimeActivation['profile'] !== 'cpanel')
            <div class="alert">{{ $t('cPanel profile is not active; do not apply cPanel controls to VPS.', 'وضع cPanel غير نشط؛ لا تطبّق هذه الإعدادات على VPS.') }}</div>
        @else
            @if(count($runtimeActivation['queue_blockers']))
                <p class="alert" role="status">{{ $t('Queue activation blocked until these verified preconditions pass:', 'تفعيل الطوابير متوقف حتى اجتياز الشروط الفعلية:') }}
                    <code>{{ implode(' · ', $runtimeActivation['queue_blockers']) }}</code>
                </p>
            @endif
            <form method="post" action="{{ route('platform.updates.runtime-activate') }}">
                @csrf
                <input type="hidden" name="action" value="queue">
                <label for="queue_activation_confirmation">{{ $t('Type ACTIVATE to confirm database queue + bounded Cron activation', 'اكتب ACTIVATE لتأكيد تشغيل Database Queue وCron المحدود') }}</label>
                <input id="queue_activation_confirmation" name="confirmation" required pattern="ACTIVATE" autocomplete="off" placeholder="ACTIVATE">
                <button type="submit" @disabled($runtimeActivation['queue_enabled'] || count($runtimeActivation['queue_blockers']))>{{ $t('Activate verified queue', 'تفعيل الطابور المتحقق منه') }}</button>
            </form>
            @if(count($runtimeActivation['ai_blockers']))
                <p class="alert" role="status">{{ $t('AI activation prerequisites not yet complete:', 'شروط تفعيل الذكاء الاصطناعي غير مكتملة:') }}
                    <code>{{ implode(' · ', $runtimeActivation['ai_blockers']) }}</code>
                </p>
            @endif
            <p class="muted">{{ $t(
                'AI activation enables one-at-a-time bounded background generation, not automatic WordPress publishing. Provider READY is not proof that POST generation succeeds; verify a single reviewed SEO proposal after activation.',
                'تفعيل AI يسمح بتوليد محدود داخل العامل الخلفي وليس بالنشر التلقائي في WordPress. حالة READY لا تثبت نجاح التوليد؛ اختبر اقتراح SEO واحدًا بعد التفعيل.'
            ) }}</p>
            @if($runtimeActivation['ai_enabled'])
                <form method="post" action="{{ route('platform.updates.runtime-activate') }}">
                    @csrf
                    <input type="hidden" name="action" value="disable_ai">
                    <label for="disable_ai_confirmation">ACTIVATE</label>
                    <input id="disable_ai_confirmation" name="confirmation" required pattern="ACTIVATE" autocomplete="off" placeholder="ACTIVATE">
                    <button class="install" type="submit">{{ $t('Stop new AI admissions safely', 'إيقاف قبول طلبات AI الجديدة بأمان') }}</button>
                </form>
            @else
                <form method="post" action="{{ route('platform.updates.runtime-activate') }}">
                    @csrf
                    <input type="hidden" name="action" value="ai">
                    <label for="ai_activation_confirmation">{{ $t('Type ACTIVATE to enable bounded AI requests after real worker health', 'اكتب ACTIVATE لتشغيل AI المحدود بعد إثبات صحة العامل') }}</label>
                    <input id="ai_activation_confirmation" name="confirmation" required pattern="ACTIVATE" autocomplete="off" placeholder="ACTIVATE">
                    <button type="submit" @disabled(count($runtimeActivation['ai_blockers']))>{{ $t('Activate verified AI', 'تفعيل AI بعد التحقق') }}</button>
                </form>
            @endif
        @endif
    </section>
    <section class="panel" aria-labelledby="available">
        <h2 id="available">{{ $t('Find and install an update', 'فحص وتثبيت تحديث') }}</h2>
        <p class="muted">{{ $t('Checks the latest published GitHub release, verifies its Ed25519 signature, and compares it with this installation.', 'يفحص أحدث إصدار منشور على GitHub، ويتحقق من توقيعه الرقمي ثم يقارنه بالإصدار المثبت.') }}</p>
        @if(session('system_update_check_failure'))
            <div class="alert error" role="alert" data-testid="release-metadata-failure">
                <strong>{{ $t('Release metadata check failed — no installation was started.', 'فشل فحص بيانات الإصدار — لم يبدأ أي تثبيت.') }}</strong>
                <p>{{ $t('Phase: signed release metadata lookup', 'المرحلة: جلب بيانات الإصدار الموقّع') }}
                    · {{ $t('Reason', 'السبب') }}:
                    @switch(session('system_update_check_failure.category'))
                        @case('connection_timeout')
                            {{ $t('Connection timed out or failed', 'انتهت مهلة الاتصال أو تعذر الاتصال') }}
                            @break
                        @case('signature_invalid')
                            {{ $t('Digital signature verification failed', 'فشل التحقق من التوقيع الرقمي') }}
                            @break
                        @default
                            {{ $t('Release metadata could not be verified', 'تعذر التحقق من بيانات الإصدار') }}
                    @endswitch
                </p>
                <p>{{ $t('Try Check for updates again. If outbound access to GitHub remains blocked, use Verify Package with an authentic signed bundle instead. No maintenance mode or update was started by this check.', 'أعد البحث عن تحديث. إذا ظل اتصال الاستضافة بـGitHub متعذرًا، استخدم التحقق من حزمة ZIP موقّعة وموثوقة بدلًا منه. لم يبدأ هذا الفحص وضع الصيانة أو أي تحديث.') }}</p>
            </div>
        @endif
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
            <form method="post" action="{{ route('platform.updates.verify') }}" enctype="multipart/form-data">
                @csrf
                <label for="verify_bundle">{{ $t('Verify Package — signed ZIP (no installation)', 'التحقق من الحزمة — ZIP موقّع دون تثبيت') }}</label>
                <input id="verify_bundle" type="file" name="update_bundle" accept=".zip,application/zip" required style="width:100%;max-width:480px">
                <button type="submit">{{ $t('Verify Package', 'التحقق من الحزمة') }}</button>
            </form>
            @if(session('system_update_verified'))
                <div class="alert ok" role="status">
                    {{ $t('Signature and file hashes verified:', 'تم التحقق من التوقيع وبصمات الملفات:') }}
                    <strong>{{ session('system_update_verified')['version'] }}</strong>
                    <code>{{ substr(session('system_update_verified')['sha'], 0, 12) }}</code>
                    ({{ session('system_update_verified')['file_count'] }} {{ $t('files', 'ملف') }})
                </div>
            @endif
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
        <small>{{ $t('Signed forward-only Laravel migrations run after verified MySQL backup. If a migration fails, maintenance mode stays enabled and assisted recovery from the isolated backup is required; database rollback is never assumed.', 'تُنفذ ترحيلات Laravel الموقّعة بعد النسخ الاحتياطي الموثق لقاعدة MySQL. عند فشل ترحيل تبقى الصيانة مفعّلة ويلزم الاسترجاع الموجّه من النسخة المعزولة، ولا يُفترض إمكانية التراجع الآلي عن قاعدة البيانات.') }}</small>
    </section>
    <section class="panel" aria-labelledby="backup-now">
        <h2 id="backup-now">{{ $t('Backup & Recovery', 'النسخ الاحتياطي والاسترجاع') }}</h2>
        <p class="muted">{{ $t(
            'Backup Now encrypts an InnoDB consistent snapshot and verifies its integrity. It requires MySQL and private writable storage. Restore is intentionally limited to a separate empty recovery database; production restore is not automatic.',
            'ينشئ النسخ الاحتياطي نسخة متسقة مشفرة من InnoDB ويتحقق من سلامتها. يتطلب MySQL وتخزينًا خاصًا قابلًا للكتابة. الاسترجاع متاح فقط إلى قاعدة بيانات منفصلة وفارغة، ولا يتم على قاعدة الإنتاج تلقائيًا.'
        ) }}</p>
        <form method="post" action="{{ route('platform.updates.backup') }}">
            @csrf
            <label for="backup_confirmation">{{ $t('Type BACKUP to confirm', 'اكتب BACKUP لتأكيد النسخ الاحتياطي') }}</label>
            <input id="backup_confirmation" name="confirmation" pattern="BACKUP" required autocomplete="off">
            <button type="submit">{{ $t('Backup Now', 'نسخ احتياطي الآن') }}</button>
        </form>
        <p class="muted">{{ $t(
            'Do not use one-click update for irreversible schema migrations until a tested, separately recoverable database snapshot exists.',
            'لا تستخدم التحديث بضغطة واحدة مع ترحيلات قاعدة بيانات غير القابلة للعكس إلا بعد وجود نسخة قابلة للاسترجاع ومختبرة.'
        ) }}</p>
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
    </div>
</div>
</body>
</html>
