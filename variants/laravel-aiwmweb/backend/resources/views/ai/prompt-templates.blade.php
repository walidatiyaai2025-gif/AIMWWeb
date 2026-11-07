@php($ar = $locale === 'ar')
<!doctype html>
<html lang="{{ $ar ? 'ar' : 'en' }}" dir="{{ $ar ? 'rtl' : 'ltr' }}" data-mode="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="dark light">
    <title>{{ $ar ? 'قوالب أوامر الذكاء الاصطناعي' : 'AI Prompt Templates' }} — AI WordPress Manager</title>
    @vite(['resources/css/app.css'])
    <!-- Google Fonts: Inter (Latin) and Tajawal (Arabic); typography 25% larger than the 62.5% baseline. -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&amp;family=Tajawal:wght@400;500;700;800;900&amp;display=swap">
    <style data-aiwm-prompt-dashboard>
        /* Blade workspace uses the same app-shell / palette / typography as React. */
        .prompt-workspace .prompt-content { display: grid; gap: 1rem; }
        .prompt-workspace .prompt-content > section { min-width: 0; }
        .prompt-workspace .prompt-hero { min-width: 0; }
        .prompt-workspace .prompt-hero nav { display: flex; flex-wrap: wrap; align-items: center; gap: .6rem; margin-top: .9rem; }
        .prompt-workspace .prompt-hero p { margin: .4rem 0; }
        .prompt-workspace .prompt-hero h1 { margin: .15rem 0 .5rem; }
        .prompt-workspace .prompt-content .panel { padding: clamp(1rem,2vw,1.35rem); }
        .prompt-workspace .prompt-article { padding: 1rem; background: var(--surface2); border: 1px solid var(--border); border-radius: var(--radius-sm); margin: 1rem 0; }
        .prompt-workspace .prompt-article > header { display: flex; align-items: center; justify-content: space-between; gap: .6rem; flex-wrap: wrap; border-bottom: 1px solid var(--border); padding-bottom: .6rem; }
        .prompt-workspace .prompt-article > section { padding: .8rem 0; border-bottom: 1px solid var(--border); }
        .prompt-workspace .prompt-article > section:last-child { border-bottom: 0; }
        .prompt-workspace .prompt-article p, .prompt-workspace .prompt-article dd { overflow-wrap: anywhere; }
        .prompt-workspace .prompt-fields { display: grid; grid-template-columns: repeat(2,minmax(0,1fr)); gap: .7rem; }
        .prompt-workspace .prompt-fields > p { margin: 0; min-width: 0; }
        .prompt-workspace .prompt-fields label:not(:has(input[type="checkbox"])) { display: block; margin-bottom: .35rem; font-weight: 700; }
        .prompt-workspace .prompt-fields p:has(textarea), .prompt-workspace .prompt-fields p:has(input[type="checkbox"]), .prompt-workspace .prompt-fields > .prompt-full { grid-column: 1 / -1; }
        .prompt-workspace .prompt-fields input[type="checkbox"] { width: auto; min-height: 1rem; margin-inline-end: .4rem; }
        .prompt-workspace .prompt-content dl { display: grid; grid-template-columns: minmax(6rem,1fr) minmax(0,2fr); gap: .5rem; }
        .prompt-workspace .prompt-content dt { color: var(--muted); }
        .prompt-workspace .prompt-content dd { margin: 0; }
        .prompt-workspace .prompt-content pre { padding: .8rem; overflow-x: auto; background: var(--bg); border: 1px solid var(--border); border-radius: .6rem; white-space: pre-wrap; overflow-wrap: anywhere; }
        .prompt-workspace .prompt-status { padding: .9rem 1rem; border: 1px solid var(--success); border-radius: .7rem; background: var(--success-soft); }
        .prompt-workspace .prompt-alert { padding: .9rem 1rem; border: 1px solid var(--danger); border-radius: .7rem; background: var(--danger-soft); }
        .prompt-workspace .prompt-revision { border: 1px solid var(--border); background: var(--surface); border-radius: .7rem; padding: .8rem; margin: .75rem 0; }
        .prompt-workspace .prompt-subtle { color: var(--muted); }
        .prompt-workspace .prompt-nav-open { display: none; }
        .prompt-workspace .prompt-brand { display: inline-flex; align-items: center; gap: .6rem; font-weight: 800; }
        .prompt-workspace .prompt-actions { display: flex; gap: .6rem; flex-wrap: wrap; margin-top: .6rem; }
        .prompt-workspace .prompt-status[hidden], .prompt-workspace section[hidden] { display: none !important; }
        @media (max-width:700px) {
            .prompt-workspace .prompt-fields { grid-template-columns: minmax(0,1fr); }
            .prompt-workspace .prompt-nav-open { display: inline-flex; }
            .prompt-workspace .topbar .prompt-brand { display: none; }
            .prompt-workspace .topbar-actions { flex-wrap: wrap; }
        }
    </style>
