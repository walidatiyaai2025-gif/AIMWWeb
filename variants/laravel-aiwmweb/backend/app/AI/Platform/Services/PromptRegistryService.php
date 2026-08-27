<?php

namespace App\AI\Platform\Services;

use App\AI\Platform\Enums\AiFailureKind;
use App\AI\Platform\Exceptions\AiPlatformException;
use App\Models\AiPromptRevision;
use App\Models\AiPromptTemplate;
use App\Services\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

final class PromptRegistryService
{
    public const KEY_PATTERN = '/^[a-z0-9][a-z0-9._-]{0,79}$/';

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function all(bool $includeDisabled = true): array
    {
        $this->seedBuiltIns();

        return AiPromptTemplate::query()
            ->when(! $includeDisabled, fn ($query) => $query->where('enabled', true))
            ->orderBy('domain')
            ->orderBy('stable_key')
            ->get()
            ->map(fn (AiPromptTemplate $template) => $this->serialize($template))
            ->all();
    }

    public function get(string $key, bool $requireEnabled = true): AiPromptTemplate
    {
        $this->seedBuiltIns();
        $query = AiPromptTemplate::query()->where('stable_key', $key);
        if ($requireEnabled) {
            $query->where('enabled', true);
        }

        return $query->firstOrFail();
    }

    public function save(?AiPromptTemplate $template, array $input, string $changeType = 'updated'): AiPromptTemplate
    {
        $actorUserId = $this->context->membership()->user_id;
        $creating = $template === null;

        if ($creating) {
            $key = trim((string) ($input['stable_key'] ?? ''));
            if (! preg_match(self::KEY_PATTERN, $key)) {
                throw ValidationException::withMessages(['stable_key' => 'Prompt key is invalid.']);
            }
            $template = new AiPromptTemplate([
                'stable_key' => $key,
                'current_version' => 0,
                'is_builtin' => false,
                'allow_tenant_override' => true,
            ]);
        } elseif (isset($input['stable_key']) && $input['stable_key'] !== $template->stable_key) {
            throw ValidationException::withMessages(['stable_key' => 'Prompt stable ID cannot be changed.']);
        }

        if ($template->is_builtin && ! $template->allow_tenant_override) {
            throw ValidationException::withMessages(['stable_key' => 'This built-in prompt does not allow tenant overrides.']);
        }

        $template->fill(Arr::only($input, [
            'domain',
            'title',
            'system_template',
            'user_template',
            'variables',
            'output_schema',
            'enabled',
        ]));
        $template->domain = trim((string) ($template->domain ?: 'general'));
        $template->title = trim((string) ($template->title ?: $template->stable_key));
        $template->user_template = trim((string) $template->user_template);
        if ($template->user_template === '') {
            throw ValidationException::withMessages(['user_template' => 'User prompt template is required.']);
        }
        $template->updated_by_user_id = $actorUserId;

        $snapshot = $this->snapshot($template);
        $last = $template->exists
            ? AiPromptRevision::query()->where('ai_prompt_template_id', $template->id)->latest('version')->first()
            : null;
        if ($last && $last->snapshot === $snapshot) {
            return $template->fresh();
        }

        $template->current_version = (int) $template->current_version + 1;
        $template->save();
        $this->revision($template, $changeType, $actorUserId);

        $this->audit->record('ai.prompt.changed', [
            'stable_key' => $template->stable_key,
            'version' => $template->current_version,
            'change_type' => $changeType,
        ], 'AiPromptTemplate', $template->id);

        return $template->fresh();
    }

    public function setEnabled(AiPromptTemplate $template, bool $enabled): AiPromptTemplate
    {
        return $this->save($template, [
            'enabled' => $enabled,
            'domain' => $template->domain,
            'title' => $template->title,
            'system_template' => $template->system_template,
            'user_template' => $template->user_template,
            'variables' => $template->variables ?? [],
            'output_schema' => $template->output_schema,
        ], $enabled ? 'enabled' : 'disabled');
    }

    public function restore(AiPromptTemplate $template, int $version): AiPromptTemplate
    {
        $revision = AiPromptRevision::query()
            ->where('ai_prompt_template_id', $template->id)
            ->where('version', $version)
            ->firstOrFail();

        return $this->save($template, $revision->snapshot, 'restored');
    }

    public function history(AiPromptTemplate $template): array
    {
        return AiPromptRevision::query()
            ->where('ai_prompt_template_id', $template->id)
            ->orderByDesc('version')
            ->get()
            ->map(fn (AiPromptRevision $revision) => [
                'version' => $revision->version,
                'change_type' => $revision->change_type,
                'actor_user_id' => $revision->actor_user_id,
                'created_at' => $revision->created_at?->toIso8601String(),
                'snapshot' => $revision->snapshot,
            ])
            ->all();
    }

