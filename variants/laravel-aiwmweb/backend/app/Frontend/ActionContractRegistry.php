<?php

namespace App\Frontend;

use App\Models\Site;
use App\Models\Tenant;
use Illuminate\Support\Collection;
use RuntimeException;

final class ActionContractRegistry
{
    /**
     * @param  Collection<int, string>|array<int, string>  $permissions
     * @return array<string, array<string, mixed>>
     */
    public function contracts(Tenant $tenant, Collection|array $permissions, ?Site $site = null): array
    {
        $permissionNames = $permissions instanceof Collection ? $permissions->all() : $permissions;
        $contracts = [];

        foreach (config('frontend_actions', []) as $key => $definition) {
            $availability = ['state' => 'enabled', 'reason' => null];
            $permission = $definition['permission'] ?? null;
            $ownership = $definition['ownership'] ?? 'tenant';

            if ($site !== null && (int) $site->tenant_id !== (int) $tenant->id) {
                $availability = [
                    'state' => 'context_mismatch',
                    'reason' => 'The selected site does not belong to the active tenant.',
                ];
            } elseif ($ownership === 'site' && $site === null) {
                $availability = [
                    'state' => 'site_context_required',
                    'reason' => 'Select a site owned by the active tenant before invoking this action.',
                ];
            } elseif (is_string($permission)
                && ! in_array('*', $permissionNames, true)
                && ! in_array($permission, $permissionNames, true)) {
                $availability = [
                    'state' => 'permission_denied',
                    'reason' => "Missing tenant permission: {$permission}",
                ];
            }

            $endpoint = str_replace('{tenant}', rawurlencode((string) $tenant->slug), (string) $definition['endpoint']);
            if ($site !== null) {
                $endpoint = str_replace('{site}', (string) $site->id, $endpoint);
            }

            $contracts[$key] = [
                'operation_id' => $definition['operation_id'],
                'canonical_kind' => $definition['canonical']['kind'],
                'tenant_id' => (int) $tenant->id,
                'tenant_slug' => (string) $tenant->slug,
                'site_id' => $ownership === 'site' ? $site?->id : null,
                'permission' => $permission,
                'capability' => $definition['capability'] ?? null,
                'endpoint' => $endpoint,
                'method' => $definition['method'],
                'availability' => $availability,
                'fields' => $definition['fields'] ?? [],
                'fixed' => $definition['fixed'] ?? (object) [],
                'reconcile_api_key' => $definition['reconcile_api_key'] ?? null,
            ];
        }

        return $contracts;
    }

    /** @return array<string, array{state:string, reason:?string}> */
    public function capabilityStates(array $contracts): array
    {
        $states = [];
        foreach (config('frontend_actions', []) as $actionKey => $definition) {
            $availability = $contracts[$actionKey]['availability'] ?? ['state' => 'pending_integration', 'reason' => 'Action discovery is unavailable.'];
            if (($availability['state'] ?? 'pending_integration') === 'enabled') {
                continue;
            }
            $state = ($availability['state'] ?? '') === 'permission_denied' ? 'permission_denied' : 'pending_integration';
            foreach ($definition['route_keys'] ?? [] as $routeKey) {
                $states["{$routeKey}.{$actionKey}"] = [
                    'state' => $state,
                    'reason' => $availability['reason'] ?? 'The action is unavailable.',
                ];
            }
        }

        return $states;
    }

    /**
     * Validate every registered operation against the canonical ledger without
     * making production action discovery depend on docs being packaged at runtime.
     *
     * @return array{mapped:int, visible_controls:int, operation_ids:array<int, string>}
     */
    public function auditCanonicalMappings(): array
    {
        $ledgerPath = base_path('../docs/capability-parity-ledger.json');
        if (! is_file($ledgerPath)) {
            throw new RuntimeException("Canonical parity ledger not found: {$ledgerPath}");
        }

        $decoded = json_decode((string) file_get_contents($ledgerPath), true, flags: JSON_THROW_ON_ERROR);
        $operations = $decoded['operations'] ?? null;
        if (! is_array($operations)) {
            throw new RuntimeException('Canonical parity ledger contains no operations array.');
        }

        $allIds = [];
        foreach ($operations as $operation) {
            $id = $operation['operation_id'] ?? null;
            if (! is_string($id) || ! preg_match('/^AIMW-[A-Z]+-[0-9A-F]{10}$/', $id)) {
                throw new RuntimeException('Canonical parity ledger contains a malformed operation_id.');
            }
            if (isset($allIds[$id])) {
                throw new RuntimeException("Canonical parity ledger contains duplicate operation_id {$id}.");
            }
            $allIds[$id] = true;
        }

        $mapped = [];
        $visible = 0;
        foreach (config('frontend_actions', []) as $key => $definition) {
            $operationId = $definition['operation_id'] ?? null;
            $selector = $definition['canonical'] ?? [];
            if (! is_string($operationId) || ! isset($allIds[$operationId])) {
                throw new RuntimeException("Action {$key} references an unknown canonical operation_id.");
            }

            $matches = array_values(array_filter($operations, static function (array $operation) use ($selector): bool {
                foreach ($selector as $field => $value) {
                    if (($operation[$field] ?? null) !== $value) {
                        return false;
                    }
                }

                return true;
            }));

            if (count($matches) !== 1) {
                throw new RuntimeException("Action {$key} canonical selector matched ".count($matches).' ledger rows.');
            }
            if (($matches[0]['operation_id'] ?? null) !== $operationId) {
                throw new RuntimeException("Action {$key} canonical selector resolved a different operation_id.");
            }

            $mapped[] = $operationId;
            if (($matches[0]['kind'] ?? null) === 'visible_control') {
                $visible++;
            }
        }

        if (count(array_unique($mapped)) !== count($mapped)) {
            throw new RuntimeException('Action registry maps multiple actions to the same canonical operation_id.');
        }

        return ['mapped' => count($mapped), 'visible_controls' => $visible, 'operation_ids' => $mapped];
    }
}
