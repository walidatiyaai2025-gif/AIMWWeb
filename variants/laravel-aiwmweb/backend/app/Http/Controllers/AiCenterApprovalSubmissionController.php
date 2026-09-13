<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Models\Approval;
use App\Models\Site;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AiCenterApprovalSubmissionController extends Controller
{
    public const OPERATION_ID = 'AIMW-AI-93EBFDE5A1';

    public function __invoke(Request $request, TenantAuthorizer $authorizer): JsonResponse
    {
        $authorizer->authorize('ai.use');

        $data = $request->validate([
            'request_key' => ['required', 'uuid'],
            'site_id' => ['nullable', 'integer', 'min:1'],
            'operation_type' => ['nullable', 'string', 'max:191'],
            'title' => ['nullable', 'string', 'max:255'],
            'risk_level' => ['nullable', 'in:Low,Medium,High,Critical'],
            'before_content' => ['present', 'nullable', 'string'],
            'after_content' => ['required', 'string', 'max:2000000'],
            'prompt_key' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'explanation' => ['nullable', 'string', 'max:10000'],
            'confidence' => ['nullable', 'numeric', 'between:0,1'],
            'affected_fields' => ['nullable', 'array', 'max:100'],
            'affected_fields.*' => ['string', 'max:255'],
        ]);

        $existing = Approval::query()
            ->where('source_operation_id', self::OPERATION_ID)
            ->where('request_key', $data['request_key'])
            ->first();
        if ($existing !== null) {
            return response()->json(['data' => $this->serialize($existing)]);
        }

        $site = null;
        if (isset($data['site_id'])) {
            $site = Site::query()->findOrFail((int) $data['site_id']);
        }

        $affectedFields = array_values(array_unique(array_map(
            static fn (mixed $field): string => trim((string) $field),
            $data['affected_fields'] ?? [],
        )));
        $affectedFields = array_values(array_filter($affectedFields, static fn (string $field): bool => $field !== ''));

        $promptKey = trim((string) ($data['prompt_key'] ?? ''));
        $model = trim((string) ($data['model'] ?? ''));
        $operationType = trim((string) ($data['operation_type'] ?? '')) ?: 'AI.ContentUpdate';
        $title = trim((string) ($data['title'] ?? '')) ?: 'AI content proposal';
        $actorLabel = trim((string) ($request->user()->name ?? ''));
        if ($actorLabel === '') {
            $actorLabel = (string) $request->user()->getKey();
        }

        $attributes = [
            'suggestion_id' => null,
            'site_id' => $site?->getKey(),
            'site_name' => $site?->name,
            'actor_user_id' => $request->user()->getKey(),
            'status' => 'PENDING',
            'source_operation_id' => self::OPERATION_ID,
            'operation_type' => $operationType,
            'title' => $title,
            'actor_label' => $actorLabel,
            'risk_level' => $data['risk_level'] ?? 'High',
            'request_key' => $data['request_key'],
            'before_state' => [
                'Content' => (string) ($data['before_content'] ?? ''),
                'PromptKey' => $promptKey,
                'Model' => $model,
                'AffectedFields' => $affectedFields,
            ],
            'proposed_state' => [
                'Content' => (string) $data['after_content'],
                'Explanation' => (string) ($data['explanation'] ?? ''),
                'Confidence' => isset($data['confidence']) ? (float) $data['confidence'] : null,
                'AffectedFields' => $affectedFields,
                'PromptKey' => $promptKey,
                'Model' => $model,
            ],
        ];

        try {
            $approval = Approval::query()->create($attributes);
        } catch (QueryException $exception) {
            $approval = Approval::query()
                ->where('source_operation_id', self::OPERATION_ID)
                ->where('request_key', $data['request_key'])
                ->first();
            if ($approval === null) {
                throw $exception;
            }

            return response()->json(['data' => $this->serialize($approval)]);
        }

        return response()->json(['data' => $this->serialize($approval)], 201);
    }

    private function serialize(Approval $approval): array
    {
        return [
            'id' => (int) $approval->id,
            'status' => (string) $approval->status,
            'site_id' => $approval->site_id !== null ? (int) $approval->site_id : null,
            'site_name' => $approval->site_name,
            'operation_type' => $approval->operation_type,
            'title' => $approval->title,
            'risk_level' => $approval->risk_level,
            'created_at' => $approval->created_at?->toISOString(),
            'updated_at' => $approval->updated_at?->toISOString(),
        ];
    }
}
