<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AI Prompt Templates</title>
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
</style>
</head>
<body>
    <main>
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

        <section
            id="new-template-editor"
            data-ai-prompt-new-editor
            data-state="idle"
            hidden
            aria-labelledby="new-template-heading"
        >
            <h2 id="new-template-heading">New template</h2>
            <p data-ai-prompt-new-status role="status" hidden></p>
            <p>Start a new prompt template draft. No data is persisted until the separate Save operation runs.</p>
            <p>
                <label for="new-template-key">Template key</label>
                <input id="new-template-key" data-ai-prompt-new-key name="new_stable_key" type="text" autocomplete="off" placeholder="content.rewrite">
            </p>
            <p>
                <label for="new-template-title">Title</label>
                <input id="new-template-title" data-ai-prompt-new-title name="new_title" type="text" maxlength="120" autocomplete="off">
            </p>
            <p>
                <label for="new-template-system">System prompt</label>
                <textarea id="new-template-system" data-ai-prompt-new-system name="new_system_template" maxlength="20000"></textarea>
            </p>
            <p>
                <label for="new-template-user">User prompt</label>
                <textarea id="new-template-user" data-ai-prompt-new-user name="new_user_template" maxlength="20000"></textarea>
            </p>
            <p>
                <label>
                    <input data-ai-prompt-new-enabled name="new_enabled" type="checkbox" checked>
                    Enabled
                </label>
            </p>
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
    <script type="module" src="/js/ai-prompt-new-template.js"></script>
</body>
</html>