</head>
<body class="prompt-workspace">
    <a class="skip-link" href="#main-content">{{ $ar ? 'تجاوز إلى المحتوى' : 'Skip to content' }}</a>
    <div class="app-shell" data-ai-prompt-dashboard-shell="true">
        <aside class="sidebar" id="ai-prompt-sidebar" aria-label="{{ $ar ? 'قائمة لوحة التحكم' : 'Dashboard navigation' }}">
            <div class="brand-row">
                <a class="brand" href="/tenants/{{ rawurlencode($tenant->slug) }}/module/overview">
                    <span class="brand-mark" aria-hidden="true">AI</span>
                    <span class="brand-copy"><strong>AI WordPress Manager</strong><small>{{ $tenant->name }}</small></span>
                </a>
                <button class="mobile-close" type="button" data-ai-prompt-nav-close aria-label="{{ $ar ? 'إغلاق القائمة' : 'Close navigation' }}">×</button>
            </div>
            <nav class="main-nav" aria-label="{{ $ar ? 'مساحات العمل' : 'Workspaces' }}">
                <details class="nav-section" open>
                    <summary>{{ $ar ? 'الرئيسية' : 'Overview' }}</summary>
                    <div class="nav-links">
                        <a href="/tenants/{{ rawurlencode($tenant->slug) }}/module/overview"><span class="nav-icon" aria-hidden="true">⌂</span><span>{{ $ar ? 'لوحة التحكم' : 'Dashboard' }}</span></a>
                    </div>
                </details>
                <details class="nav-section" open>
                    <summary>{{ $ar ? 'الذكاء الاصطناعي' : 'AI' }}</summary>
                    <div class="nav-links">
                        <a class="active" aria-current="page" href="{{ route('tenant.settings.ai-prompts', ['tenant' => $tenant->slug]) }}"><span class="nav-icon" aria-hidden="true">✦</span><span>{{ $ar ? 'قوالب الأوامر' : 'Prompt templates' }}</span></a>
                        <a href="{{ route('tenant.settings.ai-providers', ['tenant' => $tenant->slug]) }}"><span class="nav-icon" aria-hidden="true">◎</span><span>{{ $ar ? 'مزودو الذكاء الاصطناعي' : 'AI providers' }}</span></a>
                    </div>
                </details>
                <details class="nav-section" open>
                    <summary>{{ $ar ? 'النظام والحساب' : 'System & account' }}</summary>
                    <div class="nav-links">
                        <a href="/tenants/{{ rawurlencode($tenant->slug) }}/settings"><span class="nav-icon" aria-hidden="true">⚙</span><span>{{ $ar ? 'الإعدادات' : 'Settings' }}</span></a>
                    </div>
                </details>
            </nav>
            <footer class="sidebar-footer"><strong>{{ $tenant->name }}</strong><small>{{ $ar ? 'مساحة عمل آمنة لهذا الحساب' : 'Tenant-scoped workspace' }}</small></footer>
        </aside>
        <button class="nav-backdrop" type="button" data-ai-prompt-nav-close aria-label="{{ $ar ? 'إغلاق القائمة' : 'Close navigation' }}"></button>
        <div class="main-area">
            <header class="topbar">
                <button class="icon-button prompt-nav-open" type="button" data-ai-prompt-nav-open aria-controls="ai-prompt-sidebar" aria-expanded="false" aria-label="{{ $ar ? 'فتح القائمة' : 'Open navigation' }}">☰</button>
                <div class="topbar-title">
                    <div class="heading-block">
                        <div class="breadcrumb"><span>{{ $ar ? 'الذكاء الاصطناعي' : 'AI' }}</span><b> / </b><strong>{{ $ar ? 'قوالب الأوامر' : 'Prompt templates' }}</strong></div>
                        <h1>{{ $ar ? 'قوالب أوامر الذكاء الاصطناعي' : 'AI Prompt Templates' }}</h1>
                    </div>
                </div>
                <div class="topbar-actions">
                    <a class="btn" href="{{ route('tenant.settings.ai-prompts', ['tenant' => $tenant->slug, 'lang' => $ar ? 'en' : 'ar']) }}" data-ai-prompt-locale="{{ $ar ? 'en' : 'ar' }}" aria-label="{{ $ar ? 'Switch to English' : 'التبديل إلى العربية' }}">{{ $ar ? 'EN' : 'عربي' }}</a>
                    <a class="btn" href="/tenants/{{ rawurlencode($tenant->slug) }}/settings">{{ $ar ? 'الإعدادات' : 'Settings' }}</a>
                </div>
            </header>
            <main class="content prompt-content" id="main-content">

        <header>
            <p>AI PROMPT REGISTRY</p>
            <h1>AI Prompt Templates</h1>
            <p>Read and update persisted prompt templates and append-only revision history for {{ $tenant->name }}.</p>
            <p>Settings managers only. Saving an existing template creates a new revision only when persisted prompt state actually changes.</p>
            <nav aria-label="AI prompt template navigation">
                <a
                    href="/tenants/{{ rawurlencode($tenant->slug) }}/settings"
                    data-canonical-operation="AIMW-AI-E1A964346F"
                    aria-label="Back to Settings"
                >← Settings</a>
                <button
                    type="button"
                    data-ai-prompt-new-template
                    data-canonical-operation="AIMW-AI-825B2F5A38"
                    aria-controls="new-template-editor"
                    aria-expanded="false"
                >＋ New template</button>
            </nav>
        </header>

        @if (session('status'))
            <p role="status" data-ai-prompt-transient>{{ session('status') }}</p>
        @endif

        @if ($errors->any())
            <section role="alert" aria-label="Save failed" data-ai-prompt-transient>
                <h2>Prompt save failed</h2>
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </section>
        @endif

        @php($newFailed = old('_prompt_key') === '__new__')
        <section
            class="panel"
            id="new-template-editor"
            data-ai-prompt-new-editor
            data-state="{{ $newFailed ? 'new' : 'idle' }}"
            @if (! $newFailed) hidden @endif
            aria-labelledby="new-template-heading"
        >
            <h2 id="new-template-heading">{{ $ar ? 'قالب جديد' : 'New template' }}</h2>
            <p data-ai-prompt-new-status role="status" @if (! $newFailed) hidden @endif>{{ $newFailed ? ($ar ? 'راجع البيانات وصحّح الخطأ ثم احفظ.' : 'Review the validation error and save again.') : '' }}</p>
            <p class="prompt-subtle">{{ $ar ? 'ابدأ مسودة قالب جديد. لا تُحفظ أي بيانات حتى تضغط حفظ.' : 'Start a new prompt template draft. No data is persisted until the separate Save operation runs.' }}</p>
            <form method="POST" action="{{ route('tenant.settings.ai-prompts.create', ['tenant' => $tenant->slug]) }}" data-ai-prompt-create-form>
                @csrf
                <input type="hidden" name="_prompt_key" value="__new__">
                <div class="prompt-fields">
                    <p>
                        <label for="new-template-key">{{ $ar ? 'مفتاح القالب' : 'Template key' }}</label>
                        <input id="new-template-key" data-ai-prompt-new-key name="stable_key" type="text" autocomplete="off" maxlength="80" required pattern="[a-z0-9][a-z0-9._-]{0,79}" placeholder="content.rewrite" value="{{ $newFailed ? old('stable_key') : '' }}">
                    </p>
                    <p>
                        <label for="new-template-title">{{ $ar ? 'العنوان' : 'Title' }}</label>
                        <input id="new-template-title" data-ai-prompt-new-title name="title" type="text" maxlength="120" autocomplete="off" required value="{{ $newFailed ? old('title') : '' }}">
                    </p>
                    <p>
                        <label for="new-template-system">{{ $ar ? 'تعليمات النظام' : 'System prompt' }}</label>
                        <textarea id="new-template-system" data-ai-prompt-new-system name="system_template" maxlength="20000">{{ $newFailed ? old('system_template') : '' }}</textarea>
                    </p>
                    <p>
                        <label for="new-template-user">{{ $ar ? 'تعليمات المستخدم' : 'User prompt' }}</label>
                        <textarea id="new-template-user" data-ai-prompt-new-user name="user_template" maxlength="20000" required>{{ $newFailed ? old('user_template') : '' }}</textarea>
                    </p>
                    <p class="prompt-full">
                        <input type="hidden" name="enabled" value="0">
                        <label><input data-ai-prompt-new-enabled name="enabled" type="checkbox" value="1" @checked(! $newFailed || (string) old('enabled', '0') === '1')>
                        {{ $ar ? 'مفعّل' : 'Enabled' }}</label>
                    </p>
                    <div class="prompt-full prompt-actions">
                        <button class="btn primary" type="submit" data-ai-prompt-create-save>{{ $ar ? 'حفظ القالب الجديد' : 'Save new template' }}</button>
                    </div>
                </div>
            </form>
        </section>

        <section aria-labelledby="template-library">
            <h2 id="template-library">Template library</h2>
            <p>{{ $templates->count() }} persisted {{ $templates->count() === 1 ? 'template' : 'templates' }}</p>

            @if ($templates->isEmpty())
                <p role="status">No AI prompt templates have been persisted for this tenant.</p>
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
                            <h4>Prompt content</h4>
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

                                <button type="submit" data-canonical-operation="AIMW-AI-79AE29D6B3">Save</button>
                            </form>

                            <h5>Output schema</h5>
                            @if (! empty($template->output_schema))
                                <pre>{{ json_encode($template->output_schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                            @else
                                <p>No output schema is stored.</p>
                            @endif
                        </section>

                        <section aria-label="Revision history for {{ $template->stable_key }}">
                            <h4>Revision history</h4>
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
            </main>
        </div>
    </div>
    <script type="module" src="/js/ai-prompt-new-template.js"></script>
    <script type="module" src="/js/ai-prompt-shell.js"></script>
</body>
</html>