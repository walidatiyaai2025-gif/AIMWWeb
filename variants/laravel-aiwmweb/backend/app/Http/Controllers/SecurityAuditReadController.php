<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class SecurityAuditReadController
{
    public const OPERATION_ID = 'AIMW-BILL-A152D6A7DE';

    public function __invoke(Request $request): View
    {
        $input = $request->validate([
            'category' => ['nullable', 'string', 'max:40'],
            'outcome' => ['nullable', 'string', 'max:20'],
            'q' => ['nullable', 'string', 'max:200'],
            'take' => ['nullable', 'integer', 'min:1', 'max:200'],
            'tenant_id' => ['prohibited'],
            'actor_user_id' => ['prohibited'],
        ]);

        $category = trim((string) ($input['category'] ?? ''));
        $outcome = trim((string) ($input['outcome'] ?? ''));
        $search = trim((string) ($input['q'] ?? ''));
        $take = (int) ($input['take'] ?? 200);

        $tenantIds = DB::table('tenant_memberships')
            ->where('user_id', (int) $request->user()->getAuthIdentifier())
            ->where('status', 'active')
            ->pluck('tenant_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $events = $tenantIds === [] ? collect() : DB::table('audit_events')
            ->leftJoin('users', 'users.id', '=', 'audit_events.actor_user_id')
            ->join('tenants', 'tenants.id', '=', 'audit_events.tenant_id')
            ->whereIn('audit_events.tenant_id', $tenantIds)
            ->orderByDesc('audit_events.occurred_at')
            ->orderByDesc('audit_events.id')
            ->limit(1000)
            ->get([
                'audit_events.id','audit_events.tenant_id','audit_events.actor_user_id',
                'audit_events.event','audit_events.subject_type','audit_events.subject_id',
                'audit_events.metadata','audit_events.occurred_at','users.name as actor_name',
                'users.email as actor_email','tenants.name as tenant_name','tenants.slug as tenant_slug',
            ])
            ->map(fn ($row): array => $this->snapshot($row))
            ->filter(fn (array $row): bool => $category === '' || strcasecmp($row['category'], $category) === 0)
            ->filter(fn (array $row): bool => $outcome === '' || strcasecmp($row['outcome'], $outcome) === 0)
            ->filter(function (array $row) use ($search): bool {
                if ($search === '') return true;
                $haystack = mb_strtolower(implode(' ', [$row['event'],$row['actor'],$row['target'],$row['tenant'],$row['metadata_text']]));
                return str_contains($haystack, mb_strtolower($search));
            })
            ->take($take)
            ->values();

        return view('security.audit', compact('events','category','outcome','search','take') + [
            'canonicalOperationId' => self::OPERATION_ID,
        ]);
    }

    private function snapshot(object $row): array
    {
        $metadata = is_array($row->metadata) ? $row->metadata : (json_decode((string) $row->metadata, true) ?: []);
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
        $explicit = trim((string) ($metadata['category'] ?? ''));
        if ($explicit !== '') return $explicit;
        $value = mb_strtolower($event);
        if (str_contains($value, 'session')) return 'Session';
        if (str_contains($value, 'permission') || str_contains($value, 'role') || str_contains($value, 'authoriz')) return 'Authorization';
        if (str_contains($value, 'config') || str_contains($value, 'setting') || str_contains($value, 'provider') || str_contains($value, 'connector')) return 'Configuration';
        if (str_contains($value, 'login') || str_contains($value, 'auth')) return 'Authentication';
        return 'Account';
    }

    private function outcome(string $event, array $metadata): string
    {
        $raw = mb_strtolower(trim((string) ($metadata['outcome'] ?? $metadata['status'] ?? '')));
        if (in_array($raw, ['failed','failure','error'], true)) return 'Failed';
        if (in_array($raw, ['blocked','denied','forbidden'], true)) return 'Blocked';
        if (in_array($raw, ['success','succeeded','ok','completed'], true)) return 'Succeeded';
        $value = mb_strtolower($event);
        if (str_contains($value, 'failed') || str_contains($value, 'error')) return 'Failed';
        if (str_contains($value, 'blocked') || str_contains($value, 'denied') || str_contains($value, 'forbidden')) return 'Blocked';
        return 'Succeeded';
    }

    private function actor(object $row): string
    {
        $name = trim((string) ($row->actor_name ?? ''));
        $email = trim((string) ($row->actor_email ?? ''));
        if ($name !== '' && $email !== '') return $name.' <'.$email.'>';
        return $name !== '' ? $name : ($email !== '' ? $email : 'System / unknown');
    }

    private function target(object $row): string
    {
        $type = trim((string) ($row->subject_type ?? ''));
        $id = trim((string) ($row->subject_id ?? ''));
        if ($type === '' && $id === '') return '—';
        return $id === '' ? $type : ($type === '' ? 'Target' : $type).': '.$id;
    }

    private function metadataText(array $metadata): string
    {
        return collect($metadata)->map(function ($value, $key): string {
            if (is_array($value) || is_object($value)) $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            return $key.'='.(string) $value;
        })->implode(' · ');
    }
}
