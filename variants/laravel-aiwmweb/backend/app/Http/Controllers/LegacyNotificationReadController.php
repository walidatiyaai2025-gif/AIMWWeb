<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Models\InAppNotification;
use App\Models\TenantMembership;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LegacyNotificationReadController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantAuthorizer $authorizer,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return $this->withinSelectedTenant($request, function () use ($request): JsonResponse {
            $this->authorizer->authorize('execution.view');

            // The current AIMWWeb compatibility endpoint deliberately ignores caller-provided userId.
            // Ownership always comes from the authenticated membership selected below.
            $unreadOnly = $request->boolean('unreadOnly');
            $take = min(max((int) $request->query('take', 100), 1), 500);
            $userId = $this->context->membership()->user_id;

            // Legacy callers still receive the original bounded array. Explicit
            // paging opts in to the real SQL paginator and authoritative totals.
            $query = InAppNotification::query()
                ->where('user_id', $userId)
                ->when($unreadOnly, fn ($query) => $query->whereNull('read_at'))
                ->orderByDesc('created_at')
                ->orderByDesc('id');

            $serialize = static fn (InAppNotification $notification): array => [
                    'id' => $notification->id,
                    'notification_id' => $notification->notification_id,
                    'category' => $notification->category,
                    'severity' => $notification->severity,
                    'source' => $notification->source,
                    'title' => $notification->title,
                    'message' => $notification->message,
                    'deep_link' => $notification->deep_link,
                    'mandatory' => (bool) $notification->mandatory,
                    'locale' => $notification->locale,
                    'delivery_mode' => $notification->delivery_mode,
                    'read_at' => $notification->read_at?->toIso8601String(),
                    'created_at' => $notification->created_at?->toIso8601String(),
                ];

            if ($request->hasAny(['page', 'per_page'])) {
                $filters = $request->validate([
                    'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
                    'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
                ]);
                $perPage = (int) ($filters['per_page'] ?? 25);
                $requestedPage = (int) ($filters['page'] ?? 1);
                // Count first, so a stale/oversized page never triggers an
                // unnecessarily large SQL OFFSET before last-page recovery.
                $total = (clone $query)->count();
                $lastPage = max(1, (int) ceil($total / $perPage));
                $page = min($requestedPage, $lastPage);
                $notifications = $query->paginate($perPage, ['*'], 'page', $page);
                $notifications->setCollection($notifications->getCollection()->map($serialize));

                return response()->json($notifications);
            }

            return response()->json($query->limit($take)->get()->map($serialize)->values()->all());
        });
    }

    private function withinSelectedTenant(Request $request, Closure $callback): JsonResponse
    {
        $user = $request->user();
        abort_unless($user, 401);

        $memberships = TenantMembership::query()
            ->withoutGlobalScopes()
            ->where('user_id', $user->getAuthIdentifier())
            ->where('status', 'active')
            ->with('tenant')
            ->get();

        $requestedSlug = trim((string) $request->query('tenant', ''));
        if ($requestedSlug !== '') {
            $membership = $memberships->first(
                static fn (TenantMembership $candidate): bool => $candidate->tenant?->slug === $requestedSlug,
            );
            abort_unless($membership?->tenant, 404);
        } else {
            if ($memberships->count() !== 1) {
                return response()->json([
                    'message' => 'An explicit tenant selector is required for this canonical API.',
                    'code' => 'TENANT_SELECTION_REQUIRED',
                ], 409);
            }
            $membership = $memberships->first();
        }

        $this->context->activate($membership->tenant, $membership);
        $request->attributes->set('tenant_id', (int) $membership->tenant->getKey());

        try {
            return $callback();
        } finally {
            $this->context->forget();
        }
    }
}
