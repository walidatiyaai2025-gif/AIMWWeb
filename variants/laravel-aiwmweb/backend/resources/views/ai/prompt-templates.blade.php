<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AI Prompt Templates</title>
</head>
<body>
    <main>
        <header>
            <p>AI PROMPT REGISTRY</p>
            <h1>AI Prompt Templates</h1>
            <p>Read and update persisted prompt templates and append-only revision history for {{ $tenant->name }}.</p>
            <p>Settings managers only. Saving an existing template creates a new revision only when persisted prompt state actually changes.</p>
        </header>

        @if (session('status'))
            <p role="status">{{ session('status') }}</p>
        @endif

        @if ($errors->any())
            <section role="alert" aria-label="Save failed">
                <h2>Prompt save failed</h2>
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </section>
        @endif

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
                            @if ($template->revisions->isEmpty())
                                <p>No revision history has been persisted for this template.</p>
                            @else
                                @foreach ($template->revisions as $revision)
                                    <article data-revision="{{ $revision->version }}">
                                        <h5>r{{ $revision->version }} · {{ $revision->change_type }}</h5>
                                        <p>{{ $revision->created_at?->toIso8601String() ?? 'Unknown time' }} · actor user #{{ $revision->actor_user_id }}</p>
                                        <pre>{{ data_get($revision->snapshot, 'user_template', '') }}</pre>
                                    </article>
                                @endforeach
                            @endif
                        </section>
                    </article>
                @endforeach
            @endif
        </section>
    </main>
</body>
</html>
