<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Models\TenantMembership;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class ApplicationSessionsReadController extends Controller
{
    public const OPERATION_ID = 'AIMW-CONT-6B051BD8C0';

    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(Request $request, string $tenant): View
    {
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
        $currentSessionId = $request->hasSession() ? $request->session()->getId() : null;

        $rows = empty($userIds)
            ? collect()
            : DB::table((string) config('session.table', 'sessions'))
                ->whereIn('user_id', $userIds)
                ->where('last_activity', '>', $activeAfter)
                ->orderByDesc('last_activity')
                ->orderBy('id')
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

        $activeAccounts = $sessions
            ->groupBy(fn (array $session): string => $session['user_name'].'|'.$session['role'])
            ->map(function ($group): array {
                $first = $group->first();

                return [
                    'user_name' => $first['user_name'],
                    'role' => $first['role'],
                    'active_sessions' => $group->count(),
                    'has_current_session' => $group->contains(fn (array $session): bool => $session['is_current']),
                ];
            })
            ->sortBy(fn (array $account): string => mb_strtolower($account['user_name']))
            ->values();

        return view('admin.application-sessions', [
            'tenant' => $this->context->tenant(),
            'sessions' => $sessions,
            'activeAccounts' => $activeAccounts,
            'now' => $now,
            'operationId' => self::OPERATION_ID,
        ]);
    }
}
