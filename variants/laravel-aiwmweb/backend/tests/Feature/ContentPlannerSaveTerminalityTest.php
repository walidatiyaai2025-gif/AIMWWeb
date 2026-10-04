<?php

namespace Tests\Feature;

use App\Http\Controllers\ContentPlannerController;
use App\Models\ContentPlannerItem;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ContentPlannerSaveTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-2805622F94';

    public function test_exact_operation_is_pending_source_save_and_runtime_route_is_guarded(): void
    {
        $ledger = json_decode((string) file_get_contents(base_path('../docs/capability-parity-ledger.json')), true, 512, JSON_THROW_ON_ERROR);
        $operation = collect($ledger['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('billing', $operation['domain']);
        $this->assertSame('/content-planner', $operation['route_screen']);
        $this->assertSame('SaveAsync [SaveAsync]', $operation['visible_control']);
        $this->assertTrue((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);

        $route = Route::getRoutes()->match(Request::create('/api/tenants/alpha/content-planner/items/save', 'POST'));
        $this->assertSame(ContentPlannerController::class.'@save', ltrim($route->getActionName(), '\\'));
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame(['tenant'], $route->parameterNames());
    }

    public function test_create_replay_and_update_reconcile_authoritative_persisted_state(): void
    {
        $user = User::factory()->create();
        $tenant = $this->membership($user, 'alpha', ['content.view', 'content.edit'], 'Planner');
        $this->activate($tenant);
        $site = Site::query()->create(['name' => 'Alpha Site', 'url' => 'https://alpha.test', 'status' => 'active']);
        app(TenantContext::class)->forget();

        $payload = [
            'site_id' => $site->id,
            'title' => 'Launch plan',
            'idea' => 'Authoritative content idea',
            'scheduled_at' => '2026-10-10T09:30:00Z',
        ];

        $first = $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'planner-create-1')
            ->postJson('/api/tenants/alpha/content-planner/items/save', $payload)
            ->assertCreated()
            ->assertJsonPath('operation_id', self::OPERATION_ID)
            ->assertJsonPath('mutation', 'created')
            ->assertJsonPath('data.title', 'Launch plan');

        $id = (int) $first->json('data.id');

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'planner-create-1')
            ->postJson('/api/tenants/alpha/content-planner/items/save', $payload)
            ->assertCreated()
            ->assertJsonPath('data.id', $id);

        $this->assertDatabaseCount('content_planner_items', 1);

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'planner-create-1')
            ->postJson('/api/tenants/alpha/content-planner/items/save', [...$payload, 'title' => 'Conflicting replay'])
            ->assertConflict();

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'planner-update-1')
            ->postJson('/api/tenants/alpha/content-planner/items/save', [
                ...$payload,
                'id' => $id,
                'title' => 'Launch plan revised',
            ])
            ->assertOk()
            ->assertJsonPath('mutation', 'updated')
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.title', 'Launch plan revised');

        $this->actingAs($user)
            ->getJson('/api/tenants/alpha/content-planner/items')
            ->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.title', 'Launch plan revised');

        $this->assertDatabaseHas('audit_events', [
            'event' => 'content_planner.created',
            'subject_type' => 'content_planner_item',
            'subject_id' => (string) $id,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'event' => 'content_planner.updated',
            'subject_type' => 'content_planner_item',
            'subject_id' => (string) $id,
        ]);
    }

    public function test_guest_permission_validation_and_ownership_fields_fail_closed(): void
    {
        $this->postJson('/api/tenants/alpha/content-planner/items/save', ['title' => 'Guest'])->assertUnauthorized();

        $limited = User::factory()->create();
        $this->membership($limited, 'limited', ['content.view'], 'Reader');
        $this->actingAs($limited)
            ->postJson('/api/tenants/limited/content-planner/items/save', ['title' => 'Denied'])
            ->assertForbidden();

        $editor = User::factory()->create();
        $this->membership($editor, 'alpha', ['content.view', 'content.edit'], 'Editor');
        $this->actingAs($editor)
            ->postJson('/api/tenants/alpha/content-planner/items/save', [
                'title' => '',
                'tenant_id' => 999,
                'actor_user_id' => 999,
            ])
            ->assertUnprocessable();

        $this->assertDatabaseCount('content_planner_items', 0);
    }

    public function test_foreign_site_and_item_ids_are_rejected_without_mutation(): void
    {
        $alphaUser = User::factory()->create();
        $alpha = $this->membership($alphaUser, 'alpha', ['content.view', 'content.edit'], 'AlphaEditor');

        $betaUser = User::factory()->create();
        $beta = $this->membership($betaUser, 'beta', ['content.view', 'content.edit'], 'BetaEditor');

        $this->activate($beta);
        $betaSite = Site::query()->create(['name' => 'Beta Site', 'url' => 'https://beta.test', 'status' => 'active']);
        $betaItem = ContentPlannerItem::query()->create([
            'site_id' => $betaSite->id,
            'title' => 'Beta private',
            'idea' => 'Do not cross tenants',
            'created_by_user_id' => $betaUser->id,
        ]);
        app(TenantContext::class)->forget();

        $this->actingAs($alphaUser)
            ->withHeader('Idempotency-Key', 'alpha-foreign-site')
            ->postJson('/api/tenants/alpha/content-planner/items/save', [
                'site_id' => $betaSite->id,
                'title' => 'Cross site',
            ])
            ->assertNotFound();

        $this->actingAs($alphaUser)
            ->withHeader('Idempotency-Key', 'alpha-foreign-item')
            ->postJson('/api/tenants/alpha/content-planner/items/save', [
                'id' => $betaItem->id,
                'title' => 'Cross item',
            ])
            ->assertNotFound();

        $this->activate($beta);
        $this->assertSame('Beta private', ContentPlannerItem::query()->findOrFail($betaItem->id)->title);
        app(TenantContext::class)->forget();
    }

    private function membership(User $user, string $slug, array $permissions, string $roleName): Tenant
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $this->activate($tenant);

        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => $roleName.'-'.$slug.'-'.$user->id]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        app(TenantContext::class)->forget();

        return $tenant;
    }

    private function activate(Tenant $tenant): void
    {
        app(TenantContext::class)->activate($tenant);
    }
}
