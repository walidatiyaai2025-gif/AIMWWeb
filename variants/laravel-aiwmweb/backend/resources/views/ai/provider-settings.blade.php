@php($isArabic = app()->getLocale() === 'ar')
<!doctype html>
<html lang="{{ $isArabic ? 'ar' : 'en' }}" dir="{{ $isArabic ? 'rtl' : 'ltr' }}" data-mode="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="dark light">
    <title>{{ $isArabic ? 'إعدادات مزودي الذكاء الاصطناعي' : 'AI Provider Settings' }} — AIWMWeb</title>
    @unless (app()->runningUnitTests())
        @vite(['resources/css/app.css'])
    @endunless
    <!-- Google Fonts: Inter (Latin) and Tajawal (Arabic); typography 25% larger than the 62.5% baseline. -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&amp;family=Tajawal:wght@400;500;700;800;900&amp;display=swap">
    <style data-aiwm-global-typography>
        :root { font-size: 78.125%; --aiwm-google-font: 'Inter', 'Tajawal', sans-serif; --font-sans: var(--aiwm-google-font); }
        html:lang(ar) { --aiwm-google-font: 'Tajawal', 'Inter', sans-serif; }
        body, button, input, textarea, select, option { font-family: var(--aiwm-google-font) !important; }

        @media (min-width: 1140px) {
            main :is(.cards, .record-grid, .data-cards, .details-grid, .stats-grid, .summary-grid, .info-grid, .settings-grid),
            main dl:has(> div) { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; }
        }
        @media (min-width: 701px) and (max-width: 1139px) {
            main :is(.cards, .record-grid, .data-cards, .details-grid, .stats-grid, .summary-grid, .info-grid, .settings-grid),
            main dl:has(> div) { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
        }
        @media (max-width: 700px) {
            main :is(.cards, .record-grid, .data-cards, .details-grid, .stats-grid, .summary-grid, .info-grid, .settings-grid),
            main dl:has(> div) { display: grid; grid-template-columns: minmax(0, 1fr); gap: 10px; }
        }
            /* Three-column forms, plan cards and history records; full-width actions retained. */
        @media (min-width: 1140px) { main .grid, main form:has(> label ~ label ~ label), main section:has(> article ~ article), main section:has(> .record ~ .record) { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; } }
        @media (min-width: 701px) and (max-width: 1139px) { main .grid, main form:has(> label ~ label ~ label), main section:has(> article ~ article), main section:has(> .record ~ .record) { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; } }
        @media (max-width: 700px) { main .grid, main form:has(> label ~ label ~ label), main section:has(> article ~ article), main section:has(> .record ~ .record) { display: grid; grid-template-columns: minmax(0, 1fr); gap: 10px; } }
        main form:has(> label ~ label ~ label) > :not(label), main section:has(> article ~ article) > :not(article), main section:has(> .record ~ .record) > :not(.record) { grid-column: 1 / -1; }
        main :is(.grid, .cards, .record-grid) > * { min-width: 0; }
        body { background: #0b0f17; color: #f1f5fb; margin: 0; padding: 0; }
        .provider-settings-shell { --bg: #0b0f17; --surface: #151923; --surface2: #1b2130; --border: #30394c; --text: #f4f6fa; --muted: #9aa5b5; --accent: #c5a45d; --accent-soft: rgba(197,164,93,.16); --radius: 16px; display: grid; grid-template-columns: minmax(220px, 284px) minmax(0, 1fr); min-height: 100vh; background: var(--bg); }
        .provider-settings-shell, .provider-settings-shell * { box-sizing: border-box; }
        .provider-settings-shell .sidebar { position: sticky; top: 0; height: 100vh; overflow-y: auto; display: flex; flex-direction: column; gap: 18px; background: var(--surface); border-inline-end: 1px solid var(--border); padding: 20px; }
        .provider-settings-shell .brand { color: var(--text); text-decoration: none; display: flex; align-items: center; gap: 12px; margin-bottom: 14px; }
        .provider-settings-shell .brand-mark { background: var(--accent); color: #14100b; border-radius: 10px; padding: 10px; font-weight: 800; }
        .provider-settings-shell .brand-copy { display: grid; min-width: 0; gap: 4px; }
        .provider-settings-shell .brand-copy small, .provider-settings-shell .sidebar-footer { color: var(--muted); }
        .provider-settings-shell .main-nav { display: grid; gap: 8px; }
        .provider-settings-shell .main-nav a { display: block; padding: 12px; border-radius: 10px; color: var(--text); text-decoration: none; min-height: 44px; }
        .provider-settings-shell .main-nav a.active, .provider-settings-shell .main-nav a:hover { background: var(--accent-soft); color: #e0bf73; }
        .provider-settings-shell .sidebar-footer { margin-top: auto; border-top: 1px solid var(--border); padding-top: 16px; overflow-wrap: anywhere; }
        .provider-settings-shell .main-area { min-width: 0; }
        .provider-settings-shell .topbar { background: var(--bg); border-bottom: 1px solid var(--border); padding: 16px 24px; display: flex; align-items: center; justify-content: space-between; gap: 18px; flex-wrap: wrap; }
        .provider-settings-shell .topbar h1 { margin: 0; font-size: 1.4rem; }
        .provider-settings-shell .topbar a { color: #e0bf73; }
        .provider-settings-shell main { max-width: 1150px; padding: 24px; margin: auto; min-width: 0; }
        .provider-settings-shell main > header, .provider-settings-shell main > section { background: #151923; border: 1px solid #30394c; border-radius: 18px; padding: 20px; margin-bottom: 16px; }
        .provider-settings-shell main a { color: #e0bf73; margin-inline-end: 16px; }
        .provider-settings-shell .provider-nav { display: flex; flex-wrap: wrap; gap: 12px; }
        @media (max-width: 700px) { .provider-settings-shell { grid-template-columns: minmax(0, 1fr); } .provider-settings-shell .sidebar { position: static; height: auto; padding: 12px; } .provider-settings-shell .main-nav { grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); } .provider-settings-shell .sidebar-footer { display: none; } .provider-settings-shell main { padding: 12px; } .provider-settings-shell .topbar { padding: 12px; } }
        .provider-fields { display: grid; grid-template-columns: repeat(auto-fit,minmax(240px,1fr)); gap: 14px; }
        .provider-fields label { display: flex; flex-direction: column; gap: 7px; }
        .provider-fields input { min-height: 42px; border-radius: 9px; border: 1px solid #51637a; background: #0e1929; color: white; padding: 8px; max-width: 100%; box-sizing: border-box; }
        .provider-fields input[type=checkbox] { min-height: 20px; }
        .provider-fields .provider-toggle { flex-direction: row; align-items: center; }
        .provider-save, button { cursor: pointer; border: 0; border-radius: 10px; background: #e7b74b; color: #101827; font-weight: 800; padding: 12px 22px; margin: 8px 0; }
        .provider-editor, main article { padding: 16px; border: 1px solid #2d4360; border-radius: 12px; margin: 12px 0; }
        .validation-errors { color: #ffaaaa; }
        .runtime-warning { color: #ffd27a; font-weight: 700; }
        dl { display: grid; grid-template-columns: minmax(120px,1fr) minmax(150px,2fr); gap: 6px; }
        dl dt, dl dd { min-width: 0; overflow-wrap: anywhere; margin: 0; }
</style>
</head>
<body>
<div class="app-shell provider-settings-shell" data-dashboard-shell="aiwmweb">
    <a class="skip-link" href="#provider-settings-content">{{ $isArabic ? 'تخط إلى المحتوى' : 'Skip to content' }}</a>
    <aside class="sidebar" aria-label="{{ $isArabic ? 'التنقل الرئيسي' : 'Primary navigation' }}">
        <div class="brand-row">
            <a class="brand" href="/tenants/{{ rawurlencode($tenant->slug) }}/module/overview">
                <span class="brand-mark" aria-hidden="true">AI</span>
                <span class="brand-copy"><strong>AI WordPress Manager</strong><small>{{ $tenant->name }}</small></span>
            </a>
        </div>
        <nav class="main-nav" aria-label="{{ $isArabic ? 'التنقل في الحساب' : 'Tenant navigation' }}">
            <a href="/tenants/{{ rawurlencode($tenant->slug) }}/module/overview">{{ $isArabic ? 'نظرة عامة' : 'Overview' }}</a>
            <a href="/tenants/{{ rawurlencode($tenant->slug) }}/settings">{{ $isArabic ? 'إعدادات الحساب' : 'Tenant settings' }}</a>
            <a href="/tenants/{{ rawurlencode($tenant->slug) }}/settings/ai-prompts">{{ $isArabic ? 'قوالب أوامر الذكاء الاصطناعي' : 'AI prompt templates' }}</a>
            <a class="active" aria-current="page" href="/tenants/{{ rawurlencode($tenant->slug) }}/settings/ai-providers">{{ $isArabic ? 'مزودو الذكاء الاصطناعي' : 'AI providers' }}</a>
        </nav>
        <div class="sidebar-footer">{{ $isArabic ? 'مساحة عمل محمية' : 'Protected tenant workspace' }}</div>
    </aside>
    <div class="main-area">
        <header class="topbar">
            <div><small>{{ $tenant->name }} / {{ $isArabic ? 'إعدادات الحساب' : 'Tenant settings' }}</small><h1>{{ $isArabic ? 'إعدادات مزودي الذكاء الاصطناعي' : 'AI Provider Settings' }}</h1></div>
            <a class="btn" href="/tenants/{{ rawurlencode($tenant->slug) }}/settings">{{ $isArabic ? 'العودة إلى الإعدادات' : 'Back to Settings' }}</a>
        </header>
    <main id="provider-settings-content" class="content provider-settings-content" data-canonical-operation="AIMW-AI-58FABCCEDB">
        <header>
            <p>{{ $isArabic ? 'سجل مزودي الذكاء الاصطناعي' : 'AI PROVIDER REGISTRY' }}</p>
            <h1>{{ $isArabic ? 'إعدادات مزودي الذكاء الاصطناعي' : 'AI Provider Settings' }}</h1>
            <p>{{ $isArabic ? 'مراجعة إعدادات المزود المحفوظة وجاهزية التشغيل للحساب' : 'Review persisted provider configuration and runtime readiness for' }} {{ $tenant->name }}.</p>
            <p>إدارة آمنة لمزودات الذكاء عبر حساب الـOwner. المفاتيح لا تظهر مطلقًا بعد حفظها.</p>
            <nav aria-label="AI provider settings sections">
                {{-- AIMW-AI-3B59F172AB: canonical Back to Settings navigation --}}
                <a
                    href="/tenants/{{ $tenant->slug }}/settings"
                    aria-label="Back to Settings"
                    data-canonical-operation="AIMW-AI-3B59F172AB"
                >{{ $isArabic ? 'العودة إلى الإعدادات' : '← Settings' }}</a>
                <a href="#runtime" data-canonical-operation="AIMW-AI-44DD850CE4">{{ $isArabic ? 'حالة التشغيل' : 'Runtime behavior' }}</a>
                <a href="#providers" data-canonical-operation="AIMW-AI-4F6B6584E4">{{ $isArabic ? 'المزودون والمفاتيح' : 'Providers and keys' }}</a>
            </nav>
        </header>

        @if (session('status'))
            <p role="status">{{ session('status') }}</p>
        @endif

        <section id="runtime" aria-labelledby="runtime-behavior">
            <h2 id="runtime-behavior">{{ $isArabic ? 'حالة التشغيل' : 'Runtime behavior' }}</h2>
            <p>Review the active tenant's persisted provider enablement, fallback capability, and readiness before changing separate governed AI settings.</p>
            <p>This section is read-only: it reports runtime status; edit provider settings in the separate form below.</p>
            <p>يمكن حفظ المزود واختبار الاتصال بالأسفل. تشغيل مزود لا يتجاوز شروط Cron أو Preflight على الاستضافة.</p>
            <p><strong>AI enabled:</strong> {{ $runtime['ai_enabled'] ? 'Yes' : 'No' }} · <strong>Cron:</strong> {{ $runtime['cron_enabled'] ? 'Yes' : 'No' }} · <strong>Preflight:</strong> {{ $runtime['preflight_passed'] ? 'Passed' : 'Not approved' }} · <strong>Queue:</strong> {{ $runtime['queue_driver'] }}</p>
            @if ($runtime['queue_driver'] === 'sync' || ! $runtime['ai_enabled'] || ! $runtime['cron_enabled'] || ! $runtime['preflight_passed'])
                <p class="runtime-warning">مزود AI يمكن حفظه واختبار الاتصال به الآن، لكن تنفيذ AI المؤجل في cPanel غير متاح لحين تفعيل Worker محدود وإتمام فحص الاستضافة. لا يتم فتح طوابير غير محدودة.</p>
            @endif
        </section>

        <section id="provider-editor" aria-label="إعدادات Ollama">
            <h2>{{ $isArabic ? 'ربط مزود الذكاء الاصطناعي' : 'Configure AI provider / Ollama' }}</h2>
            <p>اكتب الرابط والمفتاح والنموذج الموجود على سيرفر AI واضغط حفظ. المفتاح لا يُرسل مرة أخرى إلى المتصفح.</p>
            @include('ai.provider-form', ['provider' => null])
        </section>

        <section id="providers" aria-labelledby="provider-registry">
            <h2 id="provider-registry">{{ $isArabic ? 'سجل المزودين' : 'Provider registry' }}</h2>
            <p>{{ count($providers) }} configured {{ count($providers) === 1 ? 'provider' : 'providers' }}</p>

            @forelse ($providers as $provider)
                <article data-provider-key="{{ $provider['provider_key'] }}">
                    <header>
                        <h3>{{ $provider['display_name'] }}</h3>
                        <p>{{ $provider['provider_key'] }} · {{ $provider['enabled'] ? 'Enabled' : 'Disabled' }}</p>
                    </header>
                    <dl>
                        <dt>{{ $isArabic ? 'المحوّل' : 'Adapter' }}</dt><dd>{{ $provider['adapter_key'] }}</dd>
                        <dt>{{ $isArabic ? 'عنوان الخدمة' : 'Endpoint' }}</dt><dd>{{ $provider['endpoint'] ?: 'Default provider endpoint' }}</dd>
                        <dt>{{ $isArabic ? 'النموذج الافتراضي' : 'Default model' }}</dt><dd>{{ $provider['default_model'] ?: 'Not configured' }}</dd>
                        <dt>{{ $isArabic ? 'الأولوية' : 'Priority' }}</dt><dd>{{ $provider['priority'] }}</dd>
                        <dt>{{ $isArabic ? 'المهلة' : 'Timeout' }}</dt><dd>{{ $provider['timeout_seconds'] }} seconds</dd>
                        <dt>{{ $isArabic ? 'أقصى المحاولات' : 'Maximum attempts' }}</dt><dd>{{ $provider['max_attempts'] }}</dd>
                        <dt>{{ $isArabic ? 'الانتقال التلقائي عند الفشل' : 'Automatic failover' }}</dt><dd>{{ $provider['automatic_failover'] ? 'Enabled' : 'Disabled' }}</dd>
                        <dt>{{ $isArabic ? 'الجاهزية' : 'Readiness' }}</dt><dd>{{ $provider['readiness'] }}</dd>
                        @if ($provider['adapter_key'] === 'openai-compatible')
                            <dt>{{ $isArabic ? 'نطاق اختبار الاتصال' : 'Connection check scope' }}</dt>
                            <dd data-provider-probe-scope="models-only">{{ $isArabic ? 'فحص GET /models فقط: ظهور READY يعني إمكانية اكتشاف النموذج، ولا يثبت نجاح توليد المحتوى.' : 'GET /models only: READY confirms model discovery, not successful content generation.' }}</dd>
                            <dt>{{ $isArabic ? 'حالة اختبار التوليد' : 'Generation verification' }}</dt>
                            <dd data-provider-generation-status="unverified">{{ $isArabic ? 'غير مختبر: يحتاج POST /chat/completions وصلاحية JSON والتحقق من Cron وWorker بصورة منفصلة، باختبار آمن مصرح به.' : 'Not verified: POST /chat/completions, JSON output validity and cPanel Cron/worker health require separate authorized checks.' }}</dd>
                        @endif
                        <dt>{{ $isArabic ? 'آخر فحص جاهزية' : 'Readiness checked' }}</dt><dd>{{ $provider['readiness_checked_at'] ?: 'Not checked' }}</dd>
                        <dt>{{ $isArabic ? 'سبب عدم الجاهزية' : 'Readiness error' }}</dt><dd>{{ $provider['readiness_error'] ?: 'None' }}</dd>
                        <dt>{{ $isArabic ? 'مفتاح API' : 'API credential' }}</dt><dd>{{ $provider['has_api_key'] ? 'Configured' : 'Not configured' }}</dd>
                    </dl>

                    @if ($provider['adapter_key'] === 'openai-compatible')
                        <details>
                            <summary>تعديل المزود / Edit connection</summary>
                            @include('ai.provider-form', ['provider' => $provider])
                        </details>
                        <form method="post" action="{{ route('tenant.settings.ai-providers.check', ['tenant' => $tenant->slug, 'provider' => $provider['provider_key']]) }}">
                            @csrf
                            <button type="submit">فحص الاتصال والنموذج / Check connection</button>
                        </form>
                    @endif

                    @if ($provider['has_api_key'])
                        {{-- AIMW-AI-6701FB22AE: Confirm API key removal [ConfirmRemovalAndSaveAsync] --}}
                        <details data-canonical-operation="AIMW-AI-6701FB22AE">
                            <summary>Confirm API key removal</summary>
                            <p>You are about to remove stored encrypted keys from provider settings.</p>
                            <p><strong>Impact:</strong> A provider that requires a key may stop executing AI requests after this save.</p>
                            <p><strong>Recovery:</strong> You can add a new key later, but the deleted value itself cannot be recovered from the UI.</p>
                            <form method="post" action="{{ route('tenant.settings.ai-providers.api-key.destroy', ['tenant' => $tenant->slug, 'provider' => $provider['provider_key']]) }}">
                                @csrf
                                @method('DELETE')
                                <label for="remove-api-key-{{ $provider['id'] }}">Type REMOVE to confirm</label>
                                <input
                                    id="remove-api-key-{{ $provider['id'] }}"
                                    name="confirmation"
                                    type="text"
                                    autocomplete="off"
                                    spellcheck="false"
                                    required
                                    aria-describedby="remove-api-key-impact-{{ $provider['id'] }}"
                                >
                                <span id="remove-api-key-impact-{{ $provider['id'] }}">The stored encrypted API key will be deleted.</span>
                                @error('confirmation')
                                    <p role="alert">{{ $message }}</p>
                                @enderror
                                <button type="submit">Remove keys and save</button>
                            </form>
                        </details>
                    @endif

                    <section aria-label="Models for {{ $provider['provider_key'] }}">
                        <h4>{{ $isArabic ? 'النماذج' : 'Models' }}</h4>
                        @forelse ($provider['models'] as $model)
                            <article data-model-key="{{ $model['model_key'] }}">
                                <strong>{{ $model['display_name'] }}</strong>
                                <span> · {{ $model['enabled'] ? 'Enabled' : 'Disabled' }}</span>
                            </article>
                        @empty
                            <p>No persisted model profiles.</p>
                        @endforelse
                    </section>
                </article>
            @empty
                <p role="status">No AI provider profiles have been persisted for this tenant.</p>
            @endforelse
        </section>
    </main>
    </div>
</div>
</body>
</html>
