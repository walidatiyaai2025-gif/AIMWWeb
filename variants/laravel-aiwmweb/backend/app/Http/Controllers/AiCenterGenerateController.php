<?php

namespace App\Http\Controllers;

use App\AI\Platform\Contracts\AiGenerator;
use App\AI\Platform\Exceptions\AiPlatformException;
use App\Authorization\TenantAuthorizer;
use App\Models\AiPromptTemplate;
use App\Models\Site;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AiCenterGenerateController extends Controller
{
    public const OPERATION_ID = 'AIMW-AI-DDB072FE15';

    public function __invoke(
        Request $request,
        TenantAuthorizer $authorizer,
        AiGenerator $generator,
    ): JsonResponse {
        $authorizer->authorize('ai.use');

        $input = $request->validate([
            'content' => ['required', 'string', 'max:200000'],
            'prompt_key' => ['nullable', 'string', 'max:80'],
            'model' => ['nullable', 'string', 'max:160'],
            'temperature' => ['nullable', 'numeric', 'between:0,1'],
            'max_output_tokens' => ['nullable', 'integer', 'between:100,8000'],
            'site_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $content = trim((string) $input['content']);
        if ($content === '') {
            return response()->json([
                'message' => 'Content is required.',
                'errors' => ['content' => ['Content is required.']],
            ], 422);
        }

        $siteId = isset($input['site_id']) ? (int) $input['site_id'] : null;
        if ($siteId !== null) {
            Site::query()->findOrFail($siteId);
        }

        $promptKey = trim((string) ($input['prompt_key'] ?? ''));
        $template = null;
        if ($promptKey !== '') {
            $template = AiPromptTemplate::query()
                ->where('stable_key', $promptKey)
                ->where('enabled', true)
                ->first();
            if (! $template) {
                throw (new ModelNotFoundException)->setModel(AiPromptTemplate::class, [$promptKey]);
            }
        }

        $systemPrompt = $this->systemPrompt($template);
        $workflow = $promptKey === '' ? 'ai.suggestion' : 'ai.suggestion:'.$promptKey;

        try {
            $result = $generator->generate([
                'workflow' => $workflow,
                'system_prompt' => $systemPrompt,
                'user_prompt' => $content,
                'output_schema' => $this->suggestionSchema(),
                'model' => filled($input['model'] ?? null) ? trim((string) $input['model']) : null,
                'temperature' => (float) ($input['temperature'] ?? 0.2),
                'max_output_tokens' => (int) ($input['max_output_tokens'] ?? 1500),
                'site_id' => $siteId,
            ]);
        } catch (AiPlatformException $exception) {
            $response = [
                'message' => $exception->getMessage(),
                'code' => $exception->kind->value,
                'retryable' => $exception->retryable,
            ];
            if ($exception->retryAfterSeconds !== null) {
                $response['retry_after_seconds'] = $exception->retryAfterSeconds;
            }

            return response()->json($response, $exception->httpStatus ?? 502);
        }

        $suggestion = is_array($result['structured'] ?? null) ? $result['structured'] : [];

        return response()->json([
            'data' => [
                'operation_id' => self::OPERATION_ID,
                'before' => $content,
                'after' => (string) ($suggestion['after'] ?? ''),
                'explanation' => (string) ($suggestion['explanation'] ?? ''),
                'confidence' => (float) ($suggestion['confidence'] ?? 0),
                'affected_fields' => array_values(array_map('strval', (array) ($suggestion['affected_fields'] ?? []))),
                'provider' => (string) $result['provider'],
                'model' => (string) $result['model'],
                'correlation_id' => (string) $result['correlation_id'],
                'prompt_key' => $promptKey === '' ? null : $promptKey,
                'site_id' => $siteId,
            ],
        ]);
    }

    private function systemPrompt(?AiPromptTemplate $template): string
    {
        $base = trim((string) ($template?->system_template ?? ''));
        $contract = <<<'PROMPT'
Return exactly one reviewable JSON suggestion. Preserve factual meaning and never claim that an external action was executed. The JSON fields are: after (non-empty proposed content), explanation (non-empty rationale), confidence (number from 0 to 1), and affected_fields (array of changed field names). Do not include secrets, credentials, tokens, or markdown fences.
PROMPT;

        return $base === '' ? $contract : $base."\n\n".$contract;
    }

    /** @return array<string, mixed> */
    private function suggestionSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['after', 'explanation', 'confidence', 'affected_fields'],
            'additionalProperties' => false,
            'properties' => [
                'after' => ['type' => 'string', 'minLength' => 1],
                'explanation' => ['type' => 'string', 'minLength' => 1],
                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'affected_fields' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
            ],
        ];
    }
}
