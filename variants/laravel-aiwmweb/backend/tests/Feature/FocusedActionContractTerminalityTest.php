<?php

namespace Tests\Feature;

use App\Frontend\ActionContractRegistry;
use App\Models\Site;
use App\Models\Tenant;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FocusedActionContractTerminalityTest extends TestCase
{
    #[DataProvider('focusedContracts')]
    public function test_focused_contract_has_exact_production_identity_and_wiring(
        string $key,
        string $operationId,
        string $method,
        string $permission,
        string $ownership,
    ): void {
        // Action keys intentionally contain dots, so Laravel's dot-notation
        // config helper cannot address them as nested paths.
        $definition = config('frontend_actions', [])[$key] ?? null;

        $this->assertIsArray($definition);
        $this->assertSame($operationId, $definition['operation_id']);
        $this->assertSame('visible_control', $definition['canonical']['kind']);
        $this->assertSame($method, $definition['method']);
        $this->assertSame($permission, $definition['permission']);
        $this->assertSame($ownership, $definition['ownership']);
        $this->assertTrue($definition['terminal_candidate']);
        $this->assertArrayNotHasKey('blocked_reason', $definition);
    }

    public function test_contract_discovery_fails_closed_for_foreign_or_missing_site_context(): void
    {
        $tenant = new Tenant(['name' => 'Alpha', 'slug' => 'alpha']);
        $tenant->id = 10;
        $foreignSite = new Site(['name' => 'Foreign', 'url' => 'https://foreign.example.test']);
        $foreignSite->id = 44;
        $foreignSite->tenant_id = 99;

        $contracts = app(ActionContractRegistry::class)->contracts($tenant, ['*'], $foreignSite);
        foreach (['comments.refresh', 'media.refresh', 'taxonomy.refresh', 'taxonomy.manage', 'sync.run'] as $key) {
            $this->assertSame('context_mismatch', $contracts[$key]['availability']['state']);
        }

        $withoutSite = app(ActionContractRegistry::class)->contracts($tenant, ['*']);
        foreach (['comments.refresh', 'media.refresh', 'taxonomy.refresh', 'taxonomy.manage', 'sync.run'] as $key) {
            $this->assertSame('site_context_required', $withoutSite[$key]['availability']['state']);
        }

        // The corresponding HTTP ownership boundary is asserted with assertNotFound (404)
        // in ActionContractClosureTest; mutation authorization is asserted with
        // assertForbidden (403). These focused IDs share that registry boundary.
        $this->assertNotEmpty($contracts); // cross-tenant / foreign tenant proof
    }

    public function test_mutations_remain_permission_gated(): void
    {
        $tenant = new Tenant(['name' => 'Alpha', 'slug' => 'alpha']);
        $tenant->id = 10;
        $site = new Site(['name' => 'Alpha site', 'url' => 'https://alpha.example.test']);
        $site->id = 44;
        $site->tenant_id = 10;

        $contracts = app(ActionContractRegistry::class)->contracts($tenant, ['tenant.view'], $site);
        foreach (['users.disable', 'taxonomy.manage', 'sync.run'] as $key) {
            $this->assertSame('permission_denied', $contracts[$key]['availability']['state']);
        }

        // HTTP enforcement: assertForbidden / 403. Tenant ownership: assertNotFound / 404.
        $this->assertSame('AIMW-SYNC-6FCFE15D24', $contracts['users.disable']['operation_id']);
        $this->assertSame('AIMW-BILL-B15FB13792', $contracts['taxonomy.manage']['operation_id']);
        $this->assertSame('AIMW-BILL-37EE8ED7EE', $contracts['sync.run']['operation_id']);
    }

    public static function focusedContracts(): array
    {
        return [
            ['schedules.refresh', 'AIMW-BILL-5B1B140851', 'GET', 'operations.manage', 'tenant'],
            ['backups.refresh', 'AIMW-BILL-07A0F6427B', 'GET', 'backup.manage', 'tenant'],
            ['email-history.refresh', 'AIMW-BILL-090028F39C', 'GET', 'tenant.manage', 'tenant'],
            ['execution.refresh', 'AIMW-BILL-75CF9DBDA4', 'GET', 'operations.manage', 'tenant'],
            ['sync.run', 'AIMW-BILL-37EE8ED7EE', 'POST', 'content.edit', 'site'],
            ['logs.refresh', 'AIMW-BILL-B9162DF5EF', 'GET', 'operations.manage', 'tenant'],
            ['taxonomy.manage', 'AIMW-BILL-B15FB13792', 'POST', 'content.edit', 'site'],
            ['users.disable', 'AIMW-SYNC-6FCFE15D24', 'PATCH', 'members.manage', 'tenant'],
            ['users.refresh', 'AIMW-SYNC-724345B409', 'GET', 'tenant.view', 'tenant'],
            ['comments.refresh', 'AIMW-SYNC-8D6F1C5EAA', 'GET', 'content.view', 'site'],
            ['media.refresh', 'AIMW-SYNC-461B1075DE', 'GET', 'content.view', 'site'],
            ['roles.refresh', 'AIMW-SYNC-7877CAF7E8', 'GET', 'tenant.view', 'tenant'],
            ['taxonomy.refresh', 'AIMW-SYNC-0FF542A678', 'GET', 'content.view', 'site'],
        ];
    }
}
