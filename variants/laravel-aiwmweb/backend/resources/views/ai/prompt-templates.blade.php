<!doctype html>
<html lang="en" dir="ltr" data-mode="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="dark light">
    <title>AI Prompt Templates — AIWMWeb</title>
    @if (is_file(public_path('build/manifest.json')))
        @vite(['resources/css/app.css'])
    @endif
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&amp;family=Tajawal:wght@400;500;700;800&amp;display=swap">
    <style data-aiwm-prompt-dashboard-styles>
        :root { color-scheme: dark; --bg: #0b0f17; --surface: #151923; --surface2: #1b2130; --border: #30394c; --text: #f4f6fa; --muted: #9aa5b5; --accent: #c5a45d; --accent-soft: rgba(197,164,93,.16); --accent-strong: #e0bf73; --radius: 16px; --sidebar-width: 284px; --gutter: clamp(1rem,2vw,2rem); --font-sans: 'Inter','Tajawal',sans-serif; }
        html:lang(ar) { --font-sans: 'Tajawal','Inter',sans-serif; }
        html, body { min-height: 100%; margin: 0; background: var(--bg); color: var(--text); font-family: var(--font-sans); }
        .prompt-shell, .prompt-shell * { box-sizing: border-box; }
        .prompt-shell { display: grid; grid-template-columns: var(--sidebar-width) minmax(0,1fr); min-height: 100vh; background: var(--bg); }
        .prompt-shell a { color: var(--accent-strong); }
        .prompt-shell .sidebar { position: sticky; top: 0; min-width: 0; height: 100vh; overflow-y: auto; display: flex; flex-direction: column; padding: 1rem; background: var(--surface); border-inline-end: 1px solid var(--border); }
        .prompt-shell .brand-row { padding: .6rem 0 1rem; border-bottom: 1px solid var(--border); margin-bottom: 1rem; }
        .prompt-shell .brand { display: flex; align-items: center; gap: .8rem; color: var(--text); text-decoration: none; }
        .prompt-shell .brand-mark { display: grid; place-items: center; width: 44px; height: 44px; border-radius: 12px; background: var(--accent); color: #15120b; font-weight: 800; }
        .prompt-shell .brand-copy { display: grid; min-width: 0; gap: .2rem; }
        .prompt-shell .brand-copy small { color: var(--muted); }
        .prompt-shell .main-nav { display: grid; gap: .7rem; }
        .prompt-shell .nav-section { border: 1px solid var(--border); border-radius: 12px; padding: .35rem; }
        .prompt-shell .nav-section summary { color: var(--muted); font-size: .82rem; font-weight: 800; padding: .5rem; cursor: pointer; display: flex; justify-content: space-between; }
        .prompt-shell .nav-links { display: grid; gap: .25rem; }
        .prompt-shell .nav-links a { min-height: 44px; display: flex; align-items: center; gap: .6rem; padding: .65rem; border-radius: 10px; color: var(--text); text-decoration: none; }
        .prompt-shell .nav-links a.active, .prompt-shell .nav-links a:hover { background: var(--accent-soft); color: var(--accent-strong); }
        .prompt-shell .sidebar-footer { margin-top: auto; border-top: 1px solid var(--border); padding-top: 1rem; display: grid; gap: .25rem; }
        .prompt-shell .sidebar-footer small { color: var(--muted); }
        .prompt-shell .main-area { min-width: 0; }
        .prompt-shell .topbar { position: sticky; top: 0; z-index: 3; min-height: 76px; background: var(--bg); border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; gap: 1rem; padding: .8rem var(--gutter); }
        .prompt-shell .topbar h1 { font-size: 1.15rem; margin: .3rem 0; }
        .prompt-shell .topbar small, .prompt-shell .breadcrumb { color: var(--muted); }
        .prompt-shell .breadcrumb { display: flex; gap: .5rem; align-items: center; font-size: .76rem; }
        .prompt-shell .topbar-actions { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; }
        .prompt-shell .content { width: min(100%,1560px); padding: 1.5rem var(--gutter) 3rem; margin: 0 auto; }
        .prompt-shell .prompt-workspace { display: grid; gap: 1rem; }
        .prompt-shell .prompt-workspace > header, .prompt-shell .prompt-workspace > section { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: clamp(1rem,2vw,1.5rem); }
        .prompt-shell .prompt-workspace header h2, .prompt-shell .prompt-workspace h2 { margin: 0 0 .6rem; font-size: 1.28rem; }
        .prompt-shell .prompt-workspace p { line-height: 1.55; }
        .prompt-shell .prompt-workspace nav { display: flex; flex-wrap: wrap; gap: .65rem; align-items: center; }
        .prompt-shell .prompt-workspace article, .prompt-shell .prompt-workspace section[id=new-template-editor] { background: var(--surface2); border: 1px solid var(--border); border-radius: 14px; padding: 1rem; margin-top: 1rem; }
        .prompt-shell .prompt-workspace article section { border-top: 1px solid var(--border); margin-top: 1rem; padding-top: 1rem; }
        .prompt-shell .prompt-workspace form { display: grid; gap: .55rem; width: 100%; }
        .prompt-shell .prompt-workspace form p { margin: .35rem 0; }
        .prompt-shell .prompt-workspace label { display: block; margin-bottom: .35rem; color: var(--text); font-weight: 600; }
        .prompt-shell .prompt-workspace input:not([type=checkbox]), .prompt-shell .prompt-workspace textarea { width: 100%; max-width: 100%; padding: .8rem; min-height: 44px; border-radius: 10px; border: 1px solid #526079; background: var(--bg); color: var(--text); font: inherit; }
        .prompt-shell .prompt-workspace textarea { min-height: 110px; resize: vertical; }
        .prompt-shell .prompt-workspace input[type=checkbox] { margin-inline-end: .45rem; accent-color: var(--accent); }
        .prompt-shell .prompt-workspace dl { display: grid; grid-template-columns: minmax(100px,1fr) minmax(160px,2fr); gap: .4rem 1rem; }
        .prompt-shell .prompt-workspace dd { margin: 0; overflow-wrap: anywhere; }
        .prompt-shell .prompt-workspace dt { color: var(--muted); }
        .prompt-shell .prompt-workspace pre { max-width: 100%; overflow-x: auto; white-space: pre-wrap; overflow-wrap: anywhere; }
        .prompt-shell button, .prompt-shell .btn { min-height: 44px; display: inline-flex; justify-content: center; align-items: center; padding: .65rem 1rem; background: var(--accent); color: #15120b; border: 1px solid transparent; border-radius: 10px; font: inherit; font-weight: 700; cursor: pointer; }
        .prompt-shell button:disabled { cursor: not-allowed; opacity: .5; }
        .prompt-shell button:focus-visible, .prompt-shell a:focus-visible, .prompt-shell input:focus-visible, .prompt-shell textarea:focus-visible { outline: 3px solid var(--accent-strong); outline-offset: 2px; }
        .prompt-shell [role=alert] { border-color: #f87171 !important; color: #fecaca; }
        .prompt-shell [role=status] { color: var(--accent-strong); }
        .prompt-shell [hidden] { display: none !important; }
        .prompt-shell .skip-link { position: absolute; inset-block-start: -100px; }
        .prompt-shell .skip-link:focus { inset-block-start: .5rem; z-index: 20; }
        @media (min-width:1140px) {
            .prompt-shell .prompt-workspace article dl { grid-template-columns: 110px minmax(0,1fr) 110px minmax(0,1fr); }
        }
        @media (max-width:850px) {
            .prompt-shell { grid-template-columns: minmax(0,1fr); }
            .prompt-shell .sidebar { position: static; width: 100%; height: auto; padding: .65rem var(--gutter); border-inline-end: 0; border-bottom: 1px solid var(--border); }
            .prompt-shell .main-nav { display: flex; flex-wrap: wrap; }
            .prompt-shell .nav-section { flex: 1 1 200px; }
            .prompt-shell .sidebar-footer { display: none; }
            .prompt-shell .topbar { position: static; flex-wrap: wrap; }
            .prompt-shell .prompt-workspace dl, .prompt-shell .prompt-workspace article dl { grid-template-columns: minmax(0,1fr) minmax(0,2fr); }
        }
        @media (max-width:460px) {
            .prompt-shell .topbar-actions { width: 100%; }
            .prompt-shell .topbar-actions > * { flex: 1 1 auto; }
        }
    </style>
    <script>
        try {
            document.documentElement.lang = localStorage.getItem('aiwm.locale') === 'ar' ? 'ar' : 'en';
            document.documentElement.dir = document.documentElement.lang === 'ar' ? 'rtl' : 'ltr';
        } catch (_) {}
    </script>
</head>
<body>
    <div class="app-shell prompt-shell" data-dashboard-shell="aiwmweb">
        <a href="#main-content" class="skip-link" data-prompt-ar="انتقل إلى المحتوى الرئيسي">Skip to main content</a>
        <aside class="sidebar prompt-sidebar" aria-label="Primary navigation">
            <div class="brand-row"><a class="brand" href="/tenants/{{ rawurlencode($tenant->slug) }}/"><span class="brand-mark" aria-hidden="true">AI</span><span class="brand-copy"><strong>AI WordPress Manager</strong><small>{{ $tenant->name }}</small></span></a></div>
            <nav class="main-nav" aria-label="Workspace navigation">
                <details class="nav-section" open><summary><span data-prompt-ar="الرئيسية">Home</span><span aria-hidden="true">⌄</span></summary>
                    <div class="nav-links">
                        <a href="/tenants/{{ rawurlencode($tenant->slug) }}/"><span class="nav-icon" aria-hidden="true">⌂</span><span data-prompt-ar="لوحة التحكم">Dashboard</span></a>
                        <a href="/tenants/{{ rawurlencode($tenant->slug) }}/module/overview"><span class="nav-icon" aria-hidden="true">◎</span><span data-prompt-ar="نظرة عامة">Overview</span></a>
                    </div>
                </details>
                <details class="nav-section" open><summary><span data-prompt-ar="النظام والحساب">System &amp; Account</span><span aria-hidden="true">⌄</span></summary>
                    <div class="nav-links">
                        <a href="/tenants/{{ rawurlencode($tenant->slug) }}/settings"><span class="nav-icon" aria-hidden="true">⚙</span><span data-prompt-ar="الإعدادات">Settings</span></a>
                        <a class="active" aria-current="page" href="{{ route('tenant.settings.ai-prompts', ['tenant' => $tenant->slug]) }}"><span class="nav-icon" aria-hidden="true">✦</span><span data-prompt-ar="قوالب الذكاء الاصطناعي">AI Prompt Templates</span></a>
                        <a href="/tenants/{{ rawurlencode($tenant->slug) }}/settings/ai-providers"><span class="nav-icon" aria-hidden="true">◈</span><span data-prompt-ar="مزودو الذكاء الاصطناعي">AI Providers</span></a>
                    </div>
                </details>
            </nav>
            <div class="sidebar-footer"><strong>{{ $tenant->name }}</strong><small data-prompt-ar="مساحة عمل محمية">Protected tenant workspace</small></div>
        </aside>
        <div class="main-area">
            <header class="topbar">
                <div class="topbar-title"><div class="heading-block">
                    <nav class="breadcrumb" aria-label="Breadcrumb"><a href="/tenants/{{ rawurlencode($tenant->slug) }}/settings" data-prompt-ar="الإعدادات">Settings</a><b aria-hidden="true">›</b><strong data-prompt-ar="قوالب الذكاء الاصطناعي">AI Prompt Templates</strong></nav>
                    <h1 data-prompt-ar="قوالب الذكاء الاصطناعي">AI Prompt Templates</h1><small>{{ $tenant->name }}</small>
                </div></div>
                <div class="topbar-actions">
                    <button type="button" class="language-switch" data-prompt-locale-toggle aria-label="Switch language">🌐 <span data-prompt-locale-target>العربية</span></button>
                    <a class="btn" href="/tenants/{{ rawurlencode($tenant->slug) }}/settings" data-prompt-ar="رجوع للإعدادات">Back to Settings</a>
                </div>
            </header>
            <main id="main-content" class="content prompt-content" tabindex="-1">
                <div class="workspace-stack">
                    <div class="prompt-workspace">
        <header>
            <p>AI PROMPT REGISTRY</p>
            <h2 data-prompt-ar="قوالب أوامر الذكاء الاصطناعي">AI Prompt Templates</h2>
            <p><span data-prompt-ar="عرض قوالب الأوامر المحفوظة وتعديلها ومراجعة سجل إصداراتها للحساب">Read and update persisted prompt templates and append-only revision history for</span> {{ $tenant->name }}.</p>
            <p data-prompt-ar="لمديري الإعدادات فقط. لا يُنشأ إصدار جديد عند حفظ محتوى لم يتغير.">Settings managers only. Saving an existing template creates a new revision only when persisted prompt state actually changes.</p>
            <nav aria-label="AI prompt template navigation">
                <a
                    href="/tenants/{{ rawurlencode($tenant->slug) }}/settings"
                    data-canonical-operation="AIMW-AI-E1A964346F"
                    aria-label="Back to Settings"
                ><span data-prompt-ar="← الإعدادات">← Settings</span></a>
                <button
                    type="button"
                    data-ai-prompt-new-template
                    data-canonical-operation="AIMW-AI-825B2F5A38"
                    aria-controls="new-template-editor"
                    aria-expanded="false"
                ><span data-prompt-ar="＋ قالب جديد">＋ New template</span></button>
            </nav>
        </header>

        @if (session('status'))
            <p role="status" data-ai-prompt-transient>{{ session('status') }}</p>
        @endif

        @if ($errors->any())
            <section role="alert" aria-label="Save failed" data-ai-prompt-transient>
                <h2 data-prompt-ar="فشل حفظ القالب">Prompt save failed</h2>
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </section>
        @endif

        <section
            id="new-template-editor"
            data-ai-prompt-new-editor
            data-state="{{ old('_prompt_form') === 'create' ? 'new' : 'idle' }}"
            @if(old('_prompt_form') !== 'create') hidden @endif
            aria-labelledby="new-template-heading"
        >
            <h2 id="new-template-heading" data-prompt-ar="قالب جديد">New template</h2>
            <p data-ai-prompt-new-status role="status" hidden></p>
            <p data-prompt-ar="ابدأ قالبًا جديدًا؛ لن يتم حفظ شيء إلا بعد الضغط على حفظ.">Start a new prompt template draft. No data is persisted until the separate Save operation runs.</p>
            <form method="POST" id="new-prompt-form" data-ai-prompt-create-form action="{{ route('tenant.settings.ai-prompts.create', ['tenant' => $tenant->slug]) }}">
                @csrf
                <input type="hidden" name="_prompt_form" value="create">
                <input type="hidden" name="enabled" value="0">
            <p>
                <label for="new-template-key" data-prompt-ar="مفتاح القالب">Template key</label>
                <input id="new-template-key" data-ai-prompt-new-key name="stable_key" type="text" autocomplete="off" required pattern="[a-z0-9][a-z0-9._-]{0,79}" maxlength="80" placeholder="content.example" value="{{ old('stable_key') }}">
            </p>
            <p>
                <label for="new-template-title" data-prompt-ar="العنوان">Title</label>
                <input id="new-template-title" data-ai-prompt-new-title name="title" type="text" maxlength="120" autocomplete="off" required value="{{ old('title') }}">
            </p>
            <p>
                <label for="new-template-system" data-prompt-ar="أمر النظام">System prompt</label>
                <textarea id="new-template-system" data-ai-prompt-new-system name="system_template" maxlength="20000">{{ old('system_template') }}</textarea>
            </p>
            <p>
                <label for="new-template-user" data-prompt-ar="أمر المستخدم">User prompt</label>
                <textarea id="new-template-user" data-ai-prompt-new-user name="user_template" maxlength="20000" required>{{ old('user_template') }}</textarea>
            </p>
            <p>
                <label>
                    <input data-ai-prompt-new-enabled name="enabled" type="checkbox" value="1" @checked(old('enabled', '1') === '1')>
                    <span data-prompt-ar="مفعّل">Enabled</span>
                </label>
            </p>
                <button type="submit" class="btn prompt-submit" data-ai-prompt-create-save data-prompt-ar="حفظ القالب الجديد">Save new template</button>
            </form>
        </section>

        <section aria-labelledby="template-library">
            <h2 id="template-library" data-prompt-ar="مكتبة القوالب">Template library</h2>
            <p data-prompt-template-count="{{ $templates->count() }}">{{ $templates->count() }} persisted {{ $templates->count() === 1 ? 'template' : 'templates' }}</p>

            @if ($templates->isEmpty())
                <p role="status" data-prompt-ar="لم يتم حفظ أي قوالب لهذا الحساب حتى الآن.">No AI prompt templates have been persisted for this tenant.</p>
            @else
                @foreach ($templates as $template)
                    @php
                        $failedTemplate = old('_prompt_key') === $template->stable_key;
                        $formTitle = $failedTemplate ? old('title') : $template->title;
                        $formSystem = $failedTemplate ? old('system_template') : $template->system_template;
                        $formUser = $failedTemplate ? old('user_template') : $template->user_template;
                        $formEnabled = $failedTemplate ? (string) old('enabled', '0') === '1' : $template->enabled;
                    @endphp
                    <article id="prompt-{{ $template->stable_key }}" data-template-key="{{ $template->stable_key }}">
                        <header>
                            <h3>{{ $template->title }}</h3>
                            <p>{{ $template->stable_key }} · r{{ $template->current_version }} · {{ $template->enabled ? 'Enabled' : 'Disabled' }}</p>
                        </header>

                        <dl>
                            <dt data-prompt-ar="المجال">Domain</dt><dd>{{ $template->domain }}</dd>
                            <dt data-prompt-ar="مدمج">Built in</dt><dd>{{ $template->is_builtin ? 'Yes' : 'No' }}</dd>
                            <dt data-prompt-ar="تعديل الحساب">Tenant override</dt><dd>{{ $template->allow_tenant_override ? 'Allowed' : 'Not allowed' }}</dd>
                            <dt data-prompt-ar="المتغيرات">Variables</dt><dd>{{ empty($template->variables) ? 'None' : implode(', ', $template->variables) }}</dd>
                        </dl>

                        <section aria-label="Edit prompt content for {{ $template->stable_key }}">
                            <h4 data-prompt-ar="محتوى الأمر">Prompt content</h4>
                            <form method="POST" action="{{ route('tenant.settings.ai-prompts.save', ['tenant' => $tenant->slug, 'template' => $template->stable_key]) }}">
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="_prompt_key" value="{{ $template->stable_key }}">

                                <p>
                                    <strong>Stable key:</strong> {{ $template->stable_key }}
                                    <small data-prompt-ar="مفتاح القالب ثابت ولا يمكن تغييره.">The stable key is immutable.</small>
                                </p>

                                <p>
                                    <label for="title-{{ $template->id }}" data-prompt-ar="العنوان">Title</label>
                                    <input id="title-{{ $template->id }}" name="title" type="text" maxlength="120" required value="{{ $formTitle }}">
                                </p>

                                <p>
                                    <label for="system-template-{{ $template->id }}" data-prompt-ar="أمر النظام">System prompt</label>
                                    <textarea id="system-template-{{ $template->id }}" name="system_template" maxlength="20000">{{ $formSystem }}</textarea>
                                </p>

                                <p>
                                    <label for="user-template-{{ $template->id }}" data-prompt-ar="أمر المستخدم">User prompt</label>
                                    <textarea id="user-template-{{ $template->id }}" name="user_template" maxlength="20000" required>{{ $formUser }}</textarea>
                                </p>

                                <input type="hidden" name="enabled" value="0">
                                <p>
                                    <label>
                                        <input name="enabled" type="checkbox" value="1" @checked($formEnabled)>
                                        Enabled
                                    </label>
                                </p>

                                <button type="submit" class="btn prompt-submit" data-canonical-operation="AIMW-AI-79AE29D6B3" data-prompt-ar="حفظ">Save</button>
                            </form>

                            <h5 data-prompt-ar="مخطط المخرجات">Output schema</h5>
                            @if (! empty($template->output_schema))
                                <pre>{{ json_encode($template->output_schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                            @else
                                <p data-prompt-ar="لا يوجد مخطط مخرجات محفوظ.">No output schema is stored.</p>
                            @endif
                        </section>

                        <section aria-label="Revision history for {{ $template->stable_key }}">
                            <h4 data-prompt-ar="سجل الإصدارات">Revision history</h4>
                            <p data-prompt-ar="الاستعادة لا تمحو السجل؛ بل تضيف إصدارًا جديدًا بناءً على المحتوى المحدد.">Restoring never rewrites history; it creates a new revision from the selected historical content.</p>
                            @if ($template->revisions->isEmpty())
                                <p data-prompt-ar="لا يوجد سجل إصدارات محفوظ لهذا القالب.">No revision history has been persisted for this template.</p>
                            @else
                                @foreach ($template->revisions as $revision)
                                    <article data-revision="{{ $revision->version }}">
                                        <h5>r{{ $revision->version }} · {{ $revision->change_type }}</h5>
                                        <p>{{ $revision->created_at?->toIso8601String() ?? 'Unknown time' }} · actor user #{{ $revision->actor_user_id }}</p>
                                        <pre>{{ data_get($revision->snapshot, 'user_template', '') }}</pre>
                                        <form
                                            method="POST"
                                            action="{{ route('tenant.settings.ai-prompts.restore', ['tenant' => $tenant->slug, 'template' => $template->stable_key, 'version' => $revision->version]) }}"
                                            onsubmit="return window.confirm(document.documentElement.lang === 'ar' ? 'استعادة الإصدار r{{ $revision->version }} من {{ $template->stable_key }} كإصدار جديد؟' : 'Restore revision r{{ $revision->version }} of {{ $template->stable_key }} as a new revision?');"
                                        >
                                            @csrf
                                            <button
                                                type="submit"
                                                data-ai-prompt-restore="true"
                                                @disabled((int) $revision->version === (int) $template->current_version)
                                            >Restore</button>
                                        </form>
                                    </article>
                                @endforeach
                            @endif
                        </section>
                    </article>
                @endforeach
            @endif
        </section>
                    </div>
                </div>
            </main>
        </div>
    </div>
    <script>
        // Keep Laravel's server-rendered forms while reusing the SPA locale preference.
        (() => {
            const original = new WeakMap();
            const apply = (locale) => {
                const arabic = locale === 'ar';
                document.documentElement.lang = arabic ? 'ar' : 'en';
                document.documentElement.dir = arabic ? 'rtl' : 'ltr';
                document.querySelectorAll('[data-prompt-ar]').forEach((element) => {
                    if (!original.has(element)) original.set(element, element.textContent);
                    element.textContent = arabic ? element.getAttribute('data-prompt-ar') : original.get(element);
                });
                const count = document.querySelector('[data-prompt-template-count]');
                if (count && arabic) count.textContent = count.dataset.promptTemplateCount + ' قالب محفوظ';
                if (count && !arabic && !original.has(count)) original.set(count, count.textContent);
                if (count && !arabic) count.textContent = original.get(count);
                const flash = document.querySelector('[data-ai-prompt-transient][role=status]');
                if (flash && !original.has(flash)) original.set(flash, flash.textContent);
                if (flash) {
                    const raw = original.get(flash).trim();
                    const match = raw.match(/^(Created|Saved|Restored) (.+) as revision r(\\d+)\\.$/);
                    flash.textContent = arabic && match
                        ? (match[1] === 'Created' ? 'تم إنشاء ' : match[1] === 'Saved' ? 'تم حفظ ' : 'تمت استعادة ')
                            + match[2] + ' كإصدار r' + match[3] + '.'
                        : original.get(flash);
                }
                const target = document.querySelector('[data-prompt-locale-target]');
                if (target) target.textContent = arabic ? 'EN' : 'العربية';
                const control = document.querySelector('[data-prompt-locale-toggle]');
                if (control) control.setAttribute('aria-label', arabic ? 'Switch to English' : 'التبديل إلى العربية');
            };
            apply(document.documentElement.lang === 'ar' ? 'ar' : 'en');
            document.querySelector('[data-prompt-locale-toggle]')?.addEventListener('click', () => {
                const next = document.documentElement.lang === 'ar' ? 'en' : 'ar';
                try { localStorage.setItem('aiwm.locale', next); } catch (_) {}
                apply(next);
            });
            const failedCreate = document.querySelector('[data-ai-prompt-new-editor][data-state="new"]');
            if (failedCreate) document.querySelector('[data-ai-prompt-new-template]')?.setAttribute('aria-expanded', 'true');
        })();
    </script>
    <script type="module" src="/js/ai-prompt-new-template.js"></script>
</body>
</html>