    public function render(string $key, array $variables): array
    {
        $template = $this->get($key);
        $declared = array_values(array_map('strval', $template->variables ?? []));
        foreach ($declared as $variable) {
            if (! array_key_exists($variable, $variables)) {
                throw new AiPlatformException(
                    AiFailureKind::PolicyRejection,
                    "Prompt variable '{$variable}' is required.",
                    false,
                    422,
                );
            }
        }

        $unknown = array_diff(array_keys($variables), $declared);
        if ($unknown !== []) {
            throw new AiPlatformException(
                AiFailureKind::PolicyRejection,
                'Prompt contains undeclared variables: '.implode(', ', $unknown),
                false,
                422,
            );
        }

        $replace = [];
        foreach ($variables as $name => $value) {
            $replace['{{'.$name.'}}'] = is_scalar($value) || $value === null
                ? (string) $value
                : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }

        return [
            'template' => $template,
            'system' => $template->system_template ? strtr($template->system_template, $replace) : null,
            'user' => strtr($template->user_template, $replace),
            'output_schema' => $template->output_schema,
        ];
    }

    public function serialize(AiPromptTemplate $template): array
    {
        return [
            'id' => $template->id,
            'stable_key' => $template->stable_key,
            'version' => $template->current_version,
            'domain' => $template->domain,
            'title' => $template->title,
            'system_template' => $template->system_template,
            'user_template' => $template->user_template,
            'variables' => $template->variables ?? [],
            'output_schema' => $template->output_schema,
            'enabled' => $template->enabled,
            'is_builtin' => $template->is_builtin,
            'allow_tenant_override' => $template->allow_tenant_override,
            'updated_at' => $template->updated_at?->toIso8601String(),
        ];
    }

    private function revision(AiPromptTemplate $template, string $changeType, int $actorUserId): void
    {
        AiPromptRevision::query()->create([
            'ai_prompt_template_id' => $template->id,
            'version' => $template->current_version,
            'snapshot' => $this->snapshot($template),
            'change_type' => $changeType,
            'actor_user_id' => $actorUserId,
            'created_at' => now(),
        ]);
    }

    private function snapshot(AiPromptTemplate $template): array
    {
        return [
            'domain' => $template->domain,
            'title' => $template->title,
            'system_template' => $template->system_template,
            'user_template' => $template->user_template,
            'variables' => $template->variables ?? [],
            'output_schema' => $template->output_schema,
            'enabled' => (bool) $template->enabled,
        ];
    }

    private function seedBuiltIns(): void
    {
        foreach ($this->builtIns() as $definition) {
            if (AiPromptTemplate::query()->where('stable_key', $definition['stable_key'])->exists()) {
                continue;
            }

            $template = AiPromptTemplate::query()->create([
                ...$definition,
                'current_version' => 1,
                'updated_by_user_id' => $this->context->membership()->user_id,
            ]);
            $this->revision($template, 'builtin_seed', $this->context->membership()->user_id);
        }
    }

    private function builtIns(): array
    {
        return [
            [
                'stable_key' => 'content.rewrite',
                'domain' => 'content',
                'title' => 'Rewrite content',
                'system_template' => 'Rewrite while preserving factual meaning. Never publish automatically.',
                'user_template' => '{{content}}',
                'variables' => ['content'],
                'output_schema' => null,
                'enabled' => true,
                'is_builtin' => true,
                'allow_tenant_override' => true,
            ],
            [
                'stable_key' => 'content.brief',
                'domain' => 'planner',
                'title' => 'Content brief',
                'system_template' => 'Create a practical content brief. Return JSON only.',
                'user_template' => "Title: {{title}}\nIdea: {{idea}}\nKeywords: {{keywords}}\nTopics: {{topics}}",
                'variables' => ['title', 'idea', 'keywords', 'topics'],
                'output_schema' => [
                    'type' => 'object',
                    'required' => ['brief', 'outline'],
                    'additionalProperties' => false,
                    'properties' => [
                        'brief' => ['type' => 'string', 'minLength' => 1],
                        'outline' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                        ],
                    ],
                ],
                'enabled' => true,
                'is_builtin' => true,
                'allow_tenant_override' => true,
            ],
            [
                'stable_key' => 'content.draft',
                'domain' => 'planner',
                'title' => 'Content draft',
                'system_template' => 'Write a complete HTML draft from the approved brief. Do not publish automatically. Return JSON only.',
                'user_template' => "Title: {{title}}\nBrief: {{brief}}\nOutline: {{outline}}",
                'variables' => ['title', 'brief', 'outline'],
                'output_schema' => [
                    'type' => 'object',
                    'required' => ['draft_html'],
                    'additionalProperties' => false,
                    'properties' => [
                        'draft_html' => ['type' => 'string', 'minLength' => 1],
                    ],
                ],
                'enabled' => true,
                'is_builtin' => true,
                'allow_tenant_override' => true,
            ],
            [
                'stable_key' => 'system.safety',
                'domain' => 'system',
                'title' => 'AI safety constraints',
                'system_template' => null,
                'user_template' => 'Never expose secrets and never claim an external action succeeded without verification.',
                'variables' => [],
                'output_schema' => null,
                'enabled' => true,
                'is_builtin' => true,
                'allow_tenant_override' => false,
            ],
        ];
    }
}
