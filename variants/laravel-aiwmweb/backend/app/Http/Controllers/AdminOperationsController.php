<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Models\Site;
use App\Models\TenantMembership;
use App\Operations\AdministrationService;
use App\Operations\OperationsControlPlaneService;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AdminOperationsController extends Controller
{
    public const OPERATIONS_HUB_RELOAD_OPERATION_ID = 'AIMW-BILL-2C2CB8CBAC';

    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly AdministrationService $administration,
        private readonly OperationsControlPlaneService $operations,
        private readonly TenantContext $context,
    ) {}

    public function members(Request $request, string $tenant): JsonResponse
    {
        $this->authorizer->authorize('tenant.view');
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['sometimes', 'integer', 'in:10,25,50,100'],
        ]);

        // Existing integrations without page parameters retain the array envelope.
        // The React Application Users grid opts in to true tenant-scoped SQL pages.
        if ($request->hasAny(['page', 'per_page'])) {
            return response()->json($this->administration->paginatedMembers(
                $data['search'] ?? null,
                (int) ($data['page'] ?? 1),
                (int) ($data['per_page'] ?? 25),
            ));
        }

        return response()->json(['data' => $this->administration->members($data['search'] ?? null)]);
    }

    public function addMember(Request $request, string $tenant): JsonResponse
    {
        $this->authorizer->authorize('members.manage');
        $data = $request->validate(['email' => ['required', 'email'], 'role_id' => ['nullable', 'integer']]);

        return response()->json($this->administration->addOrInvite($data['email'], $data['role_id'] ?? null, (int) $request->user()->getAuthIdentifier()), 201);
    }

    public function updateMember(Request $request, string $tenant, int $membership): JsonResponse
    {
        $this->authorizer->authorize('members.manage');
        $data = $request->validate(['status' => ['nullable', 'string'], 'role_ids' => ['nullable', 'array'], 'role_ids.*' => ['integer']]);

        return response()->json($this->administration->updateMember($membership, $data['status'] ?? null, $data['role_ids'] ?? null, (int) $request->user()->getAuthIdentifier()));
    }

    public function removeMember(Request $request, string $tenant, int $membership): JsonResponse
    {
        $this->authorizer->authorize('members.manage');
        $this->administration->removeMember($membership, (int) $request->user()->getAuthIdentifier());

        return response()->json([], 204);
    }

    public function roles(Request $request, string $tenant): JsonResponse
    {
        $this->authorizer->authorize('tenant.view');

        // Paging parameters or search opt into real SQL-filtered pagination.
        // Role pickers without query parameters keep their legacy array response.
        if ($request->hasAny(['page', 'per_page', 'search'])) {
            $input = $request->validate([
                'search' => ['nullable', 'string', 'max:255'],
                'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
                'per_page' => ['sometimes', 'integer', 'in:10,25,50,100'],
            ]);

            return response()->json($this->administration->paginatedRoles(
                $input['search'] ?? null,
                (int) ($input['page'] ?? 1),
                (int) ($input['per_page'] ?? 25),
            ));
        }

        return response()->json(['data' => $this->administration->roles()]);
    }

    public function saveRole(Request $request, string $tenant, ?int $role = null): JsonResponse
    {
        $this->authorizer->authorize('roles.manage');
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'permissions' => ['array'], 'permissions.*' => ['string', 'max:120']]);

        return response()->json($this->administration->saveRole($role, $data['name'], $data['permissions'] ?? [], (int) $request->user()->getAuthIdentifier()), $role ? 200 : 201);
    }

    public function sessions(Request $request, string $tenant): JsonResponse|View
    {
        if ($request->expectsJson()) {
            $this->authorizer->authorize('sessions.manage');

            return response()->json([
                'data' => $this->administration->sessions(
                    (int) $request->user()->getAuthIdentifier(),
                    $this->sessionId($request),
                ),
            ]);
        }

        $this->authorizer->authorize('members.manage');
        abort_unless($this->context->tenant()->slug === $tenant, 404);

        $memberships = TenantMembership::query()
            ->where('status', 'active')
            ->with([
                'user:id,name,email',
                'roles:id,name',
            ])
            ->orderBy('id')
            ->get();

        $membersByUserId = $memberships
            ->filter(fn (TenantMembership $membership): bool => $membership->user !== null)
            ->keyBy(fn (TenantMembership $membership): int => (int) $membership->user_id);

        $userIds = $membersByUserId->keys()->all();
        $lifetimeSeconds = max(1, (int) config('session.lifetime', 120)) * 60;
        $now = now();
        $activeAfter = $now->getTimestamp() - $lifetimeSeconds;
        $currentSessionId = $this->sessionId($request);

        // Sessions are paged in SQL, not sliced from an unbounded fetched session list.
        // Only active members of the resolved tenant are included in counts and rows.
        $allowedPageSizes = [10, 25, 50, 100];
        $requestedSize = $request->query('per_page', '25');
        $perPage = is_string($requestedSize) && in_array($requestedSize, ['10', '25', '50', '100'], true)
            ? (int) $requestedSize
            : 25;
        $safePage = static function (mixed $value): int {
            return is_string($value) && preg_match('/^[1-9][0-9]{0,8}$/D', $value) === 1
                ? (int) $value
                : 1;
        };

        $sessionQuery = DB::table((string) config('session.table', 'sessions'))
            ->whereIn('user_id', $userIds)
            ->where('last_activity', '>', $activeAfter);
        $totalSessions = empty($userIds) ? 0 : (clone $sessionQuery)->count();
        $sessionPages = max(1, (int) ceil($totalSessions / $perPage));
        $sessionPage = min($safePage($request->query('page', '1')), $sessionPages);
        $rows = $totalSessions === 0
            ? collect()
            : (clone $sessionQuery)
                ->orderByDesc('last_activity')
                ->orderBy('id')
                ->forPage($sessionPage, $perPage)
                ->get([
                    'id',
                    'user_id',
                    'ip_address',
                    'user_agent',
                    'last_activity',
                ]);

        $sessions = $rows->map(function (object $row) use ($membersByUserId, $lifetimeSeconds, $currentSessionId): array {
            /** @var TenantMembership $membership */
            $membership = $membersByUserId->get((int) $row->user_id);
            $lastActivity = Carbon::createFromTimestampUTC((int) $row->last_activity);
            $role = $membership->roles
                ->pluck('name')
                ->filter()
                ->sort(SORT_NATURAL | SORT_FLAG_CASE)
                ->first() ?? 'member';

            return [
                'user_name' => (string) $membership->user->name,
                'role' => (string) $role,
                'last_activity' => $lastActivity,
                'expires_at' => $lastActivity->copy()->addSeconds($lifetimeSeconds),
                'ip_address' => filled($row->ip_address) ? (string) $row->ip_address : null,
                'user_agent' => filled($row->user_agent) ? (string) $row->user_agent : null,
                'is_current' => $currentSessionId !== null && hash_equals($currentSessionId, (string) $row->id),
            ];
        })->values();

        // Aggregate active sessions per authorized user in SQL to keep account
        // counters authoritative even when that user's sessions span many pages.
        $currentSessionUserId = $currentSessionId === null || $totalSessions === 0
            ? null
            : (clone $sessionQuery)->where('id', $currentSessionId)->value('user_id');
        $accountRows = $totalSessions === 0
            ? collect()
            : (clone $sessionQuery)->select('user_id')
                ->selectRaw('COUNT(*) AS active_sessions')
                ->groupBy('user_id')
                ->get();

        $allActiveAccounts = $accountRows
            ->map(function (object $row) use ($membersByUserId, $currentSessionUserId): array {
                /** @var TenantMembership $membership */
                $membership = $membersByUserId->get((int) $row->user_id);
                $role = $membership->roles
                    ->pluck('name')
                    ->filter()
                    ->sort(SORT_NATURAL | SORT_FLAG_CASE)
                    ->first() ?? 'member';

                return [
                    'user_name' => (string) $membership->user->name,
                    'role' => (string) $role,
                    'active_sessions' => (int) $row->active_sessions,
                    'has_current_session' => $currentSessionUserId !== null
                        && (int) $currentSessionUserId === (int) $row->user_id,
                ];
            })
            ->groupBy(fn (array $account): string => $account['user_name'].'|'.$account['role'])
            ->map(function ($group): array {
                $first = $group->first();

                return [
                    'user_name' => $first['user_name'],
                    'role' => $first['role'],
                    'active_sessions' => $group->sum('active_sessions'),
                    'has_current_session' => $group->contains(fn (array $account): bool => $account['has_current_session']),
                ];
            })
            ->sortBy(fn (array $account): string => mb_strtolower($account['user_name']))
            ->values();

        $totalActiveAccounts = $allActiveAccounts->count();
        $accountPages = max(1, (int) ceil($totalActiveAccounts / $perPage));
        $accountPage = min($safePage($request->query('accounts_page', '1')), $accountPages);
        $activeAccounts = $allActiveAccounts->forPage($accountPage, $perPage)->values();

        return view('admin.application-sessions', [
            'tenant' => $this->context->tenant(),
            'sessions' => $sessions,
            'activeAccounts' => $activeAccounts,
            'totalActiveAccounts' => $totalActiveAccounts,
            'accountPage' => $accountPage,
            'accountPages' => $accountPages,
            'totalSessions' => $totalSessions,
            'sessionPage' => $sessionPage,
            'sessionPages' => $sessionPages,
            'perPage' => $perPage,
            'allowedPageSizes' => $allowedPageSizes,
            'now' => $now,
            'operationId' => 'AIMW-CONT-6B051BD8C0',
        ]);
    }

    public function revokeSession(Request $request, string $tenant, string $session): JsonResponse
    {
        $this->authorizer->authorize('sessions.manage');
        $this->administration->revokeSession($session, (int) $request->user()->getAuthIdentifier(), $this->sessionId($request));

        return response()->json([], 204);
    }

    public function revokeOtherSessions(Request $request, string $tenant): JsonResponse
    {
        $this->authorizer->authorize('sessions.manage');

        return response()->json(['revoked' => $this->administration->revokeOtherSessions((int) $request->user()->getAuthIdentifier(), $this->sessionId($request))]);
    }

    public function platformSettings(string $tenant): JsonResponse
    {
        $this->authorizer->authorize('settings.manage');

        return response()->json(['data' => $this->administration->platformSafeSettings(), 'writable' => false]);
    }

    public function settings(Request $request, string $tenant): JsonResponse
    {
        $this->authorizer->authorize('settings.manage');
        $data = $request->validate(['scope' => ['required', 'in:tenant,site,user'], 'site_key' => ['nullable', 'string', 'max:255']]);

        return response()->json(['data' => $this->administration->settings($data['scope'], $data['site_key'] ?? null, (int) $request->user()->getAuthIdentifier())]);
    }

    public function saveSetting(Request $request, string $tenant): JsonResponse
    {
        $this->authorizer->authorize('settings.manage');
        $data = $request->validate(['scope' => ['required', 'in:tenant,site,user'], 'key' => ['required', 'string', 'max:190'], 'value' => ['present'], 'secret' => ['sometimes', 'boolean'], 'site_key' => ['nullable', 'string', 'max:255']]);

        return response()->json($this->administration->saveSetting($data['scope'], $data['key'], $data['value'], (bool) ($data['secret'] ?? false), $data['site_key'] ?? null, (int) $request->user()->getAuthIdentifier()));
    }

    public function schedules(Request $request, string $tenant): JsonResponse
    {
        $this->authorizer->authorize('operations.manage');

        // Explicit pagination is opt-in so existing integrations keep their
        // legacy data-only response with no change to schedule mutations.
        if ($request->hasAny(['page', 'per_page'])) {
            $input = $request->validate([
                'search' => ['nullable', 'string', 'max:160'],
                'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
                'per_page' => ['sometimes', 'integer', 'in:10,25,50,100'],
            ]);

            return response()->json($this->operations->paginatedSchedules(
                $input['search'] ?? null,
                (int) ($input['page'] ?? 1),
                (int) ($input['per_page'] ?? 25),
            ));
        }

        return response()->json(['data' => $this->operations->schedules()]);
    }

    public function saveSchedule(Request $request, string $tenant, ?int $task = null): JsonResponse
    {
        $this->authorizer->authorize('operations.manage');

        return response()->json($this->operations->saveSchedule($task, $request->all(), (int) $request->user()->getAuthIdentifier()), $task ? 200 : 201);
    }

    public function automations(string $tenant): JsonResponse
    {
        $this->authorizer->authorize('operations.manage');

        return response()->json(['data' => $this->operations->automations()]);
    }

    public function saveAutomation(Request $request, string $tenant, ?int $rule = null): JsonResponse
    {
        $this->authorizer->authorize('operations.manage');

        return response()->json($this->operations->saveAutomation($rule, $request->all(), (int) $request->user()->getAuthIdentifier()), $rule ? 200 : 201);
    }

    public function triggerAutomation(Request $request, string $tenant, int $rule): JsonResponse
    {
        $this->authorizer->authorize('operations.manage');

        return response()->json($this->operations->triggerAutomation($rule, (array) $request->input('payload', []), (int) $request->user()->getAuthIdentifier()));
    }

    public function approveAutomation(Request $request, string $tenant, int $run): JsonResponse
    {
        $this->authorizer->authorize('operations.manage');

        return response()->json($this->operations->approveAutomationRun($run, (int) $request->user()->getAuthIdentifier()));
    }

    public function operationsHub(string $tenant): JsonResponse
    {
        $this->authorizer->authorize('operations.manage');
        $this->authorizer->authorize('execution.view');

        $sites = Site::query()
            ->orderBy('id')
            ->get()
            ->map(static fn (Site $site): array => [
                'id' => (int) $site->getKey(),
                'name' => (string) $site->name,
                'status' => (string) $site->status,
                'connection_status' => (string) $site->connection_status,
                'health_state' => (string) $site->health_state,
                'last_verified_at' => $site->last_verified_at?->toIso8601String(),
                'last_sync_at' => $site->last_sync_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $operations = collect($this->operations->operations())
            ->map(static fn (array $operation): array => [
                'id' => (int) ($operation['id'] ?? 0),
                'type' => (string) ($operation['type'] ?? ''),
                'status' => (string) ($operation['status'] ?? ''),
                'subject_type' => $operation['subject_type'] ?? null,
                'started_at' => $operation['started_at'] ?? null,
                'completed_at' => $operation['completed_at'] ?? null,
                'updated_at' => $operation['updated_at'] ?? null,
            ])
            ->values()
            ->all();

        return response()->json([
            'data' => [
                'sites' => $sites,
                'operations' => $operations,
            ],
            'meta' => [
                'operation_id' => self::OPERATIONS_HUB_RELOAD_OPERATION_ID,
                'tenant' => $tenant,
                'refreshed_at' => now()->toIso8601String(),
            ],
        ]);
    }

    public function operations(Request $request, string $tenant): JsonResponse
    {
        $this->authorizer->authorize('operations.manage');

        // The Execution Center opts in to authoritative server paging. Existing
        // callers without paging params keep the previous legacy array shape.
        if ($request->hasAny(['page', 'per_page', 'search', 'date_from', 'date_to'])) {
            $filters = $request->validate([
                'page' => ['sometimes', 'integer', 'min:1'],
                'per_page' => ['sometimes', 'integer', 'in:10,25,50,100'],
                'search' => ['nullable', 'string', 'max:120', 'regex:/^[a-zA-Z0-9 .:_-]*$/'],
                'status' => ['nullable', 'string', 'max:40', 'regex:/^[a-z_]*$/'],
                'type' => ['nullable', 'string', 'max:80', 'regex:/^[a-zA-Z0-9._-]*$/'],
                'date_from' => ['nullable', 'date_format:Y-m-d'],
                'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            ]);

            return response()->json($this->operations->paginatedOperations(
                $filters, (int) $request->user()->getAuthIdentifier(),
            ));
        }

        return response()->json(['data' => $this->operations->operations($request->only(['status', 'type']))]);
    }

    public function operation(string $tenant, int $operation): JsonResponse
    {
        $this->authorizer->authorize('operations.manage');

        return response()->json($this->operations->operation($operation));
    }

    public function cancelOperation(Request $request, string $tenant, int $operation): JsonResponse
    {
        $this->authorizer->authorize('operations.manage');

        return response()->json($this->operations->cancelOperation($operation, (int) $request->user()->getAuthIdentifier()));
    }

    public function retryOperation(Request $request, string $tenant, int $operation): JsonResponse
    {
        $this->authorizer->authorize('operations.manage');

        return response()->json($this->operations->retryOperation($operation, (int) $request->user()->getAuthIdentifier()));
    }

    public function syncOperations(string $tenant): JsonResponse
    {
        $this->authorizer->authorize('operations.manage');

        return response()->json(['data' => $this->operations->syncOperations()]);
    }

    public function backups(string $tenant): JsonResponse
    {
        $this->authorizer->authorize('backup.manage');

        return response()->json(['data' => $this->operations->backups()]);
    }

    public function requestBackup(Request $request, string $tenant): JsonResponse
    {
        $this->authorizer->authorize('backup.manage');
        $data = $request->validate(['level' => ['required', 'in:L1,L2,L3'], 'site_key' => ['nullable', 'string', 'max:255'], 'manifest' => ['array']]);

        return response()->json($this->operations->requestBackup($data['level'], $data['site_key'] ?? null, $data['manifest'] ?? [], (int) $request->user()->getAuthIdentifier()), 201);
    }

    public function approveBackup(Request $request, string $tenant, int $backup): JsonResponse
    {
        $this->authorizer->authorize('backup.manage');

        return response()->json($this->operations->approveBackup($backup, (int) $request->user()->getAuthIdentifier()));
    }

    public function requestRestore(Request $request, string $tenant, int $backup): JsonResponse
    {
        $this->authorizer->authorize('backup.manage');

        return response()->json($this->operations->requestRestore($backup, (int) $request->user()->getAuthIdentifier()), 201);
    }

    public function approveRestore(Request $request, string $tenant, int $restore): JsonResponse
    {
        $this->authorizer->authorize('backup.manage');

        return response()->json($this->operations->approveRestore($restore, (int) $request->user()->getAuthIdentifier()));
    }

    public function logs(Request $request, string $tenant): JsonResponse
    {
        $this->authorizer->authorize('operations.manage');
        // Validate the GET contract before sending filter values to the query
        // builder. PHP query arrays and unbounded LIKE terms must fail with
        // 422 rather than triggering an unexpected server/database exception.
        $filters = $request->validate([
            'level' => ['nullable', 'string', 'max:32'],
            'correlation_id' => ['nullable', 'string', 'max:100'],
            'q' => ['nullable', 'string', 'max:160'],
            'search' => ['nullable', 'string', 'max:160'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['sometimes', 'integer', 'in:10,25,50,100'],
        ]);
        // A literal '0' is a valid search term, not an empty value. Preserve
        // explicit q precedence over the legacy search alias.
        if ((! isset($filters['q']) || (string) $filters['q'] === '')
            && isset($filters['search']) && (string) $filters['search'] !== '') {
            $filters['q'] = $filters['search'];
        }
        unset($filters['search']);
        // Only explicit pagination opts in; keep the legacy bounded 500-row
        // response for existing integrations that do not request pages.
        $paged = $request->hasAny(['page', 'per_page']);
        $page = $paged ? (int) ($filters['page'] ?? 1) : null;
        $perPage = $paged ? (int) ($filters['per_page'] ?? 25) : null;
        unset($filters['page'], $filters['per_page']);

        return response()->json($this->operations->logsWithEvidence($filters, $page, $perPage));
    }

    public function diagnostics(string $tenant): JsonResponse
    {
        $this->authorizer->authorize('operations.manage');

        return response()->json($this->operations->diagnostics());
    }

    public function report(string $tenant, string $report): JsonResponse
    {
        $this->authorizer->authorize('reports.manage');

        return response()->json($this->operations->report($report));
    }

    public function queueExport(Request $request, string $tenant): JsonResponse
    {
        $this->authorizer->authorize('reports.manage');
        $data = $request->validate(['report_type' => ['required', 'string'], 'filters' => ['array'], 'format' => ['sometimes', 'in:csv']]);

        return response()->json($this->operations->queueExport($data['report_type'], $data['filters'] ?? [], $data['format'] ?? 'csv', (int) $request->user()->getAuthIdentifier()), 202);
    }

    public function export(string $tenant, int $export): JsonResponse
    {
        $this->authorizer->authorize('reports.manage');

        return response()->json($this->operations->export($export));
    }

    public function downloadExport(string $tenant, int $export): StreamedResponse
    {
        $this->authorizer->authorize('reports.manage');
        $record = $this->operations->export($export);
        abort_unless(($record['status'] ?? null) === 'succeeded' && ! empty($record['file_path']), 409, 'Export is not ready.');
        abort_unless(Storage::disk('local')->exists($record['file_path']), 404);

        return Storage::disk('local')->download($record['file_path'], 'aimw-'.$record['report_type'].'-'.$export.'.csv');
    }

    private function sessionId(Request $request): ?string
    {
        return $request->hasSession() ? $request->session()->getId() : null;
    }
}
