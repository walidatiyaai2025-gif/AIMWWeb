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
            <p>Read the persisted prompt registry and append-only revision history for {{ $tenant->name }}.</p>
            <p>Settings managers only. This view does not create, edit, restore, or seed prompt data.</p>
        </header>

        <section aria-labelledby="template-library">
            <h2 id="template-library">Template library</h2>
            <p>{{ $templates->count() }} persisted {{ $templates->count() === 1 ? 'template' : 'templates' }}</p>

            @if ($templates->isEmpty())
                <p role="status">No AI prompt templates have been persisted for this tenant.</p>
            @else
                @foreach ($templates as $template)
                    <article data-template-key="{{ $template->stable_key }}">
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

                        <section aria-label="Prompt content for {{ $template->stable_key }}">
                            <h4>Prompt content</h4>
                            <h5>System prompt</h5>
                            @if (filled($template->system_template))
                                <pre>{{ $template->system_template }}</pre>
                            @else
                                <p>No system prompt is stored.</p>
                            @endif
                            <h5>User prompt</h5>
                            <pre>{{ $template->user_template }}</pre>
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
