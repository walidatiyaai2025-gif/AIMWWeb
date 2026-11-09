<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class SecurityAuditReadController
{
    public const OPERATION_ID = 'AIMW-BILL-A152D6A7DE';

    public const SETTINGS_NAVIGATION_OPERATION_ID = 'AIMW-CONT-16D09482D4';

    public function __invoke(Request $request): View
    {
        $input = $request->validate([
            'category' => ['nullable', 'string', 'max:40'],
            'outcome' => ['nullable', 'string', 'max:20'],
            'q' => ['nullable', 'string', 'max:200'],
            'take' => ['nullable', 'integer', 'min:1', 'max:200'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['sometimes', 'integer', 'in:10,25,50,100'],
            'tenant_id' => ['prohibited'],
            'actor_user_id' => ['prohibited'],
        ]);

        $category = trim((string) ($input['category'] ?? ''));
        $outcome = trim((string) ($input['outcome'] ?? ''));
        $search = trim((string) ($input['q'] ?? ''));
        // Legacy `take` is only for the filtered, bounded compatibility view.
        // It must not bypass the canonical 10/25/50/100 SQL page-size contract.
        $perPage = (int) ($input['per_page'] ?? 25);
        // No implicit 200-match truncation on paginated filtered requests.
        // Legacy consumers can still explicitly limit their results with take.
        $take = isset($input['take']) ? (int) $input['take'] : null;
        $page = (int) ($input['page'] ?? 1);
        $isFiltered = $category !== '' || $outcome !== '' || $search !== '';
        // Only these two classification filters can be expressed exactly in
        // portable, tenant-scoped SQL today. The q/take compatibility window
        // remains visibly bounded rather than implying full-dataset search.
        $isSqlFiltered = $isFiltered && $search === '' && $take === null;
        $total = 0;
        $lastPage = 1;
        $filteredMatchCount = 0;

        $tenantIds = DB::table('tenant_memberships')
            ->where('user_id', (int) $request->user()->getAuthIdentifier())
            ->where('status', 'active')
            ->pluck('tenant_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $settingsHref = $this->settingsHref($request, $tenantIds);

        $query = $tenantIds === [] ? null : DB::table('audit_events')
            ->leftJoin('users', 'users.id', '=', 'audit_events.actor_user_id')
            ->join('tenants', 'tenants.id', '=', 'audit_events.tenant_id')
            ->whereIn('audit_events.tenant_id', $tenantIds)
            ->orderByDesc('audit_events.occurred_at')
            ->orderByDesc('audit_events.id');

        $columns = [
            'audit_events.id', 'audit_events.tenant_id', 'audit_events.actor_user_id',
            'audit_events.event', 'audit_events.subject_type', 'audit_events.subject_id',
            'audit_events.metadata', 'audit_events.occurred_at', 'users.name as actor_name',
            'users.email as actor_email', 'tenants.name as tenant_name', 'tenants.slug as tenant_slug',
        ];

        if (! $isFiltered || $isSqlFiltered) {
            if ($isSqlFiltered && $query !== null) {
                if ($category !== '') {
                    $query->whereRaw('('.$this->categorySql().') = ?', [mb_strtolower($category)]);
                }
                if ($outcome !== '') {
                    $query->whereRaw('('.$this->outcomeSql().') = ?', [mb_strtolower($outcome)]);
                }
            }
            // Both unfiltered and classification-only views use the same
            // authoritative SQL COUNT and stable SQL page, never a truncated
            // list of the most recent 1,000 records.
            $total = $query === null ? 0 : (clone $query)->count('audit_events.id');
            $lastPage = max(1, (int) ceil($total / $perPage));
            $page = min($page, $lastPage);
            $events = $query === null ? collect() : $query->forPage($page, $perPage)->get($columns)
                ->map(fn ($row): array => $this->snapshot($row));
        } else {
            // Legacy derived category/outcome/metadata-text search is not yet SQL
            // paginated. Keep its semantics (including secret-safe projection)
            // and explicitly disclose its latest-1000 ceiling in the UI.
            $filteredCandidates = $query === null ? collect() : $query->limit(1000)->get($columns)
                ->map(fn ($row): array => $this->snapshot($row))
                ->filter(fn (array $row): bool => $category === '' || strcasecmp($row['category'], $category) === 0)
                ->filter(fn (array $row): bool => $outcome === '' || strcasecmp($row['outcome'], $outcome) === 0)
                ->filter(function (array $row) use ($search): bool {
                    if ($search === '') {
                        return true;
                    }
                    $haystack = mb_strtolower(implode(' ', [$row['event'], $row['actor'], $row['target'], $row['tenant'], $row['metadata_text']]));

                    return str_contains($haystack, mb_strtolower($search));
                })
                ->when($take !== null, fn ($matches) => $matches->take($take))
                ->values();
            // This is a count only within the newest 1,000 authorized events and
            // the optional legacy take bound, never a global matching total.
            $filteredMatchCount = $filteredCandidates->count();
            $lastPage = max(1, (int) ceil($filteredMatchCount / $perPage));
            $page = min($page, $lastPage);
            $events = $filteredCandidates->forPage($page, $perPage)->values();
        }

        return view('security.audit', compact('events', 'category', 'outcome', 'search', 'take', 'settingsHref', 'page', 'perPage', 'total', 'lastPage', 'isFiltered', 'filteredMatchCount') + [
            'canonicalOperationId' => self::OPERATION_ID,
            'settingsNavigationOperationId' => self::SETTINGS_NAVIGATION_OPERATION_ID,
            'isSqlFiltered' => $isSqlFiltered,
        ]);
    }

    /**
     * Extract only the fixed allowlisted metadata keys needed for classification.
     * Never search or expose arbitrary metadata JSON; invalid historical JSON
     * has the same fallback behavior as the PHP snapshot projection.
     */
    private function safeJsonText(string $key): string
    {
        // All callers use hardcoded internal keys; no user-supplied JSON path.
        $path = "'$.". $key ."'";
        $extract = DB::getDriverName() === 'mysql'
            ? "JSON_UNQUOTE(JSON_EXTRACT(audit_events.metadata, {$path}))"
            : "json_extract(audit_events.metadata, {$path})";

        return "(CASE WHEN JSON_VALID(audit_events.metadata) THEN {$extract} ELSE NULL END)";
    }

    private function categorySql(): string
    {
        $raw = 'LOWER(TRIM('.$this->safeJsonText('category').'))';
        $event = 'LOWER(COALESCE(audit_events.event, \'\'))';

        // The allowlisted metadata category overrides event-derived classes.
        // Fallthrough precedence must mirror category() exactly.
        return "CASE WHEN {$raw} IN ('authentication','account','authorization','session','configuration') THEN {$raw}
            WHEN {$event} LIKE '%session%' THEN 'session'
            WHEN {$event} LIKE '%permission%' OR {$event} LIKE '%role%' OR {$event} LIKE '%authoriz%' THEN 'authorization'
            WHEN {$event} LIKE '%config%' OR {$event} LIKE '%setting%' OR {$event} LIKE '%provider%' OR {$event} LIKE '%connector%' THEN 'configuration'
            WHEN {$event} LIKE '%login%' OR {$event} LIKE '%auth%' THEN 'authentication'
            ELSE 'account' END";
    }

    private function outcomeSql(): string
    {
        $driver = DB::getDriverName();
        $type = $driver === 'mysql' ? 'JSON_TYPE' : 'json_type';
        $stringType = $driver === 'mysql' ? 'STRING' : 'text';
        $outcomeType = "{$type}(audit_events.metadata, '$.outcome')";
        $statusType = "{$type}(audit_events.metadata, '$.status')";
        $outcomeValue = $this->safeJsonText('outcome');
        $statusValue = $this->safeJsonText('status');
        // If outcome is a string, even an unrecognized string takes precedence
        // over status, as in the snapshot projection's legacy PHP contract.
        $raw = "(CASE WHEN JSON_VALID(audit_events.metadata) THEN
            CASE WHEN {$outcomeType} = '{$stringType}' THEN {$outcomeValue}
                 WHEN {$statusType} = '{$stringType}' THEN {$statusValue}
                 ELSE NULL END ELSE NULL END)";
        $value = "LOWER(TRIM({$raw}))";
        $event = 'LOWER(COALESCE(audit_events.event, \'\'))';

        return "CASE WHEN {$value} IN ('failed','failure','error') THEN 'failed'
            WHEN {$value} IN ('blocked','denied','forbidden') THEN 'blocked'
            WHEN {$value} IN ('success','succeeded','ok','completed') THEN 'succeeded'
            WHEN {$event} LIKE '%failed%' OR {$event} LIKE '%error%' THEN 'failed'
            WHEN {$event} LIKE '%blocked%' OR {$event} LIKE '%denied%' OR {$event} LIKE '%forbidden%' THEN 'blocked'
            ELSE 'succeeded' END";
    }

    /**
     * The platform-admin audit route has no tenant route parameter. Only expose
     * Settings navigation when the authenticated user has exactly one active
     * tenant membership and that membership can actually view Settings. This
     * operation is navigation-only and never mutates the active tenant.
     *
     * @param  list<int>  $tenantIds
     */
    private function settingsHref(Request $request, array $tenantIds): ?string
    {
        if (count($tenantIds) !== 1) {
            return null;
        }

        $tenantId = (int) $tenantIds[0];
        $membership = DB::table('tenant_memberships')
            ->where('tenant_id', $tenantId)
            ->where('user_id', (int) $request->user()->getAuthIdentifier())
            ->where('status', 'active')
            ->first(['id']);

        if ($membership === null) {
            return null;
        }

        $canViewSettings = DB::table('role_tenant_membership as rtm')
            ->join('permission_role as pr', 'pr.role_id', '=', 'rtm.role_id')
            ->join('permissions as p', 'p.id', '=', 'pr.permission_id')
            ->where('rtm.tenant_membership_id', (int) $membership->id)
            ->where('rtm.tenant_id', $tenantId)
            ->where('pr.tenant_id', $tenantId)
            ->whereIn('p.name', ['tenant.view', '*'])
            ->exists();

        if ($canViewSettings === false) {
            return null;
        }

        $slug = DB::table('tenants')->where('id', $tenantId)->value('slug');
        if (is_string($slug) === false || trim((string) $slug) === '') {
            return null;
        }

        return '/tenants/'.rawurlencode($slug).'/settings';
    }

    private function snapshot(object $row): array
    {
        $decoded = is_array($row->metadata) ? $row->metadata : json_decode((string) $row->metadata, true);
        $metadata = is_array($decoded) ? $decoded : [];

        return [
            'id' => (int) $row->id,
            'tenant' => (string) ($row->tenant_name ?: $row->tenant_slug),
            'event' => (string) $row->event,
            'category' => $this->category((string) $row->event, $metadata),
            'outcome' => $this->outcome((string) $row->event, $metadata),
            'actor' => $this->actor($row),
            'target' => $this->target($row),
            'occurred_at' => (string) $row->occurred_at,
            'metadata_text' => $this->metadataText($metadata),
        ];
    }

    private function category(string $event, array $metadata): string
    {
        // Legacy metadata is untrusted and may contain a secret-bearing category.
        $explicit = $metadata['category'] ?? null;
        if (is_string($explicit)) {
            foreach (['Authentication', 'Account', 'Authorization', 'Session', 'Configuration'] as $allowed) {
                if (strcasecmp(trim($explicit), $allowed) === 0) {
                    return $allowed;
                }
            }
        }
        $value = mb_strtolower($event);
        if (str_contains($value, 'session')) {
            return 'Session';
        }
        if (str_contains($value, 'permission') || str_contains($value, 'role') || str_contains($value, 'authoriz')) {
            return 'Authorization';
        }
        if (str_contains($value, 'config') || str_contains($value, 'setting') || str_contains($value, 'provider') || str_contains($value, 'connector')) {
            return 'Configuration';
        }
        if (str_contains($value, 'login') || str_contains($value, 'auth')) {
            return 'Authentication';
        }

        return 'Account';
    }

    private function outcome(string $event, array $metadata): string
    {
        $rawValue = $metadata['outcome'] ?? null;
        if (! is_string($rawValue)) {
            $rawValue = $metadata['status'] ?? null;
        }
        $raw = mb_strtolower(trim(is_string($rawValue) ? $rawValue : ''));
        if (in_array($raw, ['failed', 'failure', 'error'], true)) {
            return 'Failed';
        }
        if (in_array($raw, ['blocked', 'denied', 'forbidden'], true)) {
            return 'Blocked';
        }
        if (in_array($raw, ['success', 'succeeded', 'ok', 'completed'], true)) {
            return 'Succeeded';
        }
        $value = mb_strtolower($event);
        if (str_contains($value, 'failed') || str_contains($value, 'error')) {
            return 'Failed';
        }
        if (str_contains($value, 'blocked') || str_contains($value, 'denied') || str_contains($value, 'forbidden')) {
            return 'Blocked';
        }

        return 'Succeeded';
    }

    private function actor(object $row): string
    {
        $name = trim((string) ($row->actor_name ?? ''));
        $email = trim((string) ($row->actor_email ?? ''));
        if ($name !== '' && $email !== '') {
            return $name.' <'.$email.'>';
        }

        return $name !== '' ? $name : ($email !== '' ? $email : 'System / unknown');
    }

    private function target(object $row): string
    {
        $type = trim((string) ($row->subject_type ?? ''));
        $id = trim((string) ($row->subject_id ?? ''));
        if ($type === '' && $id === '') {
            return '—';
        }

        return $id === '' ? $type : ($type === '' ? 'Target' : $type).': '.$id;
    }

    /**
     * Safe presentation-only projection. Never serialize arbitrary audit
     * metadata, nested provider responses, credentials or exception messages.
     * The original evidence stays unchanged in its protected database row.
     */
    private function metadataText(array $metadata): string
    {
        $parts = [];
        $category = $metadata['category'] ?? null;
        if (is_string($category)) {
            foreach (['Authentication', 'Account', 'Authorization', 'Session', 'Configuration'] as $allowed) {
                if (strcasecmp(trim($category), $allowed) === 0) {
                    $parts[] = 'category='.$allowed;
                    break;
                }
            }
        }

        foreach (['outcome', 'status'] as $key) {
            $raw = $metadata[$key] ?? null;
            if (! is_string($raw)) {
                continue;
            }

            $normalized = strtolower(trim($raw));
            $safe = match ($normalized) {
                'success', 'succeeded', 'ok', 'completed' => 'Succeeded',
                'failure', 'failed', 'error' => 'Failed',
                'blocked', 'denied', 'forbidden' => 'Blocked',
                default => null,
            };
            if ($safe !== null) {
                $parts[] = $key.'='.$safe;
            }
        }

        $ip = $metadata['ip'] ?? null;
        if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
            $parts[] = 'ip='.$ip;
        }

        foreach (['count', 'duration_ms'] as $key) {
            $value = $metadata[$key] ?? null;
            if (is_int($value) && $value >= 0 && $value <= 1000000000) {
                $parts[] = $key.'='.$value;
            } elseif (is_string($value) && preg_match('/^(?:0|[1-9][0-9]{0,9})$/D', $value) === 1
                && (float) $value <= 1000000000) {
                $parts[] = $key.'='.(int) $value;
            }
        }

        return implode(' · ', $parts);
    }
}
