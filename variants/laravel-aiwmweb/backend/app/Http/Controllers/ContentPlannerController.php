<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Models\AuditEvent;
use App\Models\ContentPlannerItem;
use App\Models\IdempotencyKey;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ContentPlannerController extends Controller
{
    public const SAVE_OPERATION_ID = 'AIMW-BILL-2805622F94';

    private const IDEMPOTENCY_OPERATION = 'content-planner.save';

    public function __construct(private readonly TenantAuthorizer $authorizer) {}

    public function index(string $tenant): JsonResponse
    {
        $this->authorizer->authorize('content.view');

        $items = ContentPlannerItem::query()
            ->with('site:id,name')
            ->latest('updated_at')
            ->latest('id')
            ->get()
            ->map(fn (ContentPlannerItem $item): array => $this->snapshot($item))
            ->values();

        return response()->json(['data' => $items]);
    }

    public function save(Request $request, string $tenant): JsonResponse
    {
        $this->authorizer->authorize('content.view');
        $this->authorizer->authorize('content.edit');

        $data = $request->validate([
            'id' => ['nullable', 'integer', 'min:1'],
            'site_id' => ['nullable', 'integer', 'min:1'],
            'title' => ['required', 'string', 'max:255'],
            'idea' => ['nullable', 'string', 'max:10000'],
            'scheduled_at' => ['nullable', 'date'],
            'tenant_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'created_by_user_id' => ['prohibited'],
            'actor_user_id' => ['prohibited'],
        ]);

        $idempotencyKey = trim((string) $request->header('Idempotency-Key', ''));
        abort_if(strlen($idempotencyKey) > 128, 422, 'Idempotency-Key must not exceed 128 characters.');

        $siteId = array_key_exists('site_id', $data) && $data['site_id'] !== null ? (int) $data['site_id'] : null;
        if ($siteId !== null) {
            Site::query()->findOrFail($siteId);
        }

        $scheduledAt = empty($data['scheduled_at'])
            ? null
            : CarbonImmutable::parse((string) $data['scheduled_at'])->utc();
        $normalized = [
            'id' => isset($data['id']) ? (int) $data['id'] : null,
            'site_id' => $siteId,
            'title' => trim((string) $data['title']),
            'idea' => array_key_exists('idea', $data) ? trim((string) ($data['idea'] ?? '')) : null,
            'scheduled_at' => $scheduledAt?->toIso8601String(),
        ];
        abort_if($normalized['title'] === '', 422, 'Title is required.');

        $requestHash = hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));

        $result = DB::transaction(function () use ($request, $normalized, $scheduledAt, $idempotencyKey, $requestHash): array {
            $keyRecord = null;
            if ($idempotencyKey !== '') {
                $keyRecord = IdempotencyKey::query()
                    ->where('operation', self::IDEMPOTENCY_OPERATION)
                    ->where('key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($keyRecord) {
                    abort_unless(hash_equals((string) $keyRecord->request_hash, $requestHash), 409, 'Idempotency key was already used with a different request.');
                    if ($keyRecord->completed_at && is_array($keyRecord->response)) {
                        return $keyRecord->response;
                    }
                } else {
                    $keyRecord = IdempotencyKey::query()->create([
                        'key' => $idempotencyKey,
                        'operation' => self::IDEMPOTENCY_OPERATION,
                        'request_hash' => $requestHash,
                    ]);
                }
            }

            $item = $normalized['id'] !== null
                ? ContentPlannerItem::query()->lockForUpdate()->findOrFail($normalized['id'])
                : new ContentPlannerItem;

            $creating = ! $item->exists;
            $item->site_id = $normalized['site_id'];
            $item->title = $normalized['title'];
            $item->idea = $normalized['idea'] === '' ? null : $normalized['idea'];
            $item->scheduled_at = $scheduledAt;
            if ($creating) {
                $item->created_by_user_id = (int) $request->user()->getAuthIdentifier();
            }
            $item->save();

            $persisted = ContentPlannerItem::query()->with('site:id,name')->findOrFail((int) $item->getKey());
            $response = [
                'operation_id' => self::SAVE_OPERATION_ID,
                'mutation' => $creating ? 'created' : 'updated',
                'data' => $this->snapshot($persisted),
            ];

            AuditEvent::query()->create([
                'actor_user_id' => (int) $request->user()->getAuthIdentifier(),
                'event' => $creating ? 'content_planner.created' : 'content_planner.updated',
                'subject_type' => 'content_planner_item',
                'subject_id' => (string) $persisted->getKey(),
                'metadata' => [
                    'operation_id' => self::SAVE_OPERATION_ID,
                    'site_id' => $persisted->site_id,
                    'scheduled' => $persisted->scheduled_at !== null,
                ],
                'occurred_at' => now(),
            ]);

            if ($keyRecord) {
                $keyRecord->response = $response;
                $keyRecord->completed_at = now();
                $keyRecord->save();
            }

            return $response;
        }, 3);

        $authoritative = ContentPlannerItem::query()->with('site:id,name')->findOrFail((int) $result['data']['id']);
        $snapshot = $this->snapshot($authoritative);
        abort_unless(
            $snapshot['title'] === $result['data']['title']
            && $snapshot['site_id'] === $result['data']['site_id']
            && $snapshot['idea'] === $result['data']['idea']
            && $snapshot['scheduled_at'] === $result['data']['scheduled_at'],
            409,
            'Persisted planner state did not reconcile after save.',
        );

        $result['data'] = $snapshot;

        return response()->json($result, $result['mutation'] === 'created' ? 201 : 200);
    }

    /** @return array<string, mixed> */
    private function snapshot(ContentPlannerItem $item): array
    {
        return [
            'id' => (int) $item->getKey(),
            'site_id' => $item->site_id === null ? null : (int) $item->site_id,
            'site_name' => $item->site?->name,
            'title' => (string) $item->title,
            'idea' => $item->idea,
            'scheduled_at' => $item->scheduled_at?->utc()->toIso8601String(),
            'updated_at' => $item->updated_at?->utc()->toIso8601String(),
        ];
    }
}
