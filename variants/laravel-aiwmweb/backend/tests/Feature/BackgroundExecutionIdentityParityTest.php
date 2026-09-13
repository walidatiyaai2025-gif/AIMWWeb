<?php

namespace Tests\Feature;

use App\Jobs\BackgroundExecutionIdentity;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

final class BackgroundExecutionIdentityParityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-AUTO-7E70C7119A';

    protected function tearDown(): void
    {
        if (app()->bound(TenantContext::class)) {
            app(TenantContext::class)->forget();
        }

        parent::tearDown();
    }

    public function test_canonical_background_job_identity_is_bound_to_the_laravel_adapter(): void
    {
        $ledger = json_decode(
            (string) file_get_contents(base_path('../docs/capability-parity-ledger.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($ledger['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('background_job', $operation['kind']);
        $this->assertSame('automation', $operation['domain']);
        $this->assertSame('job:BackgroundExecutionIdentity', $operation['route_screen']);
        $this->assertSame('BackgroundExecutionIdentity', $operation['background_job']);
        $this->assertSame(
            'src/AIWordPressManager.Web/Services/BackgroundExecutionIdentity.cs',
            $operation['current_source'],
        );
        $this->assertSame(self::OPERATION_ID, BackgroundExecutionIdentity::OPERATION_ID);
    }

    public function test_push_nested_dispose_and_repeated_dispose_restore_previous_owner(): void
    {
        $identity = app(BackgroundExecutionIdentity::class);
        $firstOwner = $this->user('first-owner')->getKey();
        $secondOwner = $this->user('second-owner')->getKey();

        $this->assertNull($identity->tryGetOwnerUserId());

        $firstLease = $identity->push($firstOwner);
        $this->assertSame($firstOwner, $identity->tryGetOwnerUserId());

        $secondLease = $identity->push($secondOwner);
        $this->assertSame($secondOwner, $identity->tryGetOwnerUserId());

        $secondLease->dispose();
        $secondLease->dispose();
        $this->assertSame($firstOwner, $identity->tryGetOwnerUserId());

        $firstLease->dispose();
        $firstLease->dispose();
        $this->assertNull($identity->tryGetOwnerUserId());
    }

    public function test_background_identity_never_authenticates_or_grants_privileges(): void
    {
        Auth::logout();
        $identity = app(BackgroundExecutionIdentity::class);
        $owner = $this->user('privilege-boundary');

        $lease = $identity->push($owner->getKey());

        $this->assertSame($owner->getKey(), $identity->tryGetOwnerUserId());
        $this->assertFalse(Auth::check(), 'Ambient background ownership must not become an authenticated session.');
        $this->assertFalse(method_exists($identity, 'roles'));
        $this->assertFalse(method_exists($identity, 'permissions'));

        $lease->dispose();
        $this->assertNull($identity->tryGetOwnerUserId());
    }

    public function test_invalid_owner_fails_closed_without_mutating_current_identity(): void
    {
        $identity = app(BackgroundExecutionIdentity::class);
        $ownerId = $this->user('valid-owner')->getKey();
        $lease = $identity->push($ownerId);

        foreach ([0, -1] as $invalidOwnerId) {
            try {
                $identity->push($invalidOwnerId);
                $this->fail('Invalid background owner identity must fail closed.');
            } catch (InvalidArgumentException) {
                $this->assertSame($ownerId, $identity->tryGetOwnerUserId());
            }
        }

        $lease->dispose();
        $this->assertNull($identity->tryGetOwnerUserId());
    }

    public function test_scoped_lifetime_prevents_owner_leak_between_tenant_job_scopes(): void
    {
        $tenantA = $this->tenant('tenant-a');
        $tenantB = $this->tenant('tenant-b');
        $ownerA = $this->user('tenant-a-owner');

        $tenantContextA = app(TenantContext::class);
        $tenantContextA->activate($tenantA);
        $identityA = app(BackgroundExecutionIdentity::class);
        $leaseA = $identityA->push($ownerA->getKey());

        $this->assertSame($ownerA->getKey(), $identityA->tryGetOwnerUserId());

        // Laravel flushes scoped instances between queue jobs. Simulate that
        // lifecycle explicitly while keeping the previous lease alive to prove
        // Tenant A ambient ownership cannot bleed into Tenant B's job scope.
        app()->forgetScopedInstances();

        $tenantContextB = app(TenantContext::class);
        $tenantContextB->activate($tenantB);
        $identityB = app(BackgroundExecutionIdentity::class);

        $this->assertNotSame($identityA, $identityB);
        $this->assertNull($identityB->tryGetOwnerUserId());

        $leaseA->dispose();
        $this->assertNull($identityB->tryGetOwnerUserId());
    }

    private function tenant(string $slug): Tenant
    {
        return Tenant::query()->create([
            'name' => 'Background Identity '.$slug,
            'slug' => $slug.'-'.Str::lower(Str::random(8)),
        ]);
    }

    private function user(string $name): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => $name.'-'.Str::lower(Str::random(8)).'@example.test',
            'password' => 'not-used-for-background-identity',
        ]);
    }
}
