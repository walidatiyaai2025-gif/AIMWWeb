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
    <link rel="stylesheet" href="{{ asset('css/ai-prompt-dashboard.css') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&amp;family=Tajawal:wght@400;500;700;800&amp;display=swap">
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
            <p>Read and update persisted prompt templates and append-only revision history for {{ $tenant->name }}.</p>
            <p>Settings managers only. Saving an existing template creates a new revision only when persisted prompt state actually changes.</p>
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
                            <dt>Domain</dt><dd>{{ $template->domain }}</dd>
                            <dt>Built in</dt><dd>{{ $template->is_builtin ? 'Yes' : 'No' }}</dd>
                            <dt>Tenant override</dt><dd>{{ $template->allow_tenant_override ? 'Allowed' : 'Not allowed' }}</dd>
                            <dt>Variables</dt><dd>{{ empty($template->variables) ? 'None' : implode(', ', $template->variables) }}</dd>
                        </dl>

                        <section aria-label="Edit prompt content for {{ $template->stable_key }}">
                            <h4 data-prompt-ar="محتوى الأمر">Prompt content</h4>
                            <form method="POST" action="{{ route('tenant.settings.ai-prompts.save', ['tenant' => $tenant->slug, 'template' => $template->stable_key]) }}">
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="_prompt_key" value="{{ $template->stable_key }}">

                                <p>
                                    <strong>Stable key:</strong> {{ $template->stable_key }}
                                    <small>The stable key is immutable.</small>
                                </p>

                                <p>
                                    <label for="title-{{ $template->id }}">Title</label>
                                    <input id="title-{{ $template->id }}" name="title" type="text" maxlength="120" required value="{{ $formTitle }}">
                                </p>

                                <p>
                                    <label for="system-template-{{ $template->id }}">System prompt</label>
                                    <textarea id="system-template-{{ $template->id }}" name="system_template" maxlength="20000">{{ $formSystem }}</textarea>
                                </p>

                                <p>
                                    <label for="user-template-{{ $template->id }}">User prompt</label>
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
                                <p>No output schema is stored.</p>
                            @endif
                        </section>

                        <section aria-label="Revision history for {{ $template->stable_key }}">
                            <h4 data-prompt-ar="سجل الإصدارات">Revision history</h4>
                            <p>Restoring never rewrites history; it creates a new revision from the selected historical content.</p>
                            @if ($template->revisions->isEmpty())
                                <p>No revision history has been persisted for this template.</p>
                            @else
                                @foreach ($template->revisions as $revision)
                                    <article data-revision="{{ $revision->version }}">
                                        <h5>r{{ $revision->version }} · {{ $revision->change_type }}</h5>
                                        <p>{{ $revision->created_at?->toIso8601String() ?? 'Unknown time' }} · actor user #{{ $revision->actor_user_id }}</p>
                                        <pre>{{ data_get($revision->snapshot, 'user_template', '') }}</pre>
                                        <form
                                            method="POST"
                                            action="{{ route('tenant.settings.ai-prompts.restore', ['tenant' => $tenant->slug, 'template' => $template->stable_key, 'version' => $revision->version]) }}"
                                            onsubmit="return window.confirm('Restore revision r{{ $revision->version }} of {{ $template->stable_key }} as a new revision?');"
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
    <script type="module" src="/js/ai-prompt-new-template.js"></script>
</body>
</